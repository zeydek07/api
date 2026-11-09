<?php

namespace Tests\Feature;

use App\Mail\CaseDocumentMail;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\SurgeryCase;
use App\Models\SurgeryCaseItem;
use App\Services\StockService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

class CaseTest extends ApiTestCase
{
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private $rep;

    private $manager;

    private $bag;

    private Hospital $hospital;

    private $shelf;

    private $screw;

    private $plate;

    private HospitalContact $nurse;

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
        $this->shelf = $this->hospital->locations()->first();
        $this->nurse = $this->hospital->contacts()->create(['name' => 'Ayşe Demir', 'title' => 'Ameliyathane hemşiresi', 'email' => 'ayse@kartal.test']);
        $this->screw = $this->product(['tracking' => 'serial', 'name' => 'Vida 4.5x40']);
        $this->plate = $this->product(['tracking' => 'lot', 'name' => 'Plak 6 delik']);

        $stock = app(StockService::class);
        // Hastane dolabında 2 seri numaralı vida ve 3 plak, araçta 2 plak
        $stock->receive($this->admin(), $this->bag, [
            ['product_id' => $this->screw->id, 'quantity' => 1, 'serial_number' => 'SN-1'],
            ['product_id' => $this->screw->id, 'quantity' => 1, 'serial_number' => 'SN-2'],
            ['product_id' => $this->plate->id, 'quantity' => 5, 'lot_number' => 'P-1', 'expiry_date' => now()->addYear()->toDateString()],
        ]);
        $stock->transfer($this->admin(), $this->bag, $this->shelf, [
            ['product_id' => $this->screw->id, 'quantity' => 1, 'serial_number' => 'SN-1'],
            ['product_id' => $this->screw->id, 'quantity' => 1, 'serial_number' => 'SN-2'],
            ['product_id' => $this->plate->id, 'quantity' => 3, 'lot_number' => 'P-1'],
        ]);
    }

    private function stockId($warehouse, array $where): int
    {
        return Stock::where('warehouse_id', $warehouse->id)->where($where)->value('id');
    }

    private function plan(array $override = [], $user = null): SurgeryCase
    {
        $res = $this->withToken($this->tokenFor($user ?? $this->rep))->postJson('/api/cases', [
            'hospital_id' => $this->hospital->id,
            'scheduled_at' => now()->addHour()->toIso8601String(),
            'procedure' => 'Diz protezi',
            ...$override,
        ])->assertCreated();

        return SurgeryCase::findOrFail($res->json('case.id'));
    }

    private function recordPayload(array $override = []): array
    {
        return [
            'contact_id' => $this->nurse->id,
            'protocol_no' => '2026-118734',
            'signature' => self::PNG,
            'latitude' => 40.9, 'longitude' => 29.18,
            'items' => [
                ['stock_id' => $this->stockId($this->shelf, ['serial_number' => 'SN-1']), 'quantity' => 1, 'status' => 'used'],
                ['stock_id' => $this->stockId($this->shelf, ['serial_number' => 'SN-2']), 'quantity' => 1, 'status' => 'unused'],
                ['stock_id' => $this->stockId($this->shelf, ['lot_number' => 'P-1']), 'quantity' => 1, 'status' => 'opened'],
                ['stock_id' => $this->stockId($this->bag, ['lot_number' => 'P-1']), 'quantity' => 1, 'status' => 'used'],
            ],
            ...$override,
        ];
    }

    public function test_rep_plans_a_case_and_manager_can_plan_for_rep(): void
    {
        $doctor = $this->hospital->doctors()->create(['name' => 'Ahmet Kaya', 'title' => 'Op. Dr.', 'branch' => 'Ortopedi']);
        $case = $this->plan(['doctor_id' => $doctor->id, 'location_id' => $this->shelf->id]);

        $this->assertMatchesRegularExpression('/^VK-\d{4}-\d{6}$/', $case->number);
        $this->assertSame([SurgeryCase::PLANNED, $this->rep->id], [$case->status, $case->user_id]);

        // Saat dilimsiz saat Türkiye saatidir, UTC tutulur
        $tr = $this->plan(['scheduled_at' => '2030-01-15 09:30']);
        $this->assertSame('2030-01-15 06:30', $tr->scheduled_at->utc()->format('Y-m-d H:i'));

        // Yönetici başka personel adına planlar
        $other = $this->plan(['user_id' => $this->rep->id], $this->manager);
        $this->assertSame([$this->rep->id, $this->manager->id], [$other->user_id, $other->created_by]);

        // Personel başkası adına planlayamaz
        $colleague = $this->userWithRole('personel');
        $this->withToken($this->tokenFor($this->rep))->postJson('/api/cases', [
            'hospital_id' => $this->hospital->id, 'scheduled_at' => now()->addDay()->toIso8601String(), 'user_id' => $colleague->id,
        ])->assertForbidden();

        // Başka hastanenin doktoru seçilemez
        $elsewhere = Hospital::create(['name' => 'Başka Hastane'])->doctors()->create(['name' => 'Dr. Başka']);
        $this->withToken($this->tokenFor($this->rep))->postJson('/api/cases', [
            'hospital_id' => $this->hospital->id, 'scheduled_at' => now()->addDay()->toIso8601String(), 'doctor_id' => $elsewhere->id,
        ])->assertStatus(422)->assertJsonValidationErrors('doctor_id');
    }

    public function test_recording_deducts_used_and_opened_from_hospital_and_vehicle_and_emails_the_report(): void
    {
        $case = $this->plan();
        $res = $this->withToken($this->tokenFor($this->rep))
            ->postJson("/api/cases/{$case->id}/record", $this->recordPayload(), ['Idempotency-Key' => 'vaka-anahtar-1'])
            ->assertCreated()
            ->assertJsonPath('case.status', 'recorded')
            ->assertJsonPath('case.signer_name', 'Ayşe Demir')
            ->assertJsonPath('case.email_status', 'sent');
        $this->assertCount(4, $res->json('case.items'));

        // Kullanılan vida dolaptan çıktı, kullanılmayan yerinde
        $this->assertEquals(0, Stock::find($this->stockId($this->shelf, ['serial_number' => 'SN-1']))->quantity);
        $this->assertEquals(1, Stock::find($this->stockId($this->shelf, ['serial_number' => 'SN-2']))->quantity);
        // Açılan plak dolaptan, kullanılan plak araçtan düştü
        $this->assertEquals(2, Stock::find($this->stockId($this->shelf, ['lot_number' => 'P-1']))->quantity);
        $this->assertEquals(1, Stock::find($this->stockId($this->bag, ['lot_number' => 'P-1']))->quantity);

        $moves = StockMovement::where('surgery_case_id', $case->id)->get();
        $this->assertCount(3, $moves);
        $this->assertTrue($moves->every(fn ($m) => $m->type === StockMovement::TYPE_CASE_USE && $m->reference === $case->number));

        $item = SurgeryCaseItem::where('surgery_case_id', $case->id)->where('serial_number', 'SN-1')->first();
        $this->assertSame([SurgeryCaseItem::USED, $this->shelf->id], [$item->status, $item->warehouse_id]);

        Mail::assertSent(CaseDocumentMail::class, fn ($m) => $m->hasTo('ayse@kartal.test') && str_contains($m->envelope()->subject, $case->number));
        Storage::disk('local')->assertExists($case->fresh()->signature_path);

        // Aynı anahtarla tekrar: ikinci düşüm yok
        $this->withToken($this->tokenFor($this->rep))
            ->postJson("/api/cases/{$case->id}/record", $this->recordPayload(), ['Idempotency-Key' => 'vaka-anahtar-1'])
            ->assertOk();
        $this->assertCount(3, StockMovement::where('surgery_case_id', $case->id)->get());

        // Farklı anahtarla tekrar kayıt: reddedilir
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$case->id}/record", $this->recordPayload())
            ->assertStatus(422)->assertJsonValidationErrors('status');

        // PDF tutanağı
        $pdf = $this->withToken($this->tokenFor($this->rep))->get("/api/cases/{$case->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_record_rules(): void
    {
        $case = $this->plan();
        $token = $this->tokenFor($this->rep);

        $this->withToken($token)->postJson("/api/cases/{$case->id}/record", $this->recordPayload(['signature' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->withToken($token)->postJson("/api/cases/{$case->id}/record", $this->recordPayload(['contact_id' => null]))
            ->assertStatus(422)->assertJsonValidationErrors('contact_id');

        // Toplam miktar mevcut miktarı aşamaz (aynı satırdan 3 kullanıldı + 1 kullanılmadı)
        $plates = $this->stockId($this->shelf, ['lot_number' => 'P-1']);
        $this->withToken($token)->postJson("/api/cases/{$case->id}/record", $this->recordPayload(['items' => [
            ['stock_id' => $plates, 'quantity' => 3, 'status' => 'used'],
            ['stock_id' => $plates, 'quantity' => 1, 'status' => 'unused'],
        ]]))->assertStatus(422)->assertJsonValidationErrors('items');

        // Başka hastanenin dolabındaki ürün kullanılamaz
        $other = Hospital::create(['name' => 'Başka Hastane']);
        app(StockService::class)->transfer($this->admin(), $this->bag, $other->locations()->first(), [
            ['product_id' => $this->plate->id, 'quantity' => 1, 'lot_number' => 'P-1'],
        ]);
        $this->withToken($token)->postJson("/api/cases/{$case->id}/record", $this->recordPayload(['items' => [
            ['stock_id' => $this->stockId($other->locations()->first(), ['lot_number' => 'P-1']), 'quantity' => 1, 'status' => 'used'],
        ]]))->assertStatus(422)->assertJsonValidationErrors('items');

        // Başka personelin vakası kaydedilemez (göremez)
        $colleague = $this->userWithRole('personel');
        $this->withToken($this->tokenFor($colleague))->postJson("/api/cases/{$case->id}/record", $this->recordPayload())->assertNotFound();

        // Hiçbiri stok değiştirmedi
        $this->assertSame(0, StockMovement::whereNotNull('surgery_case_id')->count());
    }

    public function test_approve_invoice_and_uninvoice(): void
    {
        $case = $this->plan();
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$case->id}/record", $this->recordPayload())->assertCreated();
        $mgr = $this->tokenFor($this->manager);

        // Onaylanmadan faturalanacaklarda görünmez
        $this->withToken($mgr)->getJson('/api/billing/items')->assertOk()->assertJsonPath('summary.lines', 0);
        // Personel onaylayamaz
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$case->id}/approve")->assertForbidden();

        $this->withToken($mgr)->postJson("/api/cases/{$case->id}/approve")->assertOk()->assertJsonPath('case.status', 'approved');
        $pending = $this->withToken($mgr)->getJson('/api/billing/items')->assertOk();
        $this->assertSame(3, $pending->json('summary.lines'));   // kullanılmayan satır yok
        $ids = collect($pending->json('items'))->pluck('id')->all();

        // Kullanılmayan satır faturalanamaz
        $unused = SurgeryCaseItem::where('status', 'unused')->value('id');
        $this->withToken($mgr)->postJson('/api/billing/invoice', ['item_ids' => [$unused], 'invoice_number' => 'F1', 'invoice_date' => today()->toDateString()])
            ->assertStatus(422);

        // İkisini faturala (kısmi)
        $this->withToken($mgr)->postJson('/api/billing/invoice', [
            'item_ids' => array_slice($ids, 0, 2), 'invoice_number' => 'KRT2026000123', 'invoice_date' => today()->toDateString(),
        ])->assertOk();
        $this->withToken($mgr)->getJson('/api/billing/items')->assertJsonPath('summary.lines', 1);
        $this->withToken($mgr)->getJson('/api/billing/items?status=invoiced')->assertJsonPath('summary.lines', 2)
            ->assertJsonPath('items.0.invoice_number', 'KRT2026000123');
        $this->withToken($mgr)->getJson('/api/cases?billing=pending')->assertJsonPath('total', 1);

        // Aynı satır iki kez faturalanamaz
        $this->withToken($mgr)->postJson('/api/billing/invoice', ['item_ids' => [$ids[0]], 'invoice_number' => 'F2', 'invoice_date' => today()->toDateString()])
            ->assertStatus(422);

        // Faturalanmış vakada iptal yok
        $this->withToken($mgr)->postJson("/api/cases/{$case->id}/cancel", ['reason' => 'Deneme'])->assertStatus(422);

        // Geri al: tekrar faturalanacaklarda
        $this->withToken($mgr)->postJson('/api/billing/uninvoice', ['item_ids' => array_slice($ids, 0, 2)])->assertOk();
        $this->withToken($mgr)->getJson('/api/billing/items')->assertJsonPath('summary.lines', 3);

        // Personel faturalamayı göremez
        $this->withToken($this->tokenFor($this->rep))->getJson('/api/billing/items')->assertForbidden();
    }

    public function test_cancel_restores_stock_and_planned_cases_can_be_cancelled_by_owner(): void
    {
        $planned = $this->plan();
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$planned->id}/cancel", ['reason' => 'Ameliyat ertelendi'])
            ->assertOk()->assertJsonPath('case.status', 'cancelled');

        $case = $this->plan();
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$case->id}/record", $this->recordPayload())->assertCreated();

        // Kaydedilmiş vakayı personel iptal edemez
        $this->withToken($this->tokenFor($this->rep))->postJson("/api/cases/{$case->id}/cancel", ['reason' => 'Yanlış'])->assertForbidden();

        $this->withToken($this->tokenFor($this->manager))->postJson("/api/cases/{$case->id}/cancel", ['reason' => 'Yanlış hastane'])->assertOk();
        $this->assertEquals(1, Stock::find($this->stockId($this->shelf, ['serial_number' => 'SN-1']))->quantity);
        $this->assertEquals(3, Stock::find($this->stockId($this->shelf, ['lot_number' => 'P-1']))->quantity);
        $this->assertEquals(2, Stock::find($this->stockId($this->bag, ['lot_number' => 'P-1']))->quantity);
        $this->assertSame(3, StockMovement::where('surgery_case_id', $case->id)->where('type', StockMovement::TYPE_CASE_CANCEL)->count());
    }

    public function test_lists_visibility_and_mobile_home(): void
    {
        $today = $this->plan(['scheduled_at' => now()->addHour()->toIso8601String()]);
        $overdue = $this->plan(['scheduled_at' => now()->subDay()->toIso8601String()]);
        $this->plan(['scheduled_at' => now()->addDays(3)->toIso8601String()]);
        $token = $this->tokenFor($this->rep);

        $this->withToken($token)->getJson('/api/cases?scope=today')->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $today->id);
        $this->withToken($token)->getJson('/api/cases?scope=upcoming')->assertJsonPath('total', 1);
        $this->withToken($token)->getJson('/api/cases?scope=overdue')->assertJsonPath('data.0.id', $overdue->id)
            ->assertJsonPath('data.0.is_overdue', true);

        // Başka personel görmez; yönetici görür
        $this->withToken($this->tokenFor($this->userWithRole('personel')))->getJson('/api/cases')->assertJsonPath('total', 0);
        $this->withToken($this->tokenFor($this->manager))->getJson('/api/cases')->assertJsonPath('total', 3);

        // Ana sayfa: SKT'si yaklaşan ürün araçta
        app(StockService::class)->receive($this->admin(), $this->bag, [
            ['product_id' => $this->plate->id, 'quantity' => 2, 'lot_number' => 'YAKIN', 'expiry_date' => now()->addDays(10)->toDateString()],
        ]);
        $home = $this->withToken($token)->getJson('/api/mobile/home')->assertOk();
        $home->assertJsonPath('counts.today', 1)->assertJsonPath('counts.overdue', 1)->assertJsonPath('counts.upcoming', 1);
        $home->assertJsonPath('alerts.0.level', 'critical')->assertJsonPath('alerts.0.scope', 'vehicle')->assertJsonPath('alerts.0.units', 2);
    }

    public function test_doctors(): void
    {
        $token = $this->tokenFor($this->rep);
        // Saha personeli vaka sırasında doktor ekleyebilir
        $this->withToken($token)->postJson("/api/hospitals/{$this->hospital->id}/doctors", [
            'name' => 'Ahmet Kaya', 'title' => 'Op. Dr.', 'branch' => 'Ortopedi',
        ])->assertCreated()->assertJsonPath('display_name', 'Op. Dr. Ahmet Kaya');
        $this->withToken($token)->postJson("/api/hospitals/{$this->hospital->id}/doctors", ['name' => 'Ahmet Kaya'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->withToken($token)->getJson("/api/hospitals/{$this->hospital->id}/doctors")->assertOk()->assertJsonCount(1);

        // Düzenleme hastane yetkisi ister
        $id = $this->hospital->doctors()->value('id');
        $this->withToken($token)->putJson("/api/hospitals/{$this->hospital->id}/doctors/{$id}", ['branch' => 'Travmatoloji'])->assertForbidden();
        $this->withToken($this->tokenFor($this->manager))->putJson("/api/hospitals/{$this->hospital->id}/doctors/{$id}", ['branch' => 'Travmatoloji'])
            ->assertOk()->assertJsonPath('branch', 'Travmatoloji');
    }
}
