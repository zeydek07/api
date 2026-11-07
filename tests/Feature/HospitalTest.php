<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\Role;
use App\Services\StockService;

class HospitalTest extends ApiTestCase
{
    private function setUpDelivery(): array
    {
        $rep = $this->userWithRole('personel');
        $bag = $this->warehouse(['name' => 'Mümessil çanta']);
        $bag->users()->attach($rep);
        $product = $this->product(['name' => 'Kateter']);
        app(StockService::class)->receive($this->admin(), $bag, [['product_id' => $product->id, 'quantity' => 20]]);
        $hospital = Hospital::create(['name' => 'Şehir Hastanesi', 'city' => 'Ankara']);

        return [$rep, $bag, $product, $hospital];
    }

    public function test_default_roles_exist(): void
    {
        $this->assertTrue(Role::where('slug', 'personel')->exists());
        $this->assertTrue(Role::where('slug', 'depocu')->exists());
    }

    public function test_representative_delivers_to_hospital_and_it_is_recorded(): void
    {
        [$rep, $bag, $product, $hospital] = $this->setUpDelivery();
        $token = $this->tokenFor($rep);

        $this->withToken($token)->getJson('/api/mobile/hospitals')->assertOk()->assertJsonPath('0.name', 'Şehir Hastanesi');

        $this->withToken($token)->postJson('/api/mobile/usages', [
            'warehouse_id' => $bag->id,
            'hospital_id' => $hospital->id,
            'items' => [['product_id' => $product->id, 'quantity' => 4]],
            'latitude' => 40.9, 'longitude' => 29.18,
        ])->assertCreated()->assertJsonPath('movements.0.hospital.name', 'Şehir Hastanesi');

        $this->assertDatabaseHas('stock_movements', [
            'type' => 'usage', 'hospital_id' => $hospital->id, 'user_id' => $rep->id, 'quantity' => -4,
        ]);

        // Mümessil kendi geçmişinde hastaneyi görür
        $this->withToken($token)->getJson('/api/mobile/usages')->assertOk()
            ->assertJsonPath('data.0.hospital.name', 'Şehir Hastanesi');
    }

    public function test_hospital_card_and_report_show_deliveries(): void
    {
        [$rep, $bag, $product, $hospital] = $this->setUpDelivery();
        $svc = app(StockService::class);
        $svc->recordUsage($rep, $bag, [['product_id' => $product->id, 'quantity' => 3]], ['hospital_id' => $hospital->id]);
        $svc->recordUsage($rep, $bag, [['product_id' => $product->id, 'quantity' => 2]], ['hospital_id' => $hospital->id]);

        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        $card = $this->withToken($token)->getJson("/api/hospitals/{$hospital->id}")->assertOk();
        $this->assertEquals(5, $card->json('products.0.total_quantity'));
        $this->assertSame(2, (int) $card->json('representatives.0.delivery_count'));
        $this->assertCount(2, $card->json('latest'));

        $rows = $this->withToken($token)->getJson('/api/reports/usage?group_by=hospital_product')->assertOk()->json('rows');
        $this->assertSame('Şehir Hastanesi', $rows[0]['hospital_name']);
        $this->assertEquals(5, $rows[0]['total_quantity']);

        $list = $this->withToken($token)->getJson('/api/hospitals')->assertOk();
        // Listedeki sayaç konsinye teslimat belgelerini sayar; kullanım kayıtları sayılmaz
        $this->assertSame(0, $list->json('data.0.deliveries_30d'));

        $this->withToken($token)->getJson("/api/movements?hospital_id={$hospital->id}")->assertOk()->assertJsonPath('total', 2);
    }

    public function test_inactive_hospital_cannot_be_selected(): void
    {
        [$rep, $bag, $product, $hospital] = $this->setUpDelivery();
        $hospital->update(['is_active' => false]);

        $this->withToken($this->tokenFor($rep))->postJson('/api/mobile/usages', [
            'warehouse_id' => $bag->id,
            'hospital_id' => $hospital->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'location_error' => 'denied',
        ])->assertStatus(422)->assertJsonValidationErrors('hospital_id');
    }

