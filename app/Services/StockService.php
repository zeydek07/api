<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stok miktarını değiştiren tek yer.
 *
 * Stok "depo + ürün + lot/seri + SKT" satırları halinde tutulur. Her değişiklik bir
 * transaction içinde, ilgili satırlar kilitlenerek yapılır ve bir StockMovement bırakır.
 *
 * Çıkışlarda hangi lot kullanılacağı belirtilmezse SKT'si en yakın olan önce kullanılır
 * (FEFO). SKT'si geçmiş stok otomatik seçilmez ve hastaneye/vakaya verilemez.
 *
 * Bir satırı hedeflemek için item'da şunlardan biri verilebilir:
 *   stock_id | serial_number | lot_number (+ expiry_date)
 */
class StockService
{
    /** Depoya ürün girişi (satın alma, iade vb.). Lot/seri ürünün takip türüne göre zorunludur. */
    public function receive(User $user, Warehouse $warehouse, array $items, array $meta = []): Collection
    {
        $this->ensureWarehouseActive($warehouse);

        return DB::transaction(function () use ($user, $warehouse, $items, $meta) {
            $batch = (string) Str::uuid();
            $products = $this->loadActiveProducts($items);

            $movements = collect();
            foreach ($this->sortItems($items) as $i => $item) {
                $product = $products[$item['product_id']];
                $attrs = $this->incomingAttributes($product, $item, $i);
                $movements->push($this->put($batch, StockMovement::TYPE_IN, $user, $warehouse->id, $product, abs((float) $item['quantity']), $attrs, $item, $meta));
            }

            return $movements;
        });
    }

