<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\SurgeryCase;
use App\Models\SurgeryCaseItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CapturedLocation;
use App\Support\ProofImage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Vaka döngüsü: planla → ürünleri kaydet (stoktan düş) → onayla → faturala; iptal.
 * Ürünler hastanenin konsinye lokasyonlarından ya da personelin aracından alınabilir.
 */
class CaseService
{
    public function __construct(
        private readonly StockService $stock,
        private readonly CaseDocument $document,
    ) {}

    /** Vaka planla (takvime gir). Başka personel adına planlamak cases.approve ister. */
    public function plan(User $user, array $data): SurgeryCase
    {
        $hospital = Hospital::findOrFail($data['hospital_id']);
        if (! $hospital->is_active) {
            throw ValidationException::withMessages(['hospital_id' => "{$hospital->name} pasif; vaka planlanamaz."]);
        }
        $this->ensureLocation($hospital, $data['location_id'] ?? null);
        $this->ensureDoctor($hospital, $data['doctor_id'] ?? null);
        $owner = $this->owner($user, $data['user_id'] ?? null);

        return DB::transaction(function () use ($user, $data, $hospital, $owner) {
            $case = SurgeryCase::create([
                'status' => SurgeryCase::PLANNED,
                'hospital_id' => $hospital->id,
                'location_id' => $data['location_id'] ?? null,
                'doctor_id' => $data['doctor_id'] ?? null,
                'user_id' => $owner->id,
                'created_by' => $user->id,
                'scheduled_at' => self::parseTime($data['scheduled_at']),
                'procedure' => $data['procedure'] ?? null,
                'protocol_no' => $data['protocol_no'] ?? null,
                'note' => $data['note'] ?? null,
            ]);
            $case->update(['number' => sprintf('VK-%s-%06d', now()->format('Y'), $case->id)]);

            return $case;
        });
    }

    /** Plan değişikliği: yalnızca kaydı yapılmamış vakada */
    public function update(User $user, SurgeryCase $case, array $data): SurgeryCase
    {
        $this->ensureEditable($user, $case);
        $hospital = $case->hospital;
        if (array_key_exists('hospital_id', $data) && (int) $data['hospital_id'] !== $case->hospital_id) {
            $hospital = Hospital::findOrFail($data['hospital_id']);
            if (! $hospital->is_active) {
                throw ValidationException::withMessages(['hospital_id' => "{$hospital->name} pasif."]);
            }
            // Hastane değişirse eski hastanenin bölüm ve doktoru geçersizdir
            $data += ['location_id' => null, 'doctor_id' => null];
        }
        $this->ensureLocation($hospital, $data['location_id'] ?? $case->location_id);
        $this->ensureDoctor($hospital, $data['doctor_id'] ?? $case->doctor_id);
        if (array_key_exists('user_id', $data)) {
            $data['user_id'] = $this->owner($user, $data['user_id'])->id;
        }

        if (isset($data['scheduled_at'])) {
            $data['scheduled_at'] = self::parseTime($data['scheduled_at']);
        }
        $case->update(collect($data)->only([
            'hospital_id', 'location_id', 'doctor_id', 'user_id', 'scheduled_at', 'procedure', 'protocol_no', 'note',
        ])->all());

        return $case->fresh();
    }

