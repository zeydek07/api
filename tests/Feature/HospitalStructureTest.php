<?php

namespace Tests\Feature;

use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Services\StockService;

class HospitalStructureTest extends ApiTestCase
{
    private function adminToken(): string
    {
        $admin = $this->admin();
        $admin->update(['password' => 'secret-pass']);

        return $this->tokenFor($admin);
    }

    public function test_new_hospital_gets_a_default_consignment_location(): void
    {
        $id = $this->withToken($this->adminToken())->postJson('/api/hospitals', ['name' => 'Yeni Hastane'])->assertCreated()->json('id');

        $locations = Hospital::find($id)->locations;
        $this->assertCount(1, $locations);
        $this->assertSame('Ana konsinye deposu', $locations[0]->name);
        $this->assertSame('consignment', $locations[0]->type);
    }

    public function test_location_rules(): void
    {
        $h = Hospital::create(['name' => 'H']);
        $token = $this->adminToken();
        $main = $h->locations()->first();

        // Aynı adla ikinci lokasyon açılamaz, farklı adla açılır
        $this->withToken($token)->postJson("/api/hospitals/{$h->id}/locations", ['name' => 'Ana konsinye deposu'])->assertStatus(422);
        $or = $this->withToken($token)->postJson("/api/hospitals/{$h->id}/locations", ['name' => 'Ameliyathane dolabı'])->assertCreated()->json();
        $this->assertSame("KNS-{$h->id}-2", $or['code']);

        // Son aktif lokasyon kapatılamaz
        $this->withToken($token)->putJson("/api/hospitals/{$h->id}/locations/{$or['id']}", ['is_active' => false])->assertOk();
        $this->withToken($token)->putJson("/api/hospitals/{$h->id}/locations/{$main->id}", ['is_active' => false])->assertStatus(422);

        // Başka hastanenin lokasyonuna bu adresten erişilemez
        $other = Hospital::create(['name' => 'Diğer']);
        $this->withToken($token)->putJson("/api/hospitals/{$h->id}/locations/{$other->locations()->first()->id}", ['name' => 'x'])->assertNotFound();

        // Konsinye lokasyonu Depolar sayfasından değiştirilemez, listede de varsayılan olarak çıkmaz
        $this->withToken($token)->putJson("/api/warehouses/{$main->id}", ['name' => 'x'])->assertStatus(422);
        $names = array_column($this->withToken($token)->getJson('/api/warehouses')->json(), 'name');
        $this->assertNotContains('Ana konsinye deposu', $names);
        $this->assertContains('Ana konsinye deposu', array_column($this->withToken($token)->getJson('/api/warehouses?include_consignment=1')->json(), 'name'));
    }

    public function test_contacts_can_be_quick_added_by_representative_but_not_edited(): void
    {
        $h = Hospital::create(['name' => 'H']);
        $rep = $this->userWithRole('mumessil');
        $token = $this->tokenFor($rep);

        $c = $this->withToken($token)->postJson("/api/hospitals/{$h->id}/contacts", [
            'name' => 'Ayşe Demir', 'title' => 'Sorumlu hemşire', 'phone' => '0532', 'location_id' => $h->locations()->first()->id,
        ])->assertCreated()->assertJsonPath('location.name', 'Ana konsinye deposu')->json();
        $this->assertSame($rep->id, HospitalContact::find($c['id'])->created_by);

        $this->withToken($token)->getJson("/api/hospitals/{$h->id}/contacts")->assertOk()->assertJsonCount(1);
        $this->withToken($token)->putJson("/api/hospitals/{$h->id}/contacts/{$c['id']}", ['name' => 'X'])->assertStatus(403);

        // Başka hastanenin lokasyonu kişiye bağlanamaz
        $other = Hospital::create(['name' => 'Diğer']);
        $this->withToken($token)->postJson("/api/hospitals/{$h->id}/contacts", [
            'name' => 'B', 'location_id' => $other->locations()->first()->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_responsible_rep_and_consignment_units_in_list(): void
    {
        $rep = $this->userWithRole('mumessil');
        $token = $this->adminToken();
        $id = $this->withToken($token)->postJson('/api/hospitals', ['name' => 'Sorumlu Testi', 'responsible_user_id' => $rep->id])->assertCreated()->json('id');

        // Konsinye stoğu doğrudan yaz (teslimat ekranı 3. parçada)
        $loc = Hospital::find($id)->locations()->first();
        $p = $this->product();
        app(StockService::class)->receive($this->admin(), $this->warehouse(), [['product_id' => $p->id, 'quantity' => 5]]);
        Stock::create(['warehouse_id' => $loc->id, 'product_id' => $p->id, 'lot_key' => 'L:|', 'quantity' => 7]);

        $row = collect($this->withToken($token)->getJson('/api/hospitals?q=Sorumlu')->json('data'))->first();
        $this->assertSame($rep->name, $row['responsible']['name']);
        $this->assertEquals(7, $row['consignment_units']);

        $this->withToken($this->tokenFor($rep))->getJson('/api/hospitals?mine=1')->assertOk()->assertJsonPath('total', 1);

        $cons = $this->withToken($token)->getJson("/api/hospitals/{$id}/consignment")->assertOk();
        $this->assertEquals(7, $cons->json('summary.units'));

        // Konsinye ürün varken hastane silinemez
        $this->withToken($token)->deleteJson("/api/hospitals/{$id}")->assertStatus(422);
    }

    public function test_plain_transfer_or_receive_into_hospital_is_blocked(): void
    {
        $h = Hospital::create(['name' => 'H']);
        $loc = $h->locations()->first();
        $central = Warehouse::where('code', 'MERKEZ')->first();
        $p = $this->product();
        app(StockService::class)->receive($this->admin(), $central, [['product_id' => $p->id, 'quantity' => 5]]);
        $token = $this->adminToken();

        $this->withToken($token)->postJson('/api/stocks/transfer', [
            'from_warehouse_id' => $central->id, 'to_warehouse_id' => $loc->id,
            'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422)->assertJsonValidationErrors('warehouse_id');

        $this->withToken($token)->postJson('/api/stocks/receive', [
            'warehouse_id' => $loc->id, 'items' => [['product_id' => $p->id, 'quantity' => 1]],
        ])->assertStatus(422);
    }
}
