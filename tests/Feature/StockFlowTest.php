<?php

namespace Tests\Feature;

use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\StockService;

class StockFlowTest extends ApiTestCase
{
    private function stockOf(Warehouse $w, int $productId): float
    {
        return (float) Stock::where('warehouse_id', $w->id)->where('product_id', $productId)->value('quantity');
    }

    public function test_full_flow_receive_transfer_use_and_report(): void
    {
        $manager = $this->userWithRole('yonetici');
        $staff = $this->userWithRole('personel', ['manager_id' => $manager->id]);
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $bag = $this->warehouse(['name' => 'Ali çanta']);
        $bag->users()->attach($staff);
        $gauze = $this->product(['name' => 'Steril Gazlı Bez']);

        $mToken = $this->tokenFor($manager);

        $this->withToken($mToken)->postJson('/api/stocks/receive', [
            'warehouse_id' => $central->id, 'reference' => 'FTR-1',
            'items' => [['product_id' => $gauze->id, 'quantity' => 100, 'lot_number' => 'L1', 'expiry_date' => '2028-01-01']],
        ])->assertCreated();

        $this->withToken($mToken)->postJson('/api/stocks/transfer', [
            'from_warehouse_id' => $central->id, 'to_warehouse_id' => $bag->id,
            'items' => [['product_id' => $gauze->id, 'quantity' => 20]],
        ])->assertCreated();

        $sToken = $this->tokenFor($staff);

        $this->withToken($sToken)->getJson('/api/mobile/warehouses')->assertOk()->assertJsonCount(1);
        $this->withToken($sToken)->getJson("/api/mobile/warehouses/{$bag->id}/stock")
            ->assertOk()->assertJsonPath('stocks.0.quantity', '20.00');

        $this->withToken($sToken)->postJson('/api/mobile/usages', [
            'warehouse_id' => $bag->id, 'reference' => 'Hasta 123', 'latitude' => 41.0, 'longitude' => 29.0,
            'items' => [['product_id' => $gauze->id, 'quantity' => 3]],
        ])->assertCreated();

        $this->assertSame(80.0, $this->stockOf($central, $gauze->id));
        $this->assertSame(17.0, $this->stockOf($bag, $gauze->id));

        // Personel kendi geçmişini görür
        $this->withToken($sToken)->getJson('/api/mobile/usages')->assertOk()->assertJsonPath('total', 1);

        // Yönetici ekibinin kullanımını görür
        $this->withToken($mToken)->getJson('/api/movements?type=usage')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.user.name', $staff->name);

        $rows = $this->withToken($mToken)->getJson('/api/reports/usage?group_by=user_product')->assertOk()->json('rows');
        $this->assertCount(1, $rows);
        $this->assertEquals(3, $rows[0]['total_quantity']);
    }