    public function test_representative_cannot_manage_hospitals(): void
    {
        [$rep] = $this->setUpDelivery();
        $token = $this->tokenFor($rep);

        $this->withToken($token)->getJson('/api/hospitals')->assertOk();
        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'X'])->assertStatus(403);
    }

    public function test_all_hospital_types_are_accepted_and_unknown_type_is_rejected(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        foreach (['public', 'university', 'private', 'training_research', 'city', 'other'] as $i => $type) {
            $this->withToken($token)->postJson('/api/hospitals', ['name' => "Hastane {$i}", 'type' => $type])
                ->assertCreated()->assertJsonPath('type', $type);
        }

        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'X', 'type' => 'klinik'])
            ->assertStatus(422)->assertJsonValidationErrors('type');

        $this->withToken($token)->getJson('/api/hospitals?type=training_research')->assertOk()->assertJsonPath('total', 1);
    }

    public function test_provinces_list_has_81_cities_in_turkish_order(): void
    {
        $this->getJson('/api/provinces')->assertStatus(401);

        $list = $this->withToken($this->tokenFor($this->userWithRole('personel')))
            ->getJson('/api/provinces')->assertOk()->json();

        $this->assertCount(81, $list);
        $this->assertSame(['code' => 1, 'name' => 'Adana'], $list[0]);
        $this->assertSame('Düzce', $list[array_search('Düzce', array_column($list, 'name'))]['name']);
        $names = array_column($list, 'name');
        // Türkçe sıra: Çanakkale C'den sonra, Ş S'den sonra; İ I'dan sonra gelir
        $this->assertLessThan(array_search('Çanakkale', $names), array_search('Bursa', $names));
        $this->assertLessThan(array_search('Şanlıurfa', $names), array_search('Sivas', $names));
        $this->assertLessThan(array_search('İstanbul', $names), array_search('Iğdır', $names));
        $this->assertSame('Zonguldak', end($names));
    }

    public function test_districts_endpoint_returns_973_districts_in_total(): void
    {
        $token = $this->tokenFor($this->userWithRole('personel'));

        $istanbul = $this->withToken($token)->getJson('/api/provinces/34/districts')->assertOk()->json();
        $this->assertCount(39, $istanbul);
        $this->assertContains('Kartal', $istanbul);
        $this->assertSame('Adalar', $istanbul[0]);

        $this->assertContains('Mamak', $this->withToken($token)->getJson('/api/provinces/6/districts')->json());
        $this->assertContains('Merkez', $this->withToken($token)->getJson('/api/provinces/2/districts')->json());
        $this->withToken($token)->getJson('/api/provinces/82/districts')->assertNotFound();

        $this->assertSame(973, array_sum(array_map('count', config('districts'))));
        $this->assertSame(array_keys(config('provinces')), array_keys(config('districts')));
    }

    public function test_district_must_belong_to_city(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'A', 'city' => 'Ankara', 'district' => 'Kartal'])
            ->assertStatus(422)->assertJsonValidationErrors('district');
        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'B', 'district' => 'Kartal'])
            ->assertStatus(422)->assertJsonValidationErrors('district');

        $id = $this->withToken($token)->postJson('/api/hospitals', ['name' => 'C', 'city' => 'İstanbul', 'district' => 'Kartal'])
            ->assertCreated()->json('id');

        // İl değişirse eski ilçe artık geçersiz
        $this->withToken($token)->putJson("/api/hospitals/{$id}", ['city' => 'İzmir', 'district' => 'Kartal'])->assertStatus(422);
        $this->withToken($token)->putJson("/api/hospitals/{$id}", ['city' => 'İzmir', 'district' => 'Bornova'])->assertOk();
        // Yalnızca ilçe gönderilirse kayıtlı ile göre kontrol edilir
        $this->withToken($token)->putJson("/api/hospitals/{$id}", ['district' => 'Karşıyaka'])->assertOk();
        $this->withToken($token)->putJson("/api/hospitals/{$id}", ['district' => 'Çankaya'])->assertStatus(422);
    }

    public function test_hospital_city_must_be_a_valid_province(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'A', 'city' => 'Istanbull'])
            ->assertStatus(422)->assertJsonValidationErrors('city');
        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'B', 'city' => 'Şanlıurfa'])->assertCreated();
        $this->withToken($token)->postJson('/api/hospitals', ['name' => 'C'])->assertCreated();
    }

    public function test_admin_crud(): void
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);
        $token = $this->tokenFor($admin);

        $id = $this->withToken($token)->postJson('/api/hospitals', [
            'name' => 'Özel Test Hastanesi', 'type' => 'private', 'city' => 'İstanbul', 'code' => 'H-1',
        ])->assertCreated()->json('id');

        $this->withToken($token)->putJson("/api/hospitals/{$id}", ['address' => 'Kartal, İstanbul'])->assertOk()->assertJsonPath('address', 'Kartal, İstanbul');

        // Kaldırılan alanlar artık kaydedilmez ve yanıtta yer almaz
        $res = $this->withToken($token)->putJson("/api/hospitals/{$id}", ['phone' => '0212', 'contact_person' => 'X'])->assertOk();
        $this->assertArrayNotHasKey('phone', $res->json());
        $this->assertArrayNotHasKey('contact_person', $res->json());
        $this->withToken($token)->getJson('/api/hospitals/cities')->assertOk()->assertJson(['İstanbul']);
        $this->withToken($token)->deleteJson("/api/hospitals/{$id}")->assertOk();
        $this->assertSoftDeleted('hospitals', ['id' => $id]);
    }
}
