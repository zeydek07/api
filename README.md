# Medikal Stok API

Personel, yönetici ve sayfa bazlı yetki yönetimi; medikal ürün ve stok takibi;
saha personelinin mobil uygulamadan stoktan ürün düşmesi için merkezi API.

Laravel 13 · MySQL · Laravel Sanctum (Bearer token)

## Kurulum

```bash
# 1) MySQL'de veritabanını oluşturun
CREATE DATABASE medikal_stok CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 2) .env içinde DB_USERNAME / DB_PASSWORD ve ADMIN_EMAIL / ADMIN_PASSWORD değerlerini girin

# 3) Tabloları ve başlangıç verisini kurun
php artisan migrate --seed

# 4) Çalıştırın
php artisan serve          # http://localhost:8000/api/...
```

`ADMIN_PASSWORD` boş bırakılırsa seed sırasında rastgele bir şifre üretilip ekrana yazılır.

Testler (SQLite bellek veritabanında çalışır, MySQL gerekmez): `php artisan test`

**Örnek veri** (30 ürün, 10 hastane, 3 depocu, 3 mümessil ve son 3 haftanın hareketleri). Yalnızca deneme ortamında:

```bash
php artisan db:seed --class=DemoSeeder     # örnek kullanıcı şifresi: Demo1234!
```

## Güvenlik kuralları

- **Token'sız istek kabul edilmez.** Token gerektirmeyen tek adres `POST /api/auth/login`. Bunu bir test (`AuthTest`) her seferinde kontrol eder; yeni bir route korumasız eklenirse test kırılır.
- Yalnızca `Authorization: Bearer <token>` geçerlidir. Tarayıcı oturumu (cookie) kapalıdır.
- Token'lar 7 gün sonra geçersiz olur (`SANCTUM_TOKEN_EXPIRATION`, dakika).
- Pasife alınan personelin elindeki token anında çalışmaz olur.
- Personel silinince, pasife alınınca veya şifresi değişince tüm oturumları kapanır.
- Giriş denemesi: dakikada 5. Genel istek: dakikada 120. Ürün düşme: dakikada 30.
- Bir yönetici, kendinde olmayan bir yetkiyi başkasına veya bir role veremez. Süper yönetici hesabına dokunamaz.
- Stok hareketleri değiştirilemez ve silinemez. Hata düzeltmesi sayım düzeltmesiyle yapılır.

## Yetki modeli

Her sayfa + işlem bir yetkidir (`products.view`, `stock.transfer` ...). Liste [config/permissions.php](config/permissions.php) dosyasında.
Yeni sayfa eklemek için listeye yazıp `php artisan permissions:sync` çalıştırın.

Personelin yetkisi = **rolünün yetkileri + kendisine özel verilen yetkiler**.

| Rol | Varsayılan |
|---|---|
| `super-admin` | Her şey |
| `yonetici` | Personel, ürün, stok, transfer, sayım, ekibinin hareketleri, raporlar |
| `personel` | Ürünleri görme, sahada ürün düşme |
| `depocu` | Ürün, stok girişi, transfer, sayım, tüm hareketler, raporlar |
| `mumessil` | Ürünleri ve hastaneleri görme, hastaneye teslim (stoktan düşme) |

Roller panelden (`/api/roles`) değiştirilebilir, yeni rol eklenebilir.

**Yönetici – ekip:** Personelin `manager_id` alanı yöneticisini gösterir. `movements.view_team` yetkisi olan kişi kendisinin ve altındaki tüm kademelerin hareketlerini görür. `movements.view_all` olan herkesi görür.

## Stok modeli

- **Depo**: `central` (merkez), `branch` (şube), `field` (personelin çantası/aracı).
- Personel, yalnızca kendisine atanmış depolardan ürün düşebilir (`warehouse.user_ids` veya `user.warehouse_ids`).
- Tipik akış: Merkez depoya **giriş**, ardından personelin çantasına **transfer**, ardından personel sahada **kullanım** kaydeder.
- Stok eksiye düşemez. Çok satırlı bir işlemde tek satır başarısız olursa hiçbiri kaydedilmez.

## Uç noktalar

Tüm isteklerde: `Accept: application/json`, `Authorization: Bearer <token>`

### Oturum
| | | |
|---|---|---|
| POST | `/api/auth/login` | `{email, password, device_name}` → `{token, expires_at, user{permissions[], warehouses[]}}` |
| GET | `/api/auth/me` | Profil + yetki listesi (uygulama menüsü buna göre gösterilir) |
| POST | `/api/auth/logout` | Bu cihazdan çıkış |
| POST | `/api/auth/logout-all` | Tüm cihazlardan çıkış |
| POST | `/api/auth/change-password` | `{current_password, password, password_confirmation}` |