    public function test_cannot_use_more_than_available(): void
    {
        $staff = $this->userWithRole('personel');
        $bag = $this->warehouse();
        $bag->users()->attach($staff);
        $p = $this->product();

        $response = $this->withToken($this->tokenFor($staff))->postJson('/api/mobile/usages', [
            'warehouse_id' => $bag->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertStringContainsString('yeterli stok yok', $response->json('errors.items.0'));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_failed_line_rolls_back_whole_usage(): void
    {
        $staff = $this->userWithRole('personel');
        $bag = $this->warehouse();
        $bag->users()->attach($staff);
        [$a, $b] = [$this->product(), $this->product()];
        app(StockService::class)->receive($this->admin(), $bag, [['product_id' => $a->id, 'quantity' => 5]]);

        $this->withToken($this->tokenFor($staff))->postJson('/api/mobile/usages', [
            'warehouse_id' => $bag->id,
            'items' => [['product_id' => $a->id, 'quantity' => 2], ['product_id' => $b->id, 'quantity' => 1]],
        ])->assertStatus(422);

        $this->assertSame(5.0, $this->stockOf($bag, $a->id));
        $this->assertSame(0, StockMovement::where('type', 'usage')->count());
    }

    public function test_staff_cannot_use_from_unassigned_warehouse(): void
    {
        $staff = $this->userWithRole('personel');
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $p = $this->product();

        $this->withToken($this->tokenFor($staff))->postJson('/api/mobile/usages', [
            'warehouse_id' => $central->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_same_idempotency_key_does_not_deduct_twice(): void
    {
        $staff = $this->userWithRole('personel');
        $bag = $this->warehouse();
        $bag->users()->attach($staff);
        $p = $this->product();
        app(StockService::class)->receive($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 10]]);

        $token = $this->tokenFor($staff);
        $payload = ['warehouse_id' => $bag->id, 'items' => [['product_id' => $p->id, 'quantity' => 4]]];
        $headers = ['Idempotency-Key' => 'a8f1c2d3-0000-4000-8000-000000000001'];

        $first = $this->withToken($token)->postJson('/api/mobile/usages', $payload, $headers)->assertCreated();
        $second = $this->withToken($token)->postJson('/api/mobile/usages', $payload, $headers)->assertOk();

        $this->assertSame($first->json('batch_uuid'), $second->json('batch_uuid'));
        $this->assertSame(6.0, $this->stockOf($bag, $p->id));
    }

    public function test_staff_only_sees_own_movements_and_other_team_is_hidden(): void
    {
        $managerA = $this->userWithRole('yonetici');
        $managerB = $this->userWithRole('yonetici');
        $staffB = $this->userWithRole('personel', ['manager_id' => $managerB->id]);
        $bag = $this->warehouse();
        $bag->users()->attach($staffB);
        $p = $this->product();
        $svc = app(StockService::class);
        $svc->receive($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 10]]);
        $svc->recordUsage($staffB, $bag, [['product_id' => $p->id, 'quantity' => 1]]);

        $this->withToken($this->tokenFor($managerA))->getJson('/api/movements?type=usage')
            ->assertOk()->assertJsonPath('total', 0);
        $this->withToken($this->tokenFor($managerB))->getJson('/api/movements?type=usage')
            ->assertOk()->assertJsonPath('total', 1);
    }

    public function test_adjust_sets_counted_quantity(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $p = $this->product();
        app(StockService::class)->receive($admin, $central, [['product_id' => $p->id, 'quantity' => 10]]);

        $this->withToken($this->tokenFor($admin))->postJson('/api/stocks/adjust', [
            'warehouse_id' => $central->id, 'product_id' => $p->id, 'counted_quantity' => 7, 'note' => 'Sayım',
        ])->assertCreated()->assertJsonPath('movement.quantity', '-3.00');

        $this->assertSame(7.0, $this->stockOf($central, $p->id));
    }

    public function test_movements_cannot_be_modified(): void
    {
        $bag = $this->warehouse();
        $p = $this->product();
        $m = app(StockService::class)->receive($this->admin(), $bag, [['product_id' => $p->id, 'quantity' => 1]])->first();

        $this->expectException(\LogicException::class);
        $m->update(['quantity' => 100]);
    }

    public function test_product_with_stock_cannot_be_deleted(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $p = $this->product();
        app(StockService::class)->receive($admin, Warehouse::first(), [['product_id' => $p->id, 'quantity' => 1]]);

        $this->withToken($this->tokenFor($admin))->deleteJson("/api/products/{$p->id}")->assertStatus(422);
    }

    public function test_dashboard_returns_daily_usage_and_top_products(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $bag = $this->warehouse();
        $p = $this->product(['name' => 'Enjektör']);
        $svc = app(StockService::class);
        $svc->receive($admin, $bag, [['product_id' => $p->id, 'quantity' => 10]]);
        $svc->recordUsage($admin, $bag, [['product_id' => $p->id, 'quantity' => 3]]);

        $res = $this->withToken($this->tokenFor($admin))->getJson('/api/dashboard')->assertOk();

        $this->assertCount(14, $res->json('daily_usage'));
        $this->assertSame(1, $res->json('daily_usage.13.count'));
        $this->assertSame('Enjektör', $res->json('top_products.0.name'));
        $this->assertEquals(3, $res->json('top_products.0.total_quantity'));
    }
}
