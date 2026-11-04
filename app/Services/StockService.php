<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stok miktarını değiştiren tek yer. Her değişiklik bir transaction içinde,
 * ilgili stok satırı kilitlenerek yapılır ve bir StockMovement kaydı bırakır.
 */
class StockService
{
    /**
     * Depoya ürün girişi (satın alma, iade vb.)
     *
     * @param  array<int, array{product_id:int, quantity:float, lot_number?:?string, expiry_date?:?string}>  $items
     */
    public function receive(User $user, Warehouse $warehouse, array $items, array $meta = []): Collection
    {
        $this->ensureWarehouseActive($warehouse);

        return DB::transaction(function () use ($user, $warehouse, $items, $meta) {
            $batch = (string) Str::uuid();
            $this->ensureProductsActive($items);

            return collect($this->sortItems($items))->map(fn ($item) => $this->apply(
                $batch, StockMovement::TYPE_IN, $user, $warehouse->id, $item['product_id'],
                abs((float) $item['quantity']), $item, $meta,
            ));
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

                $this->ensureProductsActive($items);

                $movements = collect($this->sortItems($items))->map(fn ($item) => $this->apply(
                    $batch, StockMovement::TYPE_USAGE, $user, $warehouse->id, $item['product_id'],
                    -abs((float) $item['quantity']), $item, $meta,
                ));

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

    /** Depolar arası transfer (ör. merkez depodan personelin çantasına) */
    public function transfer(User $user, Warehouse $from, Warehouse $to, array $items, array $meta = []): Collection
    {
        if ($from->is($to)) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Kaynak ve hedef depo aynı olamaz.']);
        }
        $this->ensureWarehouseActive($from);
        $this->ensureWarehouseActive($to);

        return DB::transaction(function () use ($user, $from, $to, $items, $meta) {
            $batch = (string) Str::uuid();
            $this->ensureProductsActive($items);

            $movements = collect();
            foreach ($this->sortItems($items) as $item) {
                $qty = abs((float) $item['quantity']);
                $movements->push($this->apply($batch, StockMovement::TYPE_TRANSFER_OUT, $user, $from->id, $item['product_id'], -$qty, $item, $meta));
                $movements->push($this->apply($batch, StockMovement::TYPE_TRANSFER_IN, $user, $to->id, $item['product_id'], $qty, $item, $meta));
            }

            return $movements;
        });
    }

    /** Sayım sonucu: depodaki miktarı sayılan değere eşitler, farkı hareket olarak yazar. */
    public function adjust(User $user, Warehouse $warehouse, int $productId, float $countedQuantity, array $meta = []): ?StockMovement
    {
        return DB::transaction(function () use ($user, $warehouse, $productId, $countedQuantity, $meta) {
            $stock = $this->lockStock($warehouse->id, $productId);
            $delta = round($countedQuantity - (float) $stock->quantity, 2);

            if ($delta == 0.0) {
                return null;
            }

            return $this->apply((string) Str::uuid(), StockMovement::TYPE_ADJUSTMENT, $user, $warehouse->id, $productId, $delta, [], $meta);
        });
    }

    private function apply(string $batch, string $type, User $user, int $warehouseId, int $productId, float $delta, array $item, array $meta): StockMovement
    {
        $stock = $this->lockStock($warehouseId, $productId);
        $newQuantity = round((float) $stock->quantity + $delta, 2);

        if ($newQuantity < 0) {
            $product = Product::withTrashed()->find($productId);
            throw ValidationException::withMessages([
                'items' => sprintf(
                    '%s için yeterli stok yok. Mevcut: %s %s, istenen: %s.',
                    $product?->name ?? "#{$productId}",
                    rtrim(rtrim(number_format((float) $stock->quantity, 2, '.', ''), '0'), '.'),
                    $product?->unit ?? '',
                    rtrim(rtrim(number_format(abs($delta), 2, '.', ''), '0'), '.'),
                ),
            ]);
        }

        $stock->update(['quantity' => $newQuantity]);

        return StockMovement::create([
            'batch_uuid' => $batch,
            'type' => $type,
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'user_id' => $user->id,
            'hospital_id' => $meta['hospital_id'] ?? null,
            'quantity' => $delta,
            'balance_after' => $newQuantity,
            'lot_number' => $item['lot_number'] ?? null,
            'expiry_date' => $item['expiry_date'] ?? null,
            'reference' => $meta['reference'] ?? null,
            'note' => $item['note'] ?? $meta['note'] ?? null,
            'latitude' => $meta['latitude'] ?? null,
            'longitude' => $meta['longitude'] ?? null,
        ]);
    }

    /** Stok satırını yoksa oluşturur ve transaction sonuna kadar kilitler. */
    private function lockStock(int $warehouseId, int $productId): Stock
    {
        Stock::query()->insertOrIgnore([
            'warehouse_id' => $warehouseId, 'product_id' => $productId, 'quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return Stock::query()
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** Kilitleri hep aynı sırada almak, eşzamanlı işlemlerde kilitlenmeyi (deadlock) önler. */
    private function sortItems(array $items): array
    {
        usort($items, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);

        return $items;
    }

    private function ensureProductsActive(array $items): void
    {
        $ids = array_unique(array_column($items, 'product_id'));
        $inactive = Product::query()->whereIn('id', $ids)->where('is_active', false)->pluck('name');

        if ($inactive->isNotEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Pasif ürünlerle işlem yapılamaz: '.$inactive->join(', '),
            ]);
        }
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
