<?php

namespace Tests\Feature;

use App\Models\Delivery;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Services\StockService;
use App\Mail\DeliveryDocumentMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class DeliveryTest extends ApiTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private $rep;

    private $manager;

    private $bag;

    private Hospital $hospital;

    private $location;

    private $product;

    private HospitalContact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();

        $this->manager = $this->userWithRole('yonetici');
        $this->rep = $this->userWithRole('personel', ['manager_id' => $this->manager->id]);
        $this->bag = $this->warehouse(['name' => 'Can - Araç']);
        $this->bag->users()->attach($this->rep);
        $this->hospital = Hospital::create(['name' => 'Kartal Devlet Hastanesi']);
        $this->location = $this->hospital->locations()->first();
        $this->contact = $this->hospital->contacts()->create(['name' => 'Ayşe Demir', 'title' => 'Sorumlu hemşire']);
        $this->product = $this->product(['tracking' => 'lot', 'name' => 'Kateter']);

        app(StockService::class)->receive($this->admin(), $this->bag, [
            ['product_id' => $this->product->id, 'quantity' => 5, 'lot_number' => 'YAKIN', 'expiry_date' => now()->addMonths(2)->toDateString()],
            ['product_id' => $this->product->id, 'quantity' => 5, 'lot_number' => 'UZAK', 'expiry_date' => now()->addYears(2)->toDateString()],
        ]);
    }

    private function stockId(string $lot, $warehouse = null): int
    {
        return Stock::where('warehouse_id', ($warehouse ?? $this->bag)->id)->where('lot_number', $lot)->value('id');
    }

    private function payload(array $override = []): array
    {
        return [
            'type' => 'delivery',
            'hospital_id' => $this->hospital->id,
            'location_id' => $this->location->id,
            'vehicle_id' => $this->bag->id,
            'contact_id' => $this->contact->id,
            'items' => [['stock_id' => $this->stockId('YAKIN'), 'quantity' => 3]],
            'signature' => self::PNG,
            'latitude' => 40.9, 'longitude' => 29.18,
            ...$override,
        ];
    }

    public function test_delivery_moves_selected_lot_to_hospital_with_proof(): void
    {
        $token = $this->tokenFor($this->rep);
        $res = $this->withToken($token)->postJson('/api/deliveries', $this->payload())->assertCreated();

        $d = Delivery::first();
        $this->assertMatchesRegularExpression('/^TS-\d{4}-\d{6}$/', $d->number);
        $this->assertSame('Ayşe Demir', $d->receiver_name);
        $this->assertSame('Sorumlu hemşire', $d->receiver_title);
        $this->assertSame($this->rep->id, $d->user_id);
        $this->assertNotNull($d->signature_path);
        Storage::disk('local')->assertExists($d->signature_path);

        // Stok: araçtan 3 düştü, hastanede aynı lot/SKT ile 3 var
        $this->assertSame(2.0, (float) Stock::find($this->stockId('YAKIN'))->quantity);
        $there = Stock::where('warehouse_id', $this->location->id)->first();
        $this->assertSame(['YAKIN', 3.0], [$there->lot_number, (float) $there->quantity]);
        $this->assertSame(now()->addMonths(2)->toDateString(), $there->expiry_date->toDateString());

        $this->assertSame(2, StockMovement::where('delivery_id', $d->id)->count());
        $this->assertSame([StockMovement::TYPE_CONSIGN_OUT, StockMovement::TYPE_CONSIGN_IN],
            StockMovement::where('delivery_id', $d->id)->orderBy('id')->pluck('type')->all());

        // Belge ayrıntısı ve imza görseli
        $show = $this->withToken($token)->getJson("/api/deliveries/{$d->id}")->assertOk();
        $this->assertSame('Kateter', $show->json('lines.0.product.name'));
        $this->assertTrue($show->json('has_signature'));
        $this->assertArrayNotHasKey('signature_path', $show->json());
        $this->withToken($token)->get("/api/deliveries/{$d->id}/signature")->assertOk()->assertHeader('content-type', 'image/png');

        // Hastanenin konsinye stoğunda ve hastane listesinde görünüyor
        $mgr = $this->tokenFor($this->manager);
        $this->withToken($mgr)->getJson("/api/hospitals/{$this->hospital->id}/consignment")
            ->assertOk()->assertJsonPath('summary.units', 3);
        $row = $this->withToken($mgr)->getJson('/api/hospitals?q=Kartal')->assertOk()->json('data.0');
        $this->assertSame(1, $row['deliveries_30d']);
        $this->assertEquals(3, $row['consignment_units']);
        $this->assertNotNull($row['last_delivery_at']);
    }

    public function test_signature_is_required_and_receiver_must_be_given(): void
    {
        $token = $this->tokenFor($this->rep);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['signature' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['contact_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('contact_id');
        // Kayıtlı olmayan kişi adıyla da olur
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['contact_id' => null, 'receiver_name' => 'Nöbetçi hemşire']))
            ->assertCreated();
        $this->assertSame(0, Delivery::where('receiver_name', 'Ayşe Demir')->count());
    }

    public function test_rules_on_places_and_stock(): void
    {
        $token = $this->tokenFor($this->rep);

        // Başka hastanenin lokasyonu
        $other = Hospital::create(['name' => 'Diğer']);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['location_id' => $other->locations()->first()->id]))
            ->assertStatus(422)->assertJsonValidationErrors('location_id');

        // Başkasının aracı
        $otherBag = $this->warehouse();
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['vehicle_id' => $otherBag->id]))
            ->assertStatus(422)->assertJsonValidationErrors('vehicle_id');

        // Araçtakinden fazla
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['items' => [['stock_id' => $this->stockId('YAKIN'), 'quantity' => 9]]]))
            ->assertStatus(422)->assertJsonValidationErrors('items');

        // SKT'si geçmiş lot hastaneye bırakılamaz
        $expired = Stock::create(['warehouse_id' => $this->bag->id, 'product_id' => $this->product->id, 'lot_number' => 'ESKI',
            'expiry_date' => now()->subDay()->toDateString(), 'lot_key' => Stock::keyFor('ESKI', null, now()->subDay()->toDateString()), 'quantity' => 2]);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['items' => [['stock_id' => $expired->id, 'quantity' => 1]]]))
            ->assertStatus(422)->assertJsonValidationErrors('items');

        $this->assertSame(0, Delivery::count());
        $this->assertSame(10.0, (float) Stock::where('warehouse_id', $this->bag->id)->whereIn('lot_number', ['YAKIN', 'UZAK'])->sum('quantity'));
    }

    public function test_same_idempotency_key_creates_one_delivery(): void
    {
        $token = $this->tokenFor($this->rep);
        $h = ['Idempotency-Key' => 'teslimat-anahtar-0001'];
        $first = $this->withToken($token)->postJson('/api/deliveries', $this->payload(), $h)->assertCreated()->json('delivery.number');
        $second = $this->withToken($token)->postJson('/api/deliveries', $this->payload(), $h)->assertOk()->json('delivery.number');

        $this->assertSame($first, $second);
        $this->assertSame(1, Delivery::count());
        $this->assertSame(2.0, (float) Stock::find($this->stockId('YAKIN'))->quantity);
    }

    public function test_return_and_cancel(): void
    {
        $repToken = $this->tokenFor($this->rep);
        $this->withToken($repToken)->postJson('/api/deliveries', $this->payload(['items' => [
            ['stock_id' => $this->stockId('YAKIN'), 'quantity' => 4],
            ['stock_id' => $this->stockId('UZAK'), 'quantity' => 2],
        ]]))->assertCreated();
        $delivery = Delivery::first();

        // Geri alma: SKT'si yakın lotun 2'si hastaneden araca (imza zorunlu değil)
        $ret = $this->withToken($repToken)->postJson('/api/deliveries', [
            'type' => 'return',
            'hospital_id' => $this->hospital->id, 'location_id' => $this->location->id, 'vehicle_id' => $this->bag->id,
            'receiver_name' => 'Depo görevlisi',
            'items' => [['stock_id' => $this->stockId('YAKIN', $this->location), 'quantity' => 2]],
            'location_error' => 'timeout',
        ])->assertCreated()->json('delivery');
        $this->assertStringStartsWith('GA-', $ret['number']);
        $this->assertSame(2.0, (float) Stock::find($this->stockId('YAKIN', $this->location))->quantity);
        $this->assertSame(3.0, (float) Stock::find($this->stockId('YAKIN'))->quantity);

        // Mümessil iptal edemez
        $this->withToken($repToken)->postJson("/api/deliveries/{$delivery->id}/cancel", ['reason' => 'yanlış'])->assertStatus(403);

        // İlk teslimatın 4 YAKIN'ından 2'si geri alındığı için iptal edilemez
        $mgrToken = $this->tokenFor($this->manager);
        $this->withToken($mgrToken)->postJson("/api/deliveries/{$delivery->id}/cancel", ['reason' => 'yanlış hastane'])
            ->assertStatus(422)->assertJsonValidationErrors('items');

        // Geri alma belgesi iptal edilebilir: ürünler hastaneye döner
        $this->withToken($mgrToken)->postJson("/api/deliveries/{$ret['id']}/cancel", ['reason' => 'yanlışlıkla alındı'])->assertOk();
        $this->assertSame(4.0, (float) Stock::find($this->stockId('YAKIN', $this->location))->quantity);
        $this->assertSame('cancelled', Delivery::find($ret['id'])->status);

        // Şimdi ilk teslimat iptal edilebilir: her şey araca döner
        $this->withToken($mgrToken)->postJson("/api/deliveries/{$delivery->id}/cancel", ['reason' => 'yanlış hastane'])->assertOk();
        $this->assertSame(0.0, (float) Stock::where('warehouse_id', $this->location->id)->sum('quantity'));
        $this->assertSame(10.0, (float) Stock::where('warehouse_id', $this->bag->id)->sum('quantity'));
        $this->withToken($mgrToken)->postJson("/api/deliveries/{$delivery->id}/cancel", ['reason' => 'tekrar'])->assertStatus(422);
    }

    public function test_visibility(): void
    {
        $this->withToken($this->tokenFor($this->rep))->postJson('/api/deliveries', $this->payload())->assertCreated();
        $id = Delivery::first()->id;

        $otherRep = $this->userWithRole('personel');
        $this->withToken($this->tokenFor($otherRep))->getJson("/api/deliveries/{$id}")->assertNotFound();
        $this->withToken($this->tokenFor($otherRep))->getJson('/api/deliveries')->assertOk()->assertJsonPath('total', 0);

        $list = $this->withToken($this->tokenFor($this->manager))->getJson('/api/deliveries')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.lines_count', 1);
        $this->assertEquals(3, $list->json('data.0.units'));
    }

    public function test_serial_item_delivery(): void
    {
        $implant = $this->product(['tracking' => 'serial', 'name' => 'Diz protezi']);
        app(StockService::class)->receive($this->admin(), $this->bag, [
            ['product_id' => $implant->id, 'quantity' => 1, 'serial_number' => 'SN-77', 'lot_number' => 'L1'],
        ]);
        $sid = Stock::where('serial_number', 'SN-77')->value('id');

        $this->withToken($this->tokenFor($this->rep))->postJson('/api/deliveries', $this->payload(['items' => [['stock_id' => $sid, 'quantity' => 1]]]))
            ->assertCreated();
        $this->assertSame($this->location->id, Stock::where('serial_number', 'SN-77')->where('quantity', 1)->value('warehouse_id'));
    }

    public function test_location_is_required_at_the_moment_of_the_operation(): void
    {
        $token = $this->tokenFor($this->rep);
        $noLocation = $this->payload(['latitude' => null, 'longitude' => null]);

        // Ne konum ne de neden: reddedilir, stok değişmez
        $this->withToken($token)->postJson('/api/deliveries', $noLocation)
            ->assertStatus(422)->assertJsonValidationErrors('location_error');
        $this->assertSame(0, Delivery::count());

        // Geçersiz neden reddedilir
        $this->withToken($token)->postJson('/api/deliveries', [...$noLocation, 'location_error' => 'canim-istemedi'])
            ->assertStatus(422)->assertJsonValidationErrors('location_error');

        // Hem konum hem neden gönderilemez
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['location_error' => 'denied']))
            ->assertStatus(422)->assertJsonValidationErrors('location_error');

        // Tek koordinat yetmez
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['longitude' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('longitude');
        $this->assertSame(0, Delivery::count());
    }

    public function test_location_with_accuracy_is_stored_on_document_and_movements(): void
    {
        $this->withToken($this->tokenFor($this->rep))
            ->postJson('/api/deliveries', $this->payload(['location_accuracy' => 23.6]))
            ->assertCreated()->assertJsonPath('delivery.location_status', 'ok');

        $d = Delivery::first();
        $this->assertSame(24, $d->location_accuracy);
        $this->assertNull($d->location_error);
        $this->assertSame(2, StockMovement::where('delivery_id', $d->id)->whereNotNull('latitude')->where('location_accuracy', 24)->count());
    }

    public function test_missing_location_is_marked_and_can_be_listed_separately(): void
    {
        $token = $this->tokenFor($this->rep);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload())->assertCreated();
        $this->withToken($token)->postJson('/api/deliveries', $this->payload([
            'latitude' => null, 'longitude' => null, 'location_error' => 'denied',
            'items' => [['stock_id' => $this->stockId('YAKIN'), 'quantity' => 1]],
        ]))->assertCreated()->assertJsonPath('delivery.location_status', 'missing')
            ->assertJsonPath('delivery.location_error', 'denied');

        $missing = Delivery::where('location_error', 'denied')->first();
        $this->assertNull($missing->latitude);
        $this->assertSame(2, StockMovement::where('delivery_id', $missing->id)->where('location_error', 'denied')->count());

        $this->withToken($token)->getJson('/api/deliveries?location=missing')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $missing->id);
        $this->withToken($token)->getJson('/api/deliveries?location=ok')->assertOk()->assertJsonPath('total', 1);
        $this->withToken($token)->getJson('/api/deliveries')->assertOk()->assertJsonPath('total', 2);
    }

    public function test_cancelling_from_the_office_needs_no_location(): void
    {
        $this->withToken($this->tokenFor($this->rep))->postJson('/api/deliveries', $this->payload())->assertCreated();
        $this->withToken($this->tokenFor($this->manager))
            ->postJson('/api/deliveries/'.Delivery::first()->id.'/cancel', ['reason' => 'Yanlış hastane'])
            ->assertOk();
    }

    private function jpeg(): string
    {
        $img = imagecreatetruecolor(1600, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 110));
        ob_start();
        imagejpeg($img);

        return 'data:image/jpeg;base64,'.base64_encode(ob_get_clean());
    }

    public function test_document_copy_is_emailed_to_the_contact_as_pdf(): void
    {
        $this->contact->update(['email' => 'ayse@kartal.test']);
        $res = $this->withToken($this->tokenFor($this->rep))
            ->postJson('/api/deliveries', $this->payload(['photo' => $this->jpeg()]))->assertCreated();

        $res->assertJsonPath('delivery.email_status', 'sent')->assertJsonPath('delivery.email_to', 'ayse@kartal.test');
        $d = Delivery::first();
        Storage::disk('local')->assertExists($d->photo_path);

        Mail::assertSent(DeliveryDocumentMail::class, function (DeliveryDocumentMail $m) use ($d) {
            $att = $m->attachments()[0];
            $this->assertSame([$d->number.'.pdf', 'application/pdf'], [$att->as, $att->mime]);
            $this->assertStringStartsWith('%PDF', $att->attachWith(fn () => null, fn ($data) => $data()));
            $this->assertStringContainsString($d->number, $m->envelope()->subject);
            $this->assertStringContainsString('Kartal Devlet Hastanesi', $m->envelope()->subject);

            return $m->hasTo('ayse@kartal.test');
        });
        $this->assertNotNull($d->emailed_at);
    }

    public function test_typed_email_is_used_and_saved_on_contact_without_email(): void
    {
        $this->withToken($this->tokenFor($this->rep))
            ->postJson('/api/deliveries', $this->payload(['receiver_email' => ' Ayse@Kartal.test ']))
            ->assertCreated()->assertJsonPath('delivery.email_to', 'ayse@kartal.test');

        Mail::assertSent(DeliveryDocumentMail::class, fn ($m) => $m->hasTo('ayse@kartal.test'));
        $this->assertSame('ayse@kartal.test', $this->contact->fresh()->email);

        // Geçersiz adres kaydı engeller
        $this->withToken($this->tokenFor($this->rep))
            ->postJson('/api/deliveries', $this->payload(['receiver_email' => 'yanlis-adres']))
            ->assertStatus(422)->assertJsonValidationErrors('receiver_email');
    }

    public function test_no_email_means_nothing_is_sent(): void
    {
        $this->withToken($this->tokenFor($this->rep))->postJson('/api/deliveries', $this->payload())
            ->assertCreated()->assertJsonPath('delivery.email_status', null);
        Mail::assertNothingSent();
    }

    public function test_mail_failure_does_not_lose_the_delivery(): void
    {
        $this->contact->update(['email' => 'ayse@kartal.test']);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP sunucusuna bağlanılamadı'));

        $this->withToken($this->tokenFor($this->rep))->postJson('/api/deliveries', $this->payload())
            ->assertCreated()
            ->assertJsonPath('delivery.email_status', 'failed')
            ->assertJsonPath('delivery.email_error', 'SMTP sunucusuna bağlanılamadı');
        $this->assertSame(1, Delivery::count());
        $this->assertSame(2.0, (float) Stock::find($this->stockId('YAKIN'))->quantity);
    }

    public function test_pdf_download_and_resend(): void
    {
        $token = $this->tokenFor($this->rep);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload(['photo' => $this->jpeg(), 'note' => 'Dolap A üst raf']))->assertCreated();
        $d = Delivery::first();

        $pdf = $this->withToken($token)->get("/api/deliveries/{$d->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Adres yoksa yazılması istenir
        $this->withToken($token)->postJson("/api/deliveries/{$d->id}/email")->assertStatus(422)->assertJsonValidationErrors('email');
        $this->withToken($token)->postJson("/api/deliveries/{$d->id}/email", ['email' => 'satinalma@kartal.test'])
            ->assertOk()->assertJsonPath('delivery.email_status', 'sent');
        Mail::assertSent(DeliveryDocumentMail::class, fn ($m) => $m->hasTo('satinalma@kartal.test'));

        // Başka personel göremez, gönderemez
        $other = $this->userWithRole('personel');
        $this->withToken($this->tokenFor($other))->get("/api/deliveries/{$d->id}/pdf")->assertNotFound();
        $this->withToken($this->tokenFor($other))->postJson("/api/deliveries/{$d->id}/email", ['email' => 'x@y.test'])->assertNotFound();

        // İptal edilen belge PDF'te görünür ama gönderilmez
        $this->withToken($this->tokenFor($this->manager))->postJson("/api/deliveries/{$d->id}/cancel", ['reason' => 'Yanlış lokasyon'])->assertOk();
        $this->withToken($token)->get("/api/deliveries/{$d->id}/pdf")->assertOk();
        $this->withToken($token)->postJson("/api/deliveries/{$d->id}/email", ['email' => 'satinalma@kartal.test'])->assertStatus(422);
    }

    public function test_return_document_is_also_emailed(): void
    {
        $this->contact->update(['email' => 'ayse@kartal.test']);
        $token = $this->tokenFor($this->rep);
        $this->withToken($token)->postJson('/api/deliveries', $this->payload())->assertCreated();
        $there = Stock::where('warehouse_id', $this->location->id)->value('id');

        $res = $this->withToken($token)->postJson('/api/deliveries', $this->payload([
            'type' => 'return', 'signature' => null, 'photo' => $this->jpeg(),
            'items' => [['stock_id' => $there, 'quantity' => 1]],
        ]))->assertCreated();
        $this->assertStringStartsWith('GA-', $res->json('delivery.number'));
        Mail::assertSent(DeliveryDocumentMail::class, 2);
        Mail::assertSent(DeliveryDocumentMail::class, fn ($m) => str_starts_with($m->envelope()->subject, 'Geri alma belgesi'));
    }
}
