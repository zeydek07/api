<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\DemoSeeder;

class DemoSeederTest extends ApiTestCase
{
    public function test_demo_data_is_consistent_and_seeding_twice_is_safe(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(30, Product::count());
        $this->assertSame(10, Hospital::count());
        $this->assertSame(3, User::whereHas('role', fn ($q) => $q->where('slug', 'depocu'))->count());
        $this->assertSame(3, User::whereHas('role', fn ($q) => $q->where('slug', 'mumessil'))->count());

        // Teslimatlar hastaneye bağlı ve mümessiller tarafından yapılmış
        $usages = StockMovement::where('type', 'usage')->get();
        $this->assertGreaterThan(20, $usages->count());
        $this->assertTrue($usages->every(fn ($m) => $m->hospital_id !== null));
        $this->assertTrue($usages->every(fn ($m) => str_starts_with(User::find($m->user_id)->email, 'mumessil')));

        // Geçmişe yayılmış
        $this->assertTrue($usages->min('created_at')->lt(now()->subDays(10)));

        // Stok hiçbir yerde eksiye düşmedi ve hareketlerle tutarlı
        $this->assertSame(0, Stock::where('quantity', '<', 0)->count());
        foreach (Stock::all() as $s) {
            $sum = StockMovement::where('warehouse_id', $s->warehouse_id)->where('product_id', $s->product_id)->sum('quantity');
            $this->assertEquals((float) $s->quantity, (float) $sum);
        }

        // Kritik stok örneği var
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $low = $this->withToken($this->tokenFor($admin))->getJson('/api/reports/low-stock')->assertOk()->json();
        $this->assertNotEmpty($low);

        // Mümessil örnek şifreyle giriş yapabilir
        $this->postJson('/api/auth/login', [
            'email' => 'mumessil1@medikal.test', 'password' => DemoSeeder::PASSWORD, 'device_name' => 'test',
        ])->assertOk();

        // İkinci çalıştırma veri çoğaltmaz
        $count = StockMovement::count();
        $this->seed(DemoSeeder::class);
        $this->assertSame($count, StockMovement::count());
    }
}