    /**
     * Ameliyat sonrası ürün kaydı. Kullanılan ve açılan ürünler stoktan düşer;
     * kullanılmayanlar yalnızca kayda geçer. Tutanak imzası zorunludur.
     *
     * @return array{case: SurgeryCase, replayed: bool}
     */
    public function record(User $user, SurgeryCase $case, array $data, ?string $idempotencyKey = null): array
    {
        if ($idempotencyKey && $case->batch_uuid && DB::table('idempotency_keys')
            ->where('user_id', $user->id)->where('key', $idempotencyKey)->value('batch_uuid') === $case->batch_uuid) {
            return ['case' => $case, 'replayed' => true];
        }
        $this->ensureEditable($user, $case);

        $hospital = $case->hospital;
        if (! $hospital->is_active) {
            throw ValidationException::withMessages(['hospital_id' => "{$hospital->name} pasif."]);
        }
        [$signerName, $signerTitle, $contactId] = $this->signer($hospital, $data);
        $signature = ProofImage::decode($data['signature'] ?? null, 'signature', ProofImage::SIGNATURE_MAX);
        if (! $signature) {
            throw ValidationException::withMessages(['signature' => 'Kullanım tutanağı için imza gerekli.']);
        }
        $photo = ProofImage::decode($data['photo'] ?? null, 'photo', ProofImage::PHOTO_MAX);
        $lines = $this->resolveItems($user, $hospital, $data['items']);

        DB::transaction(function () use ($user, $case, $data, $lines, $signerName, $signerTitle, $contactId, $idempotencyKey) {
            $case = SurgeryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->status !== SurgeryCase::PLANNED) {
                throw ValidationException::withMessages(['status' => 'Bu vakanın ürünleri zaten kaydedilmiş.']);
            }
            $batch = (string) Str::uuid();
            if ($idempotencyKey) {
                DB::table('idempotency_keys')->insert([
                    'user_id' => $user->id, 'key' => $idempotencyKey, 'batch_uuid' => $batch,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $location = CapturedLocation::attributes($data);

            // Stoktan düş: depo başına bir çağrı (kilit sırası ürün id'sine göre)
            $meta = [
                'batch_uuid' => $batch,
                'surgery_case_id' => $case->id,
                'hospital_id' => $case->hospital_id,
                'reference' => $case->number,
                ...$location,
            ];
            $lines->filter(fn ($l) => $l['billable'])->groupBy(fn ($l) => $l['stock']->warehouse_id)
                ->each(fn (Collection $group, $warehouseId) => $this->stock->consume(
                    $user,
                    $group->first()['stock']->warehouse,
                    $group->map(fn ($l) => [
                        'product_id' => $l['stock']->product_id, 'stock_id' => $l['stock']->id, 'quantity' => $l['quantity'],
                    ])->values()->all(),
                    $meta,
                ));

            $today = Carbon::today();
            foreach ($lines as $l) {
                $s = $l['stock'];
                $case->items()->create([
                    'product_id' => $s->product_id,
                    'warehouse_id' => $s->warehouse_id,
                    'status' => $l['status'],
                    'quantity' => $l['quantity'],
                    'lot_number' => $s->lot_number,
                    'serial_number' => $s->serial_number,
                    'expiry_date' => $s->expiry_date?->toDateString(),
                    'expired_at_use' => $l['billable'] && $s->expiry_date && $s->expiry_date->lt($today),
                ]);
            }

            $case->update([
                'status' => SurgeryCase::RECORDED,
                'performed_at' => now(),
                'recorded_by' => $user->id,
                'device_time' => $data['device_time'] ?? null,
                'protocol_no' => $data['protocol_no'] ?? $case->protocol_no,
                'note' => $data['note'] ?? $case->note,
                'contact_id' => $contactId,
                'signer_name' => $signerName,
                'signer_title' => $signerTitle,
                'batch_uuid' => $batch,
                ...$location,
            ]);
        });

        $case->refresh();
        $paths = ['signature_path' => ProofImage::store("cases/{$case->id}/signature", $signature)];
        if ($photo) {
            $paths['photo_path'] = ProofImage::store("cases/{$case->id}/photo", $photo);
        }
        $case->update($paths);

        // Tutanağın kopyası hastane personeline
        if ($to = $this->recipient($case, $data['receiver_email'] ?? null)) {
            $this->document->email($case, $to);
        }

        return ['case' => $case->fresh(), 'replayed' => false];
    }

    public function approve(User $user, SurgeryCase $case): SurgeryCase
    {
        if ($case->status !== SurgeryCase::RECORDED) {
            throw ValidationException::withMessages(['status' => 'Yalnızca ürünleri kaydedilmiş, onay bekleyen vaka onaylanabilir.']);
        }
        $case->update(['status' => SurgeryCase::APPROVED, 'approved_at' => now(), 'approved_by' => $user->id]);

        return $case;
    }

    /**
     * İptal. Planlı vakayı sahibi iptal edebilir; kaydedilmiş vakayı yalnızca onay yetkilisi.
     * Kaydedilmiş vakada düşülen ürünler alındıkları yere geri konur. Faturalanmış satır varsa iptal edilemez.
     */
    public function cancel(User $user, SurgeryCase $case, string $reason): SurgeryCase
    {
        if ($case->status === SurgeryCase::CANCELLED) {
            throw ValidationException::withMessages(['status' => 'Vaka zaten iptal edilmiş.']);
        }
        if ($case->status !== SurgeryCase::PLANNED && ! $user->hasPermission('cases.approve')) {
            abort(response()->json(['message' => 'Kaydedilmiş vakayı yalnızca yönetici iptal edebilir.', 'required_permission' => 'cases.approve'], 403));
        }
        if ($case->status === SurgeryCase::PLANNED) {
            $this->ensureEditable($user, $case);
        }

        return DB::transaction(function () use ($user, $case, $reason) {
            $case = SurgeryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($case->items()->whereNotNull('invoice_number')->exists()) {
                throw ValidationException::withMessages(['status' => 'Faturalanmış satırı olan vaka iptal edilemez; önce faturayı geri alın.']);
            }

            $billable = $case->items()->whereIn('status', SurgeryCaseItem::BILLABLE)->with('warehouse')->get();
            $meta = ['surgery_case_id' => $case->id, 'hospital_id' => $case->hospital_id, 'reference' => $case->number, 'note' => "İptal: {$reason}"];
            $billable->groupBy('warehouse_id')->each(fn ($group) => $this->stock->putBack(
                $user,
                $group->first()->warehouse,
                $group->map(fn (SurgeryCaseItem $i) => [
                    'product_id' => $i->product_id,
                    'quantity' => (float) $i->quantity,
                    'lot_number' => $i->lot_number,
                    'serial_number' => $i->serial_number,
                    'expiry_date' => $i->expiry_date?->toDateString(),
                ])->values()->all(),
                $meta,
            ));

            $case->update([
                'status' => SurgeryCase::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $user->id,
                'cancel_reason' => $reason,
            ]);

            return $case;
        });
    }

    /** Onaylı vakaların faturalanacak satırlarını faturalandı olarak işaretle */
    public function invoice(User $user, array $itemIds, string $number, string $date): int
    {
        return DB::transaction(function () use ($user, $itemIds, $number, $date) {
            $items = SurgeryCaseItem::query()->whereIn('id', $itemIds)->with('surgeryCase:id,status,number')->lockForUpdate()->get();
            if ($items->count() !== count(array_unique($itemIds))) {
                throw ValidationException::withMessages(['item_ids' => 'Seçilen satırlardan bazıları bulunamadı.']);
            }
            foreach ($items as $i) {
                if (! $i->billable) {
                    throw ValidationException::withMessages(['item_ids' => 'Kullanılmayan ürün faturalanamaz.']);
                }
                if ($i->surgeryCase->status !== SurgeryCase::APPROVED) {
                    throw ValidationException::withMessages(['item_ids' => "{$i->surgeryCase->number} onaylanmamış; önce vakayı onaylayın."]);
                }
                if ($i->invoice_number) {
                    throw ValidationException::withMessages(['item_ids' => "{$i->surgeryCase->number} içindeki bir satır zaten {$i->invoice_number} ile faturalanmış."]);
                }
            }

            return SurgeryCaseItem::query()->whereIn('id', $items->pluck('id'))->update([
                'invoice_number' => $number,
                'invoice_date' => $date,
                'invoiced_at' => now(),
                'invoiced_by' => $user->id,
            ]);
        });
    }

    /** Yanlış işaretlemeyi geri al */
    public function uninvoice(array $itemIds): int
    {
        return SurgeryCaseItem::query()->whereIn('id', $itemIds)->whereNotNull('invoice_number')->update([
            'invoice_number' => null, 'invoice_date' => null, 'invoiced_at' => null, 'invoiced_by' => null,
        ]);
    }

    /** Saat dilimi belirtilmemiş saat Türkiye saatidir ("2026-11-10 09:00"); kayıt UTC tutulur */
    public static function parseTime(string $value): Carbon
    {
        return Carbon::parse($value, config('app.display_timezone'))->utc();
    }

    /** Vakanın sahibi (ya da onay yetkilisi) mi, vaka hâlâ planlı mı */
    private function ensureEditable(User $user, SurgeryCase $case): void
    {
        if ($case->status !== SurgeryCase::PLANNED) {
            throw ValidationException::withMessages(['status' => match ($case->status) {
                SurgeryCase::CANCELLED => 'Vaka iptal edilmiş.',
                default => 'Bu vakanın ürünleri zaten kaydedilmiş.',
            }]);
        }
        if ($case->user_id !== $user->id && ! $user->hasPermission('cases.approve')) {
            abort(response()->json(['message' => 'Bu vaka başka bir personele ait.'], 403));
        }
    }

    private function owner(User $user, ?int $userId): User
    {
        if (! $userId || $userId === $user->id) {
            return $user;
        }
        if (! $user->hasPermission('cases.approve')) {
            abort(response()->json(['message' => 'Başka personel adına vaka planlama yetkiniz yok.', 'required_permission' => 'cases.approve'], 403));
        }
        $owner = User::where('is_active', true)->find($userId);
        if (! $owner || ! $owner->hasPermission('cases.create')) {
            throw ValidationException::withMessages(['user_id' => 'Seçilen kişi vaka yetkisi olan aktif bir personel değil.']);
        }

        return $owner;
    }

    private function ensureLocation(Hospital $hospital, ?int $locationId): void
    {
        if (! $locationId) {
            return;
        }
        $loc = Warehouse::find($locationId);
        if (! $loc || ! $loc->isConsignment() || $loc->hospital_id !== $hospital->id) {
            throw ValidationException::withMessages(['location_id' => 'Seçilen bölüm bu hastaneye ait değil.']);
        }
    }

    private function ensureDoctor(Hospital $hospital, ?int $doctorId): void
    {
        if (! $doctorId) {
            return;
        }
        $doctor = Doctor::find($doctorId);
        if (! $doctor || $doctor->hospital_id !== $hospital->id) {
            throw ValidationException::withMessages(['doctor_id' => 'Seçilen doktor bu hastaneye ait değil.']);
        }
    }

    /** Tutanağı imzalayan: kayıtlı hastane personeli ya da elle yazılan ad */
    private function signer(Hospital $hospital, array $data): array
    {
        if (! empty($data['contact_id'])) {
            $c = HospitalContact::where('hospital_id', $hospital->id)->find($data['contact_id']);
            if (! $c) {
                throw ValidationException::withMessages(['contact_id' => 'Seçilen kişi bu hastaneye ait değil.']);
            }

            return [$c->name, $c->title, $c->id];
        }
        $name = trim((string) ($data['signer_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['contact_id' => 'Tutanağı imzalayan kişiyi seçin ya da adını yazın.']);
        }

        return [$name, $data['signer_title'] ?? null, null];
    }

    /**
     * Satırlar stok satırıyla (stock_id) gelir. Stok bu hastanenin bir lokasyonunda ya da
     * personelin kullanabildiği bir araçta olmalı. Aynı satırdan birden fazla durum seçilebilir
     * (ör. 2 kullanıldı, 1 kullanılmadı); toplam, satırdaki miktarı aşamaz.
     */
    private function resolveItems(User $user, Hospital $hospital, array $items): Collection
    {
        $stocks = Stock::query()->with(['product:id,name,tracking,unit', 'warehouse'])
            ->whereIn('id', array_column($items, 'stock_id'))->get()->keyBy('id');
        $vehicles = DeliveryService::allowedVehicles($user)->pluck('id')->all();

        $lines = collect($items)->map(function ($item) use ($stocks, $hospital, $vehicles) {
            $s = $stocks->get($item['stock_id']);
            $here = $s && $s->warehouse && (
                ($s->warehouse->isConsignment() && $s->warehouse->hospital_id === $hospital->id)
                || in_array($s->warehouse_id, $vehicles, true)
            );
            if (! $here) {
                throw ValidationException::withMessages(['items' => 'Seçilen ürünlerden biri bu hastanede ya da aracınızda değil. Listeyi yenileyin.']);
            }

            return [
                'stock' => $s,
                'quantity' => round((float) $item['quantity'], 2),
                'status' => $item['status'],
                'billable' => in_array($item['status'], SurgeryCaseItem::BILLABLE, true),
            ];
        });

        $lines->groupBy(fn ($l) => $l['stock']->id)->each(function (Collection $group) {
            $s = $group->first()['stock'];
            if ($group->sum('quantity') > (float) $s->quantity + 0.0001) {
                throw ValidationException::withMessages([
                    'items' => "{$s->product->name}".($s->serial_number ? " SN {$s->serial_number}" : ($s->lot_number ? " LOT {$s->lot_number}" : ''))
                        .": seçilen toplam miktar mevcut ".rtrim(rtrim(number_format((float) $s->quantity, 2, '.', ''), '0'), '.')." {$s->product->unit} miktarını aşıyor.",
                ]);
            }
        });

        return $lines->values();
    }

    private function recipient(SurgeryCase $case, ?string $typed): ?string
    {
        $typed = $typed ? mb_strtolower(trim($typed)) : null;
        $contact = $case->contact_id ? HospitalContact::find($case->contact_id) : null;
        if ($typed && $contact && ! $contact->email) {
            $contact->update(['email' => $typed]);
        }

        return $typed ?: $contact?->email;
    }
}
