<?php

namespace Database\Seeders;

use App\Models\Hospital;
use App\Models\Product;
use App\Models\Role;
use App\Models\Stock;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DeliveryService;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Panelde denemek için örnek veri. Canlı sistemde ÇALIŞTIRMAYIN.
 *   php artisan db:seed --class=DemoSeeder
 * Tekrar çalıştırılırsa ikinci kez eklemez.
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Demo1234!';

    /** Örnek teslimatlarda kullanılan imza görseli (küçük bir PNG) */
    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private const PRODUCTS = [
        // [ad, kategori, marka, birim, minimum]
        ['Steril Gazlı Bez 10x10 cm', 'Pansuman', 'MedTekstil', 'paket', 40],
        ['Steril Gazlı Bez 7.5x7.5 cm', 'Pansuman', 'MedTekstil', 'paket', 40],
        ['Elastik Bandaj 10 cm', 'Pansuman', 'MedTekstil', 'adet', 30],
        ['Hidrokolloid Yara Örtüsü 10x10', 'Yara Bakımı', 'DermaCare', 'adet', 25],
        ['Gümüşlü Köpük Yara Örtüsü 15x15', 'Yara Bakımı', 'DermaCare', 'adet', 15],
        ['Şeffaf Film Örtü 10x12', 'Yara Bakımı', 'DermaCare', 'adet', 30],
        ['Enjektör 2 ml', 'Enjeksiyon', 'SafeInject', 'kutu', 20],
        ['Enjektör 5 ml', 'Enjeksiyon', 'SafeInject', 'kutu', 20],
        ['Enjektör 10 ml', 'Enjeksiyon', 'SafeInject', 'kutu', 15],
        ['İnsülin Enjektörü 1 ml', 'Enjeksiyon', 'SafeInject', 'kutu', 15],
        ['İ.V. Kanül 18G', 'Damar Yolu', 'VenaFlow', 'kutu', 10],
        ['İ.V. Kanül 20G', 'Damar Yolu', 'VenaFlow', 'kutu', 10],
        ['İ.V. Kanül 22G', 'Damar Yolu', 'VenaFlow', 'kutu', 10],
        ['Serum Seti', 'Damar Yolu', 'VenaFlow', 'adet', 50],
        ['Üç Yollu Musluk', 'Damar Yolu', 'VenaFlow', 'adet', 40],
        ['Foley Sonda 16 Fr', 'Üroloji', 'UroMed', 'adet', 20],
        ['Foley Sonda 18 Fr', 'Üroloji', 'UroMed', 'adet', 20],
        ['İdrar Torbası 2000 ml', 'Üroloji', 'UroMed', 'adet', 30],
        ['Nelaton Sonda 14 Fr', 'Üroloji', 'UroMed', 'adet', 20],
        ['Nitril Muayene Eldiveni (M)', 'Koruyucu Ekipman', 'ProGuard', 'kutu', 30],
        ['Nitril Muayene Eldiveni (L)', 'Koruyucu Ekipman', 'ProGuard', 'kutu', 30],
        ['Steril Cerrahi Eldiven 7.5', 'Koruyucu Ekipman', 'ProGuard', 'kutu', 15],
        ['Cerrahi Maske 3 Katlı', 'Koruyucu Ekipman', 'ProGuard', 'kutu', 25],
        ['FFP2 Maske', 'Koruyucu Ekipman', 'ProGuard', 'kutu', 10],
        ['Emilebilir Sütür 3/0', 'Cerrahi', 'SutureLine', 'kutu', 8],
        ['Emilemeyen Sütür 4/0', 'Cerrahi', 'SutureLine', 'kutu', 8],
        ['Bistüri Ucu No:11', 'Cerrahi', 'SutureLine', 'kutu', 10],
        ['Cilt Stapleri 35W', 'Cerrahi', 'SutureLine', 'adet', 10],
        ['Alkollü Pamuk Ped', 'Antiseptik', 'CleanMed', 'kutu', 30],
        ['Povidon İyot Çözeltisi 1 L', 'Antiseptik', 'CleanMed', 'şişe', 12],
    ];

    private const HOSPITALS = [
        // [ad, tür, il, ilçe]
        ['Kartal Devlet Hastanesi', 'public', 'İstanbul', 'Kartal'],
        ['Bakırköy Eğitim ve Araştırma Hastanesi', 'public', 'İstanbul', 'Bakırköy'],
        ['Özel Boğaziçi Tıp Merkezi', 'private', 'İstanbul', 'Beşiktaş'],
        ['Üsküdar Şehir Hastanesi', 'public', 'İstanbul', 'Üsküdar'],
        ['Çankaya Devlet Hastanesi', 'public', 'Ankara', 'Çankaya'],
        ['Özel Başkent Cerrahi Merkezi', 'private', 'Ankara', 'Yenimahalle'],
        ['Kızılay Üniversite Hastanesi', 'university', 'Ankara', 'Çankaya'],
        ['Karşıyaka Devlet Hastanesi', 'public', 'İzmir', 'Karşıyaka'],
        ['Özel Ege Yaşam Hastanesi', 'private', 'İzmir', 'Bornova'],
        ['Bornova Üniversite Hastanesi', 'university', 'İzmir', 'Bornova'],
    ];

    private const STOREKEEPERS = [
        ['Mehmet Yılmaz', 'depocu1@medikal.test', '0532 100 10 01'],
        ['Fatma Öztürk', 'depocu2@medikal.test', '0532 100 10 02'],
        ['Ali Kara', 'depocu3@medikal.test', '0532 100 10 03'],
    ];

    private const REPRESENTATIVES = [
        ['Can Aksoy', 'mumessil1@medikal.test', '0533 200 20 01', 'İstanbul'],
        ['Seda Polat', 'mumessil2@medikal.test', '0533 200 20 02', 'Ankara'],
        ['Kerem Doğan', 'mumessil3@medikal.test', '0533 200 20 03', 'İzmir'],
    ];

    public function run(StockService $stock): void
    {
        if (User::withTrashed()->where('email', self::STOREKEEPERS[0][1])->exists()) {
            $this->command?->warn('Örnek veri zaten yüklü, atlandı.');

            return;
        }

        $this->call(DatabaseSeeder::class); // roller ve yetkiler güncel olsun
        mt_srand(2026); // her çalıştırmada aynı veri

        $central = Warehouse::firstOrCreate(['code' => 'MERKEZ'], ['name' => 'Merkez Depo', 'type' => 'central']);
        $manager = User::where('email', env('ADMIN_EMAIL', 'admin@example.com'))->first();

        // Ürünler
        $products = collect(self::PRODUCTS)->map(fn ($p, $i) => Product::firstOrCreate(
            ['code' => sprintf('MED-%03d', $i + 1)],
            [
                'name' => $p[0], 'category' => $p[1], 'brand' => $p[2], 'unit' => $p[3],
                'min_stock_level' => $p[4], 'barcode' => $barcode = '8690'.str_pad((string) (100000000 + $i * 7919), 9, '0', STR_PAD_LEFT),
                'gtin' => '0'.$barcode, // karekoddaki 14 haneli ürün numarası
                'tracking' => Product::TRACKING_LOT,
                'description' => "{$p[1]} grubunda örnek ürün.",
            ],
        ));

        // Hastaneler
        $hospitals = collect(self::HOSPITALS)->map(fn ($h, $i) => Hospital::firstOrCreate(
            ['code' => sprintf('HST-%02d', $i + 1)],
            [
                'name' => $h[0], 'type' => $h[1], 'city' => $h[2], 'district' => $h[3],
                'address' => "{$h[3]}, {$h[2]}",
            ],
        ));

        // Depocular
        $storeRole = Role::where('slug', 'depocu')->value('id');
        $keepers = collect(self::STOREKEEPERS)->map(function ($u) use ($storeRole, $manager, $central) {
            $user = User::create([
                'name' => $u[0], 'email' => $u[1], 'phone' => $u[2], 'password' => self::PASSWORD,
                'role_id' => $storeRole, 'manager_id' => $manager?->id,
            ]);
            $user->warehouses()->attach($central);

            return $user;
        });

        // Mümessiller ve çantaları
        $repRole = Role::where('slug', 'mumessil')->value('id');
        $reps = collect(self::REPRESENTATIVES)->map(function ($u, $i) use ($repRole, $manager) {
            $user = User::create([
                'name' => $u[0], 'email' => $u[1], 'phone' => $u[2], 'password' => self::PASSWORD,
                'role_id' => $repRole, 'manager_id' => $manager?->id,
            ]);
            $bag = Warehouse::create([
                'name' => "{$u[0]} - Araç", 'code' => sprintf('ARAC-%02d', $i + 1), 'type' => 'field',
                'address' => "{$u[3]} bölgesi",
            ]);
            $bag->users()->attach($user);

            return ['user' => $user, 'bag' => $bag, 'city' => $u[3]];
        });

        try {
            // 1) 21 gün önce: depocular merkez depoya ürün girişi yapar
            $this->at(21, 9, function () use ($stock, $keepers, $central, $products) {
                foreach ($products->chunk(10)->values() as $i => $chunk) {
                    $stock->receive($keepers[$i % 3], $central, $chunk->map(fn ($p) => [
                        'product_id' => $p->id,
                        'quantity' => $p->min_stock_level * mt_rand(5, 9),
                        'lot_number' => 'LT'.mt_rand(24000, 26999),
                        'expiry_date' => now()->addMonths(mt_rand(10, 36))->toDateString(),
                    ])->values()->all(), ['reference' => 'FTR-2026-'.(1040 + $i), 'note' => 'Aylık tedarik']);
                }

                // Bazı ürünlerde SKT'si yakın küçük bir lot da var (SKT uyarıları ve "yakın olan önce" kuralı görünsün)
                $stock->receive($keepers[0], $central, $products->only([0, 6, 10, 13, 19, 28])->map(fn ($p) => [
                    'product_id' => $p->id,
                    'quantity' => max(3, intdiv($p->min_stock_level, 2)),
                    'lot_number' => 'KS'.mt_rand(1000, 9999),
                    'expiry_date' => now()->addDays(21 + mt_rand(14, 90))->toDateString(),
                ])->values()->all(), ['reference' => 'FTR-2026-1039', 'note' => 'Kısa miadlı parti']);
            });

            // 2) 20 ve 10 gün önce: mümessillerin araçlarına transfer
            foreach ([20, 10] as $round => $daysAgo) {
                $this->at($daysAgo, 10, function () use ($stock, $keepers, $central, $products, $reps, $round) {
                    foreach ($reps as $i => $rep) {
                        $items = $products->shuffle()->take(12)->map(fn ($p) => [
                            'product_id' => $p->id,
                            'quantity' => max(2, intdiv($p->min_stock_level, 2) + mt_rand(0, 6)),
                        ])->values()->all();
                        $stock->transfer($keepers[($i + $round) % 3], $central, $rep['bag'], $this->fit($central, $items),
                            ['note' => ($round ? 'Ara takviye' : 'Haftalık araç yüklemesi')]);
                    }
                });
            }

            // 3) 2 gün önce: sayımda bazı ürünlerde eksik/hasar çıkar (kritik stok örneği)
            $this->at(2, 17, function () use ($stock, $keepers, $central, $products) {
                foreach ($products->only([4, 10, 24]) as $p) {
                    $stock->adjust($keepers[0], $central, $p->id, intdiv($p->min_stock_level, 4), [
                        'note' => 'Aylık sayım: hasarlı ambalaj imhası',
                    ]);
                }
            });

            // 4) Son 18 gün: mümessiller kendi bölgelerindeki hastanelere teslim eder
            for ($daysAgo = 18; $daysAgo >= 0; $daysAgo--) {
                $day = now()->subDays($daysAgo);
                if ($day->isSunday()) {
                    continue;
                }
                foreach ($reps as $rep) {
                    $regional = $hospitals->where('city', $rep['city'])->values();
                    $visits = mt_rand(0, 3);
                    for ($v = 0; $v < $visits; $v++) {
                        $this->at($daysAgo, 9 + $v * 2 + mt_rand(0, 1), function () use ($stock, $rep, $regional) {
                            $available = Stock::where('warehouse_id', $rep['bag']->id)->where('quantity', '>=', 1)->get();
                            if ($available->isEmpty()) {
                                return;
                            }
                            $items = $available->shuffle()->take(mt_rand(1, 3))->map(fn ($s) => [
                                'product_id' => $s->product_id,
                                'quantity' => min((int) $s->quantity, mt_rand(1, 4)),
                            ])->values()->all();
                            $hospital = $regional->random();
                            $stock->recordUsage($rep['user'], $rep['bag'], $items, [
                                'hospital_id' => $hospital->id,
                                'reference' => 'Teslim fişi '.mt_rand(10000, 99999),
                                'note' => mt_rand(0, 2) ? null : 'Hastane deposuna teslim edildi',
                            ]);
                        }, mt_rand(0, 59));
                    }
                }
            }

            // 5) Konsinye: sorumlu mümessil, hastane personeli, son 10 günde imzalı teslimatlar ve bir geri alma
            $deliveries = app(DeliveryService::class);
            $staff = [['Sorumlu hemşire', ['Ayşe Demir', 'Elif Şahin', 'Zeynep Kurt', 'Selin Arslan', 'Deniz Koç']],
                ['Depo görevlisi', ['Murat Kaya', 'Hakan Yıldız', 'Burak Çelik', 'Gökhan Aydın', 'Emre Öztürk']]];
            foreach ($hospitals as $i => $hospital) {
                $rep = $reps->firstWhere('city', $hospital->city);
                $hospital->update(['responsible_user_id' => $rep['user']->id]);
                foreach ($staff as [$title, $names]) {
                    $hospital->contacts()->create([
                        'name' => $names[$i % count($names)], 'title' => $title,
                        'phone' => sprintf('0532 4%02d %02d %02d', $i, mt_rand(10, 99), mt_rand(10, 99)),
                        'location_id' => $hospital->locations()->value('id'),
                    ]);
                }
            }

            foreach ([9, 7, 5, 3, 1] as $daysAgo) {
                foreach ($reps as $rep) {
                    $this->at($daysAgo, 10 + mt_rand(0, 6), function () use ($deliveries, $rep, $hospitals) {
                        $hospital = $hospitals->where('city', $rep['city'])->random();
                        $rows = Stock::where('warehouse_id', $rep['bag']->id)->where('quantity', '>=', 1)
                            ->whereDate('expiry_date', '>=', now())->get()->shuffle()->take(mt_rand(2, 4));
                        if ($rows->isEmpty()) {
                            return;
                        }
                        $contact = $hospital->contacts()->inRandomOrder()->first();
                        $deliveries->create($rep['user'], [
                            'type' => 'delivery',
                            'hospital_id' => $hospital->id,
                            'location_id' => $hospital->locations()->value('id'),
                            'vehicle_id' => $rep['bag']->id,
                            'contact_id' => $contact->id,
                            'items' => $rows->map(fn ($s) => ['stock_id' => $s->id, 'quantity' => min((int) $s->quantity, mt_rand(1, 3))])->values()->all(),
                            'signature' => self::SIGNATURE,
                            'note' => mt_rand(0, 2) ? null : 'Konsinye dolabına yerleştirildi',
                        ]);
                    }, mt_rand(0, 59));
                }
            }

            // Bir mümessil, SKT'si yaklaşan bir ürünü hastaneden geri alır
            $this->at(0, 9, function () use ($deliveries, $reps) {
                $rep = $reps->first();
                $row = Stock::query()->whereHas('warehouse', fn ($w) => $w->where('type', 'consignment'))
                    ->whereIn('warehouse_id', Warehouse::whereIn('hospital_id', Hospital::where('city', $rep['city'])->pluck('id'))->pluck('id'))
                    ->where('quantity', '>', 0)->orderBy('expiry_date')->first();
                if ($row) {
                    $loc = Warehouse::find($row->warehouse_id);
                    $deliveries->create($rep['user'], [
                        'type' => 'return', 'hospital_id' => $loc->hospital_id, 'location_id' => $loc->id, 'vehicle_id' => $rep['bag']->id,
                        'receiver_name' => 'Depo görevlisi',
                        'items' => [['stock_id' => $row->id, 'quantity' => (float) $row->quantity]],
                        'note' => "SKT'si yaklaştığı için geri alındı",
                    ]);
                }
            });
        } finally {
            Carbon::setTestNow();
        }

        $this->command?->info('Örnek veri yüklendi: 30 ürün, 10 hastane, 3 depocu, 3 mümessil.');
        $this->command?->warn('Örnek kullanıcıların şifresi: '.self::PASSWORD);
    }

    /** İşlemi geçmiş bir tarihte yapılmış gibi kaydeder (kayıt tarihleri o güne yazılır). */
    private function at(int $daysAgo, int $hour, callable $fn, int $minute = 0): void
    {
        Carbon::setTestNow(Carbon::now('UTC')->startOfDay()->subDays($daysAgo)->setTime($hour, $minute));
        try {
            $fn();
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Kaynak depoda olandan fazlasını istemesin */
    private function fit(Warehouse $from, array $items): array
    {
        $have = Stock::where('warehouse_id', $from->id)->pluck('quantity', 'product_id');

        return array_values(array_filter(array_map(function ($i) use ($have) {
            $i['quantity'] = min($i['quantity'], (int) ($have[$i['product_id']] ?? 0));

            return $i;
        }, $items), fn ($i) => $i['quantity'] > 0));
    }
}