### Mobil (yetki: `usage.create`)
| | | |
|---|---|---|
| GET | `/api/mobile/warehouses` | Ürün düşebileceğim depolar |
| GET | `/api/mobile/warehouses/{id}/stock` | O depodaki stok |
| POST | `/api/mobile/usages` | Ürün düş (aşağıya bakın) |
| GET | `/api/mobile/hospitals?q=` | Teslimat yapılabilecek aktif hastaneler |
| GET | `/api/mobile/usages?from=&to=&hospital_id=` | Kendi teslimat geçmişim |
| GET | `/api/products/barcode/{barcode}` | Barkoddan ürün bul (`products.view`) |

```http
POST /api/mobile/usages
Idempotency-Key: 3f0e8c3a-7c1d-4b8e-9d55-0b6f6c3f1a10

{
  "warehouse_id": 3,
  "hospital_id": 5,
  "reference": "Teslim fişi 10452",
  "note": "Evde pansuman",
  "latitude": 41.0082, "longitude": 28.9784,
  "items": [
    {"product_id": 12, "quantity": 2, "lot_number": "LT4471", "expiry_date": "2027-05-01"},
    {"product_id": 7,  "quantity": 1}
  ]
}
```

**Idempotency-Key**: Mobil uygulama her yeni kayıt için bir UUID üretmeli ve tekrar denemelerde aynı değeri göndermeli.
Bağlantı koptuğunda istek iki kez gelirse stok ikinci kez düşmez; ilk kaydın sonucu `200` ile döner (ilk seferde `201`).

### Yönetim
| Yöntem | Adres | Yetki |
|---|---|---|
| GET/POST | `/api/users` | `users.view` / `users.create` |
| GET/PUT/DELETE | `/api/users/{id}` | `users.view` / `users.update` / `users.delete` |
| POST | `/api/users/{id}/revoke-tokens` | `users.update` (telefon kaybolursa) |
| GET | `/api/permissions` | `roles.view` (sayfa sayfa gruplu yetki listesi) |
| GET/POST | `/api/roles` | `roles.view` / `roles.create` |
| GET/PUT/DELETE | `/api/roles/{id}` | `roles.*` |
| GET/POST | `/api/products` | `products.view` / `products.create` (`?q=&category=&is_active=`) |
| GET/PUT/DELETE | `/api/products/{id}` | `products.*` |
| GET | `/api/products/categories` | `products.view` |
| GET/POST | `/api/hospitals?q=&city=&type=` | `hospitals.view` / `hospitals.create` |
| GET | `/api/hospitals/{id}?from=&to=` | `hospitals.view` (ürün bazında toplam, teslim eden personel, son teslimatlar) |
| PUT/DELETE | `/api/hospitals/{id}` | `hospitals.update` / `hospitals.delete` |
| GET/POST | `/api/warehouses` | `warehouses.view` / `warehouses.create` |
| GET/PUT/DELETE | `/api/warehouses/{id}` | `warehouses.*` |
| GET | `/api/stocks?warehouse_id=&product_id=&low_stock=1` | `stock.view` |
| POST | `/api/stocks/receive` | `stock.receive` `{warehouse_id, reference, items[]}` |
| POST | `/api/stocks/transfer` | `stock.transfer` `{from_warehouse_id, to_warehouse_id, items[]}` |
| POST | `/api/stocks/adjust` | `stock.adjust` `{warehouse_id, product_id, counted_quantity, note}` |
| GET | `/api/movements?type=usage&user_id=&product_id=&hospital_id=&from=&to=` | `movements.view_team` / `view_all` |
| GET | `/api/dashboard` | `dashboard.view` |
| GET | `/api/reports/usage?group_by=user\|product\|user_product\|hospital\|hospital_product&from=&to=` | `reports.view` |
| GET | `/api/reports/low-stock` | `reports.view` |

Personel oluşturma örneği:

```json
POST /api/users
{
  "name": "Ali Yılmaz", "email": "ali@firma.com", "phone": "0555...",
  "password": "GeciciSifre123", "role_id": 3, "manager_id": 2,
  "warehouse_ids": [3], "permissions": ["reports.view"]
}
```

### Hata yanıtları
| Kod | Anlamı |
|---|---|
| 401 | Token yok, geçersiz ya da süresi dolmuş: giriş ekranına yönlendirin |
| 403 | Yetki yok (`required_permission` alanı hangi yetkinin gerektiğini söyler) veya hesap pasif |
| 404 | Kayıt bulunamadı |
| 422 | Doğrulama hatası / yetersiz stok (`errors` alanında ayrıntı) |
| 429 | Çok fazla istek |

## Canlıya alırken

- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, güçlü `ADMIN_PASSWORD`
- Yalnızca HTTPS üzerinden yayınlayın (token'lar açık metin olarak taşınır).
- Süresi dolan token kayıtlarının temizlenmesi için zamanlayıcıyı çalıştırın: `php artisan schedule:work` (veya cron'da `schedule:run`).
