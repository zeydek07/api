<?php

namespace App\Services;

use App\Models\Delivery;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\CapturedLocation;
use App\Support\ProofImage;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Konsinye teslimat: araçtan hastaneye bırakma ve hastaneden geri alma.
 * Stok hareketleri StockService ile yapılır; bu sınıf belgeyi, kanıtı ve kuralları yönetir.
 */
class DeliveryService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly DeliveryDocument $document,
    ) {}

    /**
     * @param  array{type:string, hospital_id:int, location_id:int, vehicle_id:int, contact_id?:?int,
     *               receiver_name?:?string, receiver_title?:?string, items:array, signature?:?string,
     *               photo?:?string, note?:?string, latitude?:?float, longitude?:?float, device_time?:?string}  $data
     * @return array{delivery: Delivery, replayed: bool}
     */
    public function create(User $user, array $data, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && ($existing = $this->findByKey($user, $idempotencyKey))) {
            return ['delivery' => $existing, 'replayed' => true];
        }

        $isReturn = $data['type'] === Delivery::TYPE_RETURN;
        $hospital = Hospital::findOrFail($data['hospital_id']);
        $location = Warehouse::findOrFail($data['location_id']);
        $vehicle = Warehouse::findOrFail($data['vehicle_id']);

        $this->ensureValidPlaces($user, $hospital, $location, $vehicle);
        [$receiverName, $receiverTitle, $contactId] = $this->receiver($hospital, $data);

        // Kanıt görsellerini önceden çöz (geçersizse hiçbir şey kaydedilmeden hata dönsün)
        $signature = ProofImage::decode($data['signature'] ?? null, 'signature', ProofImage::SIGNATURE_MAX);
        $photo = ProofImage::decode($data['photo'] ?? null, 'photo', ProofImage::PHOTO_MAX);
        if (! $isReturn && ! $signature) {
            throw ValidationException::withMessages(['signature' => 'Teslim alan kişinin imzası gerekli.']);
        }

        $from = $isReturn ? $location : $vehicle;
        $to = $isReturn ? $vehicle : $location;
        $items = $this->resolveItems($from, $data['items']);

        try {
            $delivery = DB::transaction(function () use ($user, $data, $isReturn, $hospital, $location, $vehicle, $from, $to, $items, $receiverName, $receiverTitle, $contactId, $idempotencyKey) {
                $batch = (string) Str::uuid();
                if ($idempotencyKey) {
                    DB::table('idempotency_keys')->insert([
                        'user_id' => $user->id, 'key' => $idempotencyKey, 'batch_uuid' => $batch,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $delivery = Delivery::create([
                    'type' => $data['type'],
                    'hospital_id' => $hospital->id,
                    'location_id' => $location->id,
                    'vehicle_id' => $vehicle->id,
                    'contact_id' => $contactId,
                    'receiver_name' => $receiverName,
                    'receiver_title' => $receiverTitle,
                    'user_id' => $user->id,
                    'delivered_at' => now(),
                    'device_time' => $data['device_time'] ?? null,
                    ...CapturedLocation::attributes($data),
                    'note' => $data['note'] ?? null,
                    'batch_uuid' => $batch,
                ]);
                $delivery->update(['number' => sprintf('%s-%s-%06d', $isReturn ? 'GA' : 'TS', now()->format('Y'), $delivery->id)]);

                $this->stock->transfer(
                    $user, $from, $to, $items,
                    [
                        'batch_uuid' => $batch,
                        'delivery_id' => $delivery->id,
                        'hospital_id' => $hospital->id,
                        'reference' => $delivery->number,
                        'note' => $data['note'] ?? null,
                        ...CapturedLocation::attributes($data),
                    ],
                    $isReturn ? StockMovement::TYPE_RETURN_OUT : StockMovement::TYPE_CONSIGN_OUT,
                    $isReturn ? StockMovement::TYPE_RETURN_IN : StockMovement::TYPE_CONSIGN_IN,
                    // SKT'si geçmiş ürün hastaneye bırakılamaz; geri alınabilir
                    allowExpired: $isReturn,
                );

                return $delivery;
            });
        } catch (UniqueConstraintViolationException $e) {
            if ($idempotencyKey && ($existing = $this->findByKey($user, $idempotencyKey))) {
                return ['delivery' => $existing, 'replayed' => true];
            }
            throw $e;
        }

        // Kanıt dosyaları: veritabanı kaydı başarılı olduktan sonra yazılır
        $paths = [];
        if ($signature) {
            $paths['signature_path'] = ProofImage::store("deliveries/{$delivery->id}/signature", $signature);
        }
        if ($photo) {
            $paths['photo_path'] = ProofImage::store("deliveries/{$delivery->id}/photo", $photo);
        }
        if ($paths) {
            $delivery->update($paths);
        }

        // Belge kopyası hastane personeline (e-posta yoksa gönderilmez; panelden sonra gönderilebilir)
        if ($to = $this->recipient($delivery, $data['receiver_email'] ?? null)) {
            $this->document->email($delivery, $to);
        }

        return ['delivery' => $delivery, 'replayed' => false];
    }

    /**
     * İptal: ürünler ters yönde geri taşınır (ayrı hareketler olarak), belge "iptal" olur.
     * Ürünler artık bulundukları yerde değilse (ör. vakada kullanıldı) iptal edilemez.
     */
    public function cancel(User $user, Delivery $delivery, string $reason): Delivery
    {
        if ($delivery->status !== Delivery::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['status' => 'Bu belge zaten iptal edilmiş.']);
        }

        return DB::transaction(function () use ($user, $delivery, $reason) {
            $delivery = Delivery::whereKey($delivery->id)->lockForUpdate()->firstOrFail();
            $isReturn = $delivery->type === Delivery::TYPE_RETURN;
            $where = $isReturn ? $delivery->vehicle : $delivery->location;   // ürünlerin şu an olması gereken yer
            $back = $isReturn ? $delivery->location : $delivery->vehicle;

            $items = $delivery->lines()->get()->map(fn (StockMovement $m) => [
                'product_id' => $m->product_id,
                'quantity' => (float) $m->quantity,
                'serial_number' => $m->serial_number,
                'lot_number' => $m->serial_number ? null : $m->lot_number,
                'expiry_date' => $m->serial_number ? null : $m->expiry_date?->toDateString(),
            ])->all();

            try {
                $this->stock->transfer($user, $where, $back, $items, [
                    'delivery_id' => $delivery->id,
                    'hospital_id' => $delivery->hospital_id,
                    'reference' => $delivery->number,
                    'note' => "İptal: {$reason}",
                ]);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages([
                    'items' => 'Belgedeki ürünlerin bir kısmı artık '.$where->name.' içinde değil; iptal edilemez. ('.collect($e->errors())->flatten()->first().')',
                ]);
            }

            $delivery->update([
                'status' => Delivery::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            return $delivery;
        });
    }

    /**
     * Belgenin gideceği adres: elle yazılan e-posta, yoksa seçilen kişinin kayıtlı e-postası.
     * Kayıtlı kişinin e-postası yoksa yazılan adres kişiye de kaydedilir (bir dahaki sefere hazır gelir).
     */
    private function recipient(Delivery $delivery, ?string $typed): ?string
    {
        $typed = $typed ? mb_strtolower(trim($typed)) : null;
        $contact = $delivery->contact_id ? HospitalContact::find($delivery->contact_id) : null;
        if ($typed && $contact && ! $contact->email) {
            $contact->update(['email' => $typed]);
        }

        return $typed ?: $contact?->email;
    }

    /** Kullanıcının ürün taşıyabileceği araç/depolar (firma depoları) */
    public static function allowedVehicles(User $user)
    {
        $q = Warehouse::query()->internal()->where('is_active', true);

        return $user->isSuperAdmin() ? $q : $q->whereHas('users', fn ($u) => $u->whereKey($user->id));
    }

    private function ensureValidPlaces(User $user, Hospital $hospital, Warehouse $location, Warehouse $vehicle): void
    {
        if (! $hospital->is_active) {
            throw ValidationException::withMessages(['hospital_id' => "{$hospital->name} pasif; teslimat yapılamaz."]);
        }
        if (! $location->isConsignment() || $location->hospital_id !== $hospital->id) {
            throw ValidationException::withMessages(['location_id' => 'Seçilen lokasyon bu hastaneye ait değil.']);
        }
        if (! $location->is_active) {
            throw ValidationException::withMessages(['location_id' => "{$location->name} pasif."]);
        }
        if (! self::allowedVehicles($user)->whereKey($vehicle->id)->exists()) {
            throw ValidationException::withMessages(['vehicle_id' => 'Bu araç/depo ile işlem yapma yetkiniz yok.']);
        }
    }

    /** Teslim alan: kayıtlı kişi seçildiyse onun bilgileri, değilse girilen ad */
    private function receiver(Hospital $hospital, array $data): array
    {
        if (! empty($data['contact_id'])) {
            $c = HospitalContact::where('hospital_id', $hospital->id)->find($data['contact_id']);
            if (! $c) {
                throw ValidationException::withMessages(['contact_id' => 'Seçilen kişi bu hastaneye ait değil.']);
            }

            return [$c->name, $c->title, $c->id];
        }

        $name = trim((string) ($data['receiver_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['contact_id' => 'Teslim alan kişiyi seçin ya da adını yazın.']);
        }

        return [$name, $data['receiver_title'] ?? null, null];
    }

    /** Satırlar stok satırı (stock_id) ile gelir; kaynak depoda olduklarını doğrula */
    private function resolveItems(Warehouse $from, array $items): array
    {
        $ids = array_column($items, 'stock_id');
        if (count($ids) !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['items' => 'Aynı ürün satırı birden fazla kez eklenmiş.']);
        }
        $stocks = Stock::query()->with('product:id,name')->whereIn('id', $ids)->get()->keyBy('id');

        return array_map(function ($item) use ($stocks, $from) {
            $s = $stocks->get($item['stock_id']);
            if (! $s || $s->warehouse_id !== $from->id) {
                throw ValidationException::withMessages(['items' => "Seçilen ürünlerden biri {$from->name} içinde değil. Listeyi yenileyin."]);
            }

            return ['product_id' => $s->product_id, 'stock_id' => $s->id, 'quantity' => (float) $item['quantity']];
        }, $items);
    }

    private function findByKey(User $user, string $key): ?Delivery
    {
        $batch = DB::table('idempotency_keys')->where('user_id', $user->id)->where('key', $key)->value('batch_uuid');

        return $batch ? Delivery::where('batch_uuid', $batch)->first() : null;
    }
}