    /**
     * Sahada kullanım: personel stoktan ürün düşer.
     * $idempotencyKey verilirse aynı anahtarla gelen ikinci istek yeni düşüm yapmaz,
     * ilk isteğin sonucunu döndürür.
     */
    public function recordUsage(User $user, Warehouse $warehouse, array $items, array $meta = [], ?string $idempotencyKey = null): array
    {
        $this->ensureWarehouseActive($warehouse);

        if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($user, $idempotencyKey))) {
            return ['batch_uuid' => $existing->first()?->batch_uuid, 'movements' => $existing, 'replayed' => true];
        }

        try {
            return DB::transaction(function () use ($user, $warehouse, $items, $meta, $idempotencyKey) {
                $batch = (string) Str::uuid();

                if ($idempotencyKey) {
                    DB::table('idempotency_keys')->insert([
                        'user_id' => $user->id, 'key' => $idempotencyKey, 'batch_uuid' => $batch,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }

                $products = $this->loadActiveProducts($items);
                $movements = collect();
                foreach ($this->sortItems($items) as $item) {
                    $movements->push(...$this->take($batch, StockMovement::TYPE_USAGE, $user, $warehouse->id, $products[$item['product_id']], $item, $meta, allowExpired: false));
                }

                return ['batch_uuid' => $batch, 'movements' => $movements, 'replayed' => false];
            });
        } catch (UniqueConstraintViolationException $e) {
            // Aynı anahtarla eşzamanlı iki istek geldi; diğeri kazandı.
            if ($idempotencyKey && ($existing = $this->findByIdempotencyKey($user, $idempotencyKey))) {
                return ['batch_uuid' => $existing->first()?->batch_uuid, 'movements' => $existing, 'replayed' => true];
            }
            throw $e;
        }
    }

    /**
     * Depolar arası transfer (ör. merkez depodan personelin aracına).
     * Lot/seri korunur: çıkan lot hedef depoya aynı lot olarak girer.
     * $allowExpired=false ise SKT'si geçmiş stok hiçbir şekilde taşınamaz (hastaneye teslim gibi).
     */
    public function transfer(
        User $user,
        Warehouse $from,
        Warehouse $to,
        array $items,
        array $meta = [],
        string $outType = StockMovement::TYPE_TRANSFER_OUT,
        string $inType = StockMovement::TYPE_TRANSFER_IN,
        bool $allowExpired = true,
    ): Collection {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Kaynak ve hedef depo aynı olamaz.']);
        }
        $this->ensureWarehouseActive($from);
        $this->ensureWarehouseActive($to);

        $run = function () use ($user, $from, $to, $items, $meta, $outType, $inType, $allowExpired) {
            $batch = $meta['batch_uuid'] ?? (string) Str::uuid();
            $products = $this->loadActiveProducts($items);

            $movements = collect();
            foreach ($this->sortItems($items) as $item) {
                $product = $products[$item['product_id']];
                foreach ($this->take($batch, $outType, $user, $from->id, $product, $item, $meta, $allowExpired) as $out) {
                    $movements->push($out);
                    $movements->push($this->put($batch, $inType, $user, $to->id, $product, abs((float) $out->quantity), [
                        'lot_number' => $out->lot_number,
                        'serial_number' => $out->serial_number,
                        'expiry_date' => $out->expiry_date?->toDateString(),
                    ], $item, $meta));
                }
            }

            return $movements;
        };

        // Dışarıda zaten bir transaction varsa (ör. teslimat kaydı) onun içinde çalış
        return DB::transactionLevel() > 0 ? $run() : DB::transaction($run);
    }

    /**
     * Sayım sonucu: bir lot/seri satırındaki miktarı sayılan değere eşitler, farkı hareket olarak yazar.
     * $target ile satır seçilir (stock_id, serial_number veya lot_number + expiry_date).
     * Hedef verilmezse ve depoda o ürünün tek satırı varsa o kullanılır.
     */
    public function adjust(User $user, Warehouse $warehouse, int $productId, float $countedQuantity, array $meta = [], array $target = []): ?StockMovement
    {
        return DB::transaction(function () use ($user, $warehouse, $productId, $countedQuantity, $meta, $target) {
            $product = Product::withTrashed()->findOrFail($productId);
            $row = $this->resolveAdjustRow($warehouse, $product, $target);

            if ($product->tracking === Product::TRACKING_SERIAL && $countedQuantity > 1) {
                throw ValidationException::withMessages(['counted_quantity' => 'Seri numaralı üründe sayılan miktar 0 veya 1 olabilir.']);
            }

            $delta = round($countedQuantity - (float) $row->quantity, 2);
            if ($delta == 0.0) {
                return null;
            }

            return $this->write((string) Str::uuid(), StockMovement::TYPE_ADJUSTMENT, $user, $row, $delta, [], $meta);
        });
    }

    // ---------------------------------------------------------------- iç işleyiş

    /**
     * Depodan ürün çıkarır. Belirli bir satır hedeflenmemişse SKT'si en yakın lotlardan
     * başlayarak gerektiği kadar satırdan düşer; her satır için bir hareket döner.
     *
     * @return StockMovement[]
     */
    private function take(string $batch, string $type, User $user, int $warehouseId, Product $product, array $item, array $meta, bool $allowExpired): array
    {
        $wanted = round(abs((float) $item['quantity']), 2);
        $targeted = $this->isTargeted($item);
        $rows = $this->rowsToTake($warehouseId, $product->id, $item);

        $today = Carbon::today();
        $usable = $rows->filter(fn (Stock $s) => ! $s->expiry_date || $s->expiry_date->gte($today) || ($allowExpired && $targeted));
        $available = round((float) $usable->sum('quantity'), 2);

        if ($available < $wanted) {
            $expired = round((float) $rows->diff($usable)->sum('quantity'), 2);
            $what = $this->describeTarget($item);
            throw ValidationException::withMessages([
                'items' => sprintf(
                    '%s%s için yeterli stok yok. Kullanılabilir: %s %s, istenen: %s.%s',
                    $product->name,
                    $what ? " ({$what})" : '',
                    $this->fmt($available),
                    $product->unit,
                    $this->fmt($wanted),
                    $expired > 0 ? " SKT'si geçmiş {$this->fmt($expired)} {$product->unit} kullanılamaz." : '',
                ),
            ]);
        }

        $movements = [];
        $remaining = $wanted;
        foreach ($usable as $row) {
            $part = min($remaining, (float) $row->quantity);
            if ($part <= 0) {
                continue;
            }
            $movements[] = $this->write($batch, $type, $user, $row, -$part, $item, $meta);
            $remaining = round($remaining - $part, 2);
            if ($remaining <= 0) {
                break;
            }
        }

        return $movements;
    }

    /** Depoya ürün koyar (verilen lot/seri/SKT satırına). */
    private function put(string $batch, string $type, User $user, int $warehouseId, Product $product, float $quantity, array $attrs, array $item, array $meta): StockMovement
    {
        $row = $this->lockRow($warehouseId, $product->id, $attrs);

        if ($row->serial_number !== null && (float) $row->quantity + $quantity > 1) {
            throw ValidationException::withMessages([
                'items' => "{$product->name} için {$row->serial_number} seri numarası bu depoda zaten var.",
            ]);
        }

        return $this->write($batch, $type, $user, $row, $quantity, $item, $meta);
    }

    /** Kilitli satırın miktarını değiştirir ve hareketi kaydeder. */
    private function write(string $batch, string $type, User $user, Stock $row, float $delta, array $item, array $meta): StockMovement
    {
        $newQuantity = round((float) $row->quantity + $delta, 2);
        if ($newQuantity < 0) {
            throw ValidationException::withMessages(['items' => 'Stok eksiye düşemez.']);
        }
        $row->update(['quantity' => $newQuantity]);

        // "Kalan": bu depodaki o ürünün toplamı (tüm lotlar)
        $balance = (float) Stock::query()
            ->where('warehouse_id', $row->warehouse_id)
            ->where('product_id', $row->product_id)
            ->sum('quantity');

        return StockMovement::create([
            'batch_uuid' => $batch,
            'type' => $type,
            'product_id' => $row->product_id,
            'warehouse_id' => $row->warehouse_id,
            'user_id' => $user->id,
            'hospital_id' => $meta['hospital_id'] ?? null,
            'delivery_id' => $meta['delivery_id'] ?? null,
            'quantity' => $delta,
            'balance_after' => round($balance, 2),
            'lot_number' => $row->lot_number,
            'serial_number' => $row->serial_number,
            'expiry_date' => $row->expiry_date?->toDateString(),
            'reference' => $meta['reference'] ?? null,
            'note' => $item['note'] ?? $meta['note'] ?? null,
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
        ]);
    }

    /** Çıkış için aday satırlar: kilitli, SKT'si en yakın önce, SKT'siz en sona. */
    private function rowsToTake(int $warehouseId, int $productId, array $item): Collection
    {
        $query = Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('quantity', '>', 0);

        if (! empty($item['stock_id'])) {
            $query->whereKey($item['stock_id']);
        } elseif ($serial = Stock::clean($item['serial_number'] ?? null)) {
            $query->where('serial_number', $serial);
        } elseif ($lot = Stock::clean($item['lot_number'] ?? null)) {
            $query->where('lot_number', $lot);
            if ($expiry = Stock::clean($item['expiry_date'] ?? null)) {
                $query->whereDate('expiry_date', $expiry);
            }
        }

        return $query
            ->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** Satırı yoksa oluşturur ve transaction sonuna kadar kilitler. */
    private function lockRow(int $warehouseId, int $productId, array $attrs): Stock
    {
        $lot = Stock::clean($attrs['lot_number'] ?? null);
        $serial = Stock::clean($attrs['serial_number'] ?? null);
        $expiry = Stock::clean($attrs['expiry_date'] ?? null);
        $key = Stock::keyFor($lot, $serial, $expiry);

        Stock::query()->insertOrIgnore([
            'warehouse_id' => $warehouseId, 'product_id' => $productId,
            'lot_number' => $lot, 'serial_number' => $serial, 'expiry_date' => $expiry,
            'lot_key' => $key, 'quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->where('lot_key', $key)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Girişte lot/seri/SKT'yi ürünün takip türüne göre doğrular ve normalleştirir. */
    private function incomingAttributes(Product $product, array $item, int $index): array
    {
        $lot = Stock::clean($item['lot_number'] ?? null);
        $serial = Stock::clean($item['serial_number'] ?? null);
        $expiry = Stock::clean($item['expiry_date'] ?? null);
        $expiry = $expiry ? Carbon::parse($expiry)->toDateString() : null;
        $field = "items.{$index}";

        if ($expiry && Carbon::parse($expiry)->lt(Carbon::today())) {
            throw ValidationException::withMessages(["{$field}.expiry_date" => "{$product->name}: SKT'si geçmiş ürün girişi yapılamaz."]);
        }

        switch ($product->tracking) {
            case Product::TRACKING_SERIAL:
                if (! $serial) {
                    throw ValidationException::withMessages(["{$field}.serial_number" => "{$product->name} seri numaralı bir ürün; seri numarası girin."]);
                }
                if ((float) $item['quantity'] !== 1.0) {
                    throw ValidationException::withMessages(["{$field}.quantity" => "{$product->name}: seri numaralı üründe her satır 1 adettir."]);
                }
                $where = Stock::query()->with('warehouse:id,name')
                    ->where('product_id', $product->id)->where('serial_number', $serial)->where('quantity', '>', 0)->first();
                if ($where) {
                    throw ValidationException::withMessages(["{$field}.serial_number" => "{$product->name} {$serial} seri numarası zaten stokta ({$where->warehouse?->name})."]);
                }

                return ['lot_number' => $lot, 'serial_number' => $serial, 'expiry_date' => $expiry];

            case Product::TRACKING_LOT:
                if (! $lot) {
                    throw ValidationException::withMessages(["{$field}.lot_number" => "{$product->name} lot numaralı bir ürün; lot numarası girin."]);
                }

                return ['lot_number' => $lot, 'serial_number' => null, 'expiry_date' => $expiry];

            default: // takipsiz
                return ['lot_number' => null, 'serial_number' => null, 'expiry_date' => null];
        }
    }

    private function resolveAdjustRow(Warehouse $warehouse, Product $product, array $target): Stock
    {
        if (! empty($target['stock_id'])) {
            $row = Stock::query()->whereKey($target['stock_id'])->lockForUpdate()->first();
            if (! $row || $row->warehouse_id !== $warehouse->id || $row->product_id !== $product->id) {
                throw ValidationException::withMessages(['stock_id' => 'Seçilen stok satırı bu depo ve ürüne ait değil.']);
            }

            return $row;
        }

        $hasTarget = Stock::clean($target['lot_number'] ?? null) || Stock::clean($target['serial_number'] ?? null);
        if ($hasTarget || $product->tracking === Product::TRACKING_NONE) {
            // Sayımda sistemde olmayan bir lot bulunmuş olabilir; satır yoksa açılır
            return $this->lockRow($warehouse->id, $product->id, $product->tracking === Product::TRACKING_NONE ? [] : $target);
        }

        $rows = Stock::query()
            ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->where('quantity', '!=', 0)
            ->lockForUpdate()->get();

        if ($rows->count() === 1) {
            return $rows->first();
        }

        throw ValidationException::withMessages([
            'lot_number' => $rows->isEmpty()
                ? "{$product->name} bu depoda yok; sayımda bulunduysa lot/seri numarasını girin."
                : "{$product->name} bu depoda {$rows->count()} farklı lot/seri olarak var; hangisini saydığınızı seçin.",
        ]);
    }

    private function isTargeted(array $item): bool
    {
        return ! empty($item['stock_id']) || Stock::clean($item['serial_number'] ?? null) || Stock::clean($item['lot_number'] ?? null);
    }

    private function describeTarget(array $item): string
    {
        if ($serial = Stock::clean($item['serial_number'] ?? null)) {
            return "SN {$serial}";
        }

        return ($lot = Stock::clean($item['lot_number'] ?? null)) ? "LOT {$lot}" : '';
    }

    private function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    /** Kilitleri hep aynı sırada almak, eşzamanlı işlemlerde kilitlenmeyi (deadlock) önler. */
    private function sortItems(array $items): array
    {
        uasort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return $items;
    }

    /** @return array<int, Product> */
    private function loadActiveProducts(array $items): array
    {
        $ids = array_unique(array_column($items, 'product_id'));
        $products = Product::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        $inactive = $products->filter(fn (Product $p) => ! $p->is_active || $p->trashed())->pluck('name');
        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Pasif ürünlerle işlem yapılamaz: '.$inactive->join(', ')]);
        }

        return $products->all();
    }

    private function ensureWarehouseActive(Warehouse $warehouse): void
    {
        if (! $warehouse->is_active) {
            throw ValidationException::withMessages(['warehouse_id' => "{$warehouse->name} deposu pasif."]);
        }
    }

    private function findByIdempotencyKey(User $user, string $key): ?Collection
    {
        $batch = DB::table('idempotency_keys')
            ->where('user_id', $user->id)
            ->where('key', $key)
            ->value('batch_uuid');

        return $batch
            ? StockMovement::where('batch_uuid', $batch)->with(['product:id,name,code,unit', 'hospital:id,name'])->get()
            : null;
    }
}
