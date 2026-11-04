<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Validation\ValidationException;

class LotTrackingTest extends ApiTestCase
{
    private StockService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = app(StockService::class);
    }

    private function adminToken(): string
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);

        return $this->tokenFor($admin);
    }

    private function rows(Warehouse $w, int $productId): array
    {
        return Stock::where('warehouse_id', $w->id)->where('product_id', $productId)->where('quantity', '>', 0)
            ->orderBy('expiry_date')->get()
            ->map(fn ($s) => [$s->lot_number, $s->serial_number, $s->expiry_date?->toDateString(), (float) $s->quantity])->all();
    }

    public function test_lot_product_requires_lot_number_on_receive(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $central = Warehouse::where('code', 'MERKEZ')->first();

        $this->withToken($this->adminToken())->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id,
            'items' => [['product_id' => $p->id, 'quantity' => 5]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.lot_number');
    }

    public function test_expired_goods_cannot_be_received(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $this->expectException(ValidationException::class);
        $this->svc->receive($this->admin(), $this->warehouse(), [
            ['product_id' => $p->id, 'quantity' => 1, 'lot_number' => 'X', 'expiry_date' => now()->subDay()->toDateString()],
        ]);
    }

    public function test_transfer_without_lot_uses_earliest_expiry_first_and_keeps_lot(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $bag = $this->warehouse();
        $this->svc->receive($this->admin(), $central, [
            ['product_id' => $p->id, 'quantity' => 10, 'lot_number' => 'GEC', 'expiry_date' => now()->addYears(2)->toDateString()],
            ['product_id' => $p->id, 'quantity' => 4, 'lot_number' => 'ERKEN', 'expiry_date' => now()->addMonths(3)->toDateString()],
        ]);

        // 6 isteniyor: önce ERKEN'in 4'ü, sonra GEC'ten 2
        $this->withToken($this->adminToken())->postJson('/api/stocks/transfer', [
            'from_warehouse_id' => $central->id, 'to_warehouse_id' => $bag->id,
            'items' => [['product_id' => $p->id, 'quantity' => 6]],
        ])->assertCreated();

        $this->assertSame(['GEC'], array_column($this->rows($central, $p->id), 0));
        $this->assertSame(8.0, $this->rows($central, $p->id)[0][3]);
        $bagRows = $this->rows($bag, $p->id);
        $this->assertSame([['ERKEN', 4.0], ['GEC', 2.0]], array_map(fn ($r) => [$r[0], $r[3]], $bagRows));
    }

    public function test_specific_lot_can_be_targeted(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $bag = $this->warehouse();
        $this->svc->receive($this->admin(), $bag, [
            ['product_id' => $p->id, 'quantity' => 5, 'lot_number' => 'A', 'expiry_date' => now()->addMonths(2)->toDateString()],
            ['product_id' => $p->id, 'quantity' => 5, 'lot_number' => 'B', 'expiry_date' => now()->addYear()->toDateString()],
        ]);

        $this->svc->recordUsage($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 2, 'lot_number' => 'B']]);
        $this->assertSame([['A', 5.0], ['B', 3.0]], array_map(fn ($r) => [$r[0], $r[3]], $this->rows($bag, $p->id)));

        $this->expectException(ValidationException::class);
        $this->svc->recordUsage($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 4, 'lot_number' => 'B']]);
    }

    public function test_expired_stock_is_skipped_and_cannot_be_used(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $bag = $this->warehouse();
        $this->svc->receive($this->admin(), $bag, [
            ['product_id' => $p->id, 'quantity' => 3, 'lot_number' => 'YENI', 'expiry_date' => now()->addYear()->toDateString()],
        ]);
        // Zaman geçti, bir lotun SKT'si doldu (girişte engellendiği için doğrudan ekliyoruz)
        Stock::create(['warehouse_id' => $bag->id, 'product_id' => $p->id, 'lot_number' => 'ESKI',
            'expiry_date' => now()->subDays(3)->toDateString(), 'lot_key' => Stock::keyFor('ESKI', null, now()->subDays(3)->toDateString()), 'quantity' => 5]);

        $this->svc->recordUsage($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 2]]);
        $this->assertSame(1.0, (float) Stock::where('lot_number', 'YENI')->value('quantity'));
        $this->assertSame(5.0, (float) Stock::where('lot_number', 'ESKI')->value('quantity'));

        try {
            $this->svc->recordUsage($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 2]]);
            $this->fail('SKT geçmiş lot kullanılmamalıydı');
        } catch (ValidationException $e) {
            $this->assertStringContainsString("SKT'si geçmiş 5", $e->errors()['items'][0]);
        }

        // Açıkça seçilerek imha/iade amaçlı merkeze taşınabilir
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $this->svc->transfer($this->admin(), $bag, $central, [['product_id' => $p->id, 'quantity' => 5, 'lot_number' => 'ESKI']]);
        $this->assertSame(5.0, (float) Stock::where('warehouse_id', $central->id)->where('lot_number', 'ESKI')->value('quantity'));
    }

    public function test_serial_numbers_are_unique_and_one_each(): void
    {
        $p = $this->product(['tracking' => 'serial', 'name' => 'Diz protezi']);
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id,
            'items' => [
                ['product_id' => $p->id, 'quantity' => 1, 'serial_number' => 'SN-1', 'lot_number' => 'L9'],
                ['product_id' => $p->id, 'quantity' => 1, 'serial_number' => 'SN-2', 'lot_number' => 'L9'],
            ],
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id, 'items' => [['product_id' => $p->id, 'quantity' => 1, 'serial_number' => 'SN-1']],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.serial_number');

        $this->withToken($token)->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id, 'items' => [['product_id' => $p->id, 'quantity' => 2, 'serial_number' => 'SN-3']],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');

        $this->withToken($token)->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('items.0.serial_number');

        // Belirli seriyi araca taşı
        $bag = $this->warehouse();
        $this->svc->transfer($this->admin(), $central, $bag, [['product_id' => $p->id, 'quantity' => 1, 'serial_number' => 'SN-2']]);
        $this->assertSame([[null, 'SN-2']], array_map(fn ($r) => [null, $r[1]], $this->rows($bag, $p->id)));
        $this->assertSame('L9', Stock::where('warehouse_id', $bag->id)->value('lot_number'));

        // Sayımda seri numaralı üründe 2 sayılamaz
        $stockId = Stock::where('warehouse_id', $bag->id)->value('id');
        $this->withToken($token)->postJson('/api/stocks/adjust', [
            'warehouse_id' => $bag->id, 'product_id' => $p->id, 'stock_id' => $stockId, 'counted_quantity' => 2, 'note' => 'x',
        ])->assertStatus(422);
    }

    public function test_adjust_needs_a_target_when_several_lots_exist(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $bag = $this->warehouse();
        $this->svc->receive($this->admin(), $bag, [
            ['product_id' => $p->id, 'quantity' => 5, 'lot_number' => 'A'],
            ['product_id' => $p->id, 'quantity' => 5, 'lot_number' => 'B'],
        ]);
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/stocks/adjust', [
            'warehouse_id' => $bag->id, 'product_id' => $p->id, 'counted_quantity' => 3, 'note' => 'sayım',
        ])->assertStatus(422)->assertJsonValidationErrors('lot_number');

        $stockB = Stock::where('lot_number', 'B')->value('id');
        $this->withToken($token)->postJson('/api/stocks/adjust', [
            'warehouse_id' => $bag->id, 'product_id' => $p->id, 'stock_id' => $stockB, 'counted_quantity' => 3, 'note' => 'sayım',
        ])->assertCreated()->assertJsonPath('movement.lot_number', 'B')->assertJsonPath('movement.quantity', '-2.00');

        // Sayımda sistemde olmayan bir lot bulundu
        $this->withToken($token)->postJson('/api/stocks/adjust', [
            'warehouse_id' => $bag->id, 'product_id' => $p->id, 'lot_number' => 'C', 'counted_quantity' => 2, 'note' => 'fazla bulundu',
        ])->assertCreated();
        $this->assertSame(2.0, (float) Stock::where('lot_number', 'C')->value('quantity'));
    }

    public function test_stock_list_shows_lots_and_filters_by_expiry(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $bag = $this->warehouse();
        $this->svc->receive($this->admin(), $bag, [
            ['product_id' => $p->id, 'quantity' => 1, 'lot_number' => 'KRITIK', 'expiry_date' => now()->addDays(10)->toDateString()],
            ['product_id' => $p->id, 'quantity' => 1, 'lot_number' => 'YAKIN', 'expiry_date' => now()->addDays(60)->toDateString()],
            ['product_id' => $p->id, 'quantity' => 1, 'lot_number' => 'UZAK', 'expiry_date' => now()->addYears(2)->toDateString()],
        ]);
        $token = $this->adminToken();

        $lots = fn ($url) => array_column($this->withToken($token)->getJson($url)->assertOk()->json('data'), 'lot_number');

        $this->assertSame(['KRITIK', 'YAKIN', 'UZAK'], $lots("/api/stocks?warehouse_id={$bag->id}"));
        $this->assertSame(['KRITIK'], $lots("/api/stocks?warehouse_id={$bag->id}&expiry=critical"));
        $this->assertSame(['KRITIK', 'YAKIN'], $lots("/api/stocks?warehouse_id={$bag->id}&expiry=warning"));
        $this->assertSame(['YAKIN'], $lots("/api/stocks?warehouse_id={$bag->id}&q=yak"));
    }

    public function test_gtin_is_stored_as_14_digits_and_found_by_scan(): void
    {
        $token = $this->adminToken();
        $this->withToken($token)->postJson('/api/products', [
            'name' => 'Stent', 'code' => 'ST-1', 'gtin' => '8691234567893', 'tracking' => 'serial',
        ])->assertCreated()->assertJsonPath('gtin', '08691234567893')->assertJsonPath('tracking', 'serial');

        // Aynı GTIN 14 haneli yazılsa da tekrar kaydedilemez
        $this->withToken($token)->postJson('/api/products', ['name' => 'X', 'code' => 'X-1', 'gtin' => '08691234567893'])
            ->assertStatus(422)->assertJsonValidationErrors('gtin');

        $this->withToken($token)->getJson('/api/products/barcode/08691234567893')->assertOk()->assertJsonPath('code', 'ST-1');
        $this->withToken($token)->getJson('/api/products/barcode/8691234567893')->assertOk()->assertJsonPath('code', 'ST-1');
    }

    public function test_tracking_cannot_change_while_in_stock(): void
    {
        $p = $this->product(['tracking' => 'lot']);
        $this->svc->receive($this->admin(), $this->warehouse(), [['product_id' => $p->id, 'quantity' => 1, 'lot_number' => 'A']]);

        $this->withToken($this->adminToken())->putJson("/api/products/{$p->id}", ['tracking' => 'serial'])
            ->assertStatus(422)->assertJsonValidationErrors('tracking');
    }
}
