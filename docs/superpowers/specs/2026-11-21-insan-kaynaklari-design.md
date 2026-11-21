# İnsan Kaynakları Modülü: Analiz, Kapsam, Veri Modeli ve API Sözleşmesi

- **Tarih:** 2026-11-21
- **Durum:** Taslak, onay bekliyor. Bu aşamada kod yazılmaz.
- **Kapsam:** 8 modül (Personel, Belge, Sözleşme, İzin, Puantaj, Eğitim, Masraf, Filo). Bordro ilk sürümde yok.
- **Yöntem:** Modül modül. Her modülün detayı onaylanır, geliştirilir, test edilir, sonra bir sonrakine geçilir. Şu an onaya sunulan: **Aşama 0 (Temel) ve Aşama 1 (Personel)**.

## 1. Amaç

Uygulama bugün konsinye medikal stok ve saha süreçlerini yönetiyor. Şirketin departmanları da bu sistemi kullanacak: giriş yapan kişi yalnızca kendi departmanının ekranlarını görecek. Depo ve saha ekranları var; İnsan Kaynakları, Finans ve Muhasebe için ekran yok.

Bu çalışma, OCA'nın Odoo HR modüllerinden **iş akışlarını anlayıp** bunları mevcut Laravel + MySQL + Angular + Flutter uygulamasında **bağımsız olarak** kurmayı hedefler.

### Hedefler

1. Personelin işe girişten ayrılışa kadar tek kayıtta izlenmesi.
2. İzin, puantaj düzeltme ve masraf taleplerinin kurallı onay akışlarından geçmesi.
3. Süresi dolan belge, sözleşme ve sertifikaların kaçırılmaması.
4. Her departmanın yalnızca yetkili olduğu veriyi görmesi.
5. Kritik her işlemin kim, ne zaman, ne yaptı sorusuna cevap verebilmesi.

### Hedef dışı

- Bordro, SGK, gelir vergisi ve damga vergisi hesabı.
- Yevmiye ve genel muhasebe. Dış muhasebe programı entegrasyonu yoktur; sistem tamamen şirketin kendi uygulamasıdır.
- e-Bildirge, e-Devlet veya SGK entegrasyonu.
- İşe alım, performans değerlendirme, organizasyon şeması çizimi.
- PostgreSQL'e geçiş. Veritabanı MySQL olarak kalır.

### Başarısızlık sayılacak durumlar

- Bir personelin yetkisiz kişilerce sağlık, kimlik veya özlük verisinin görülmesi.
- Aynı izin talebinin iki kez bakiyeden düşmesi veya bir onay bağlantısının iki kez çalışması.
- Mevcut stok, vaka ve faturalama akışlarının bozulması.
- OCA kodundan türetilmiş kodun projeye girmesi.

## 2. Kısıtlar ve mevcut durum

| Konu | Mevcut durum | Tasarıma etkisi |
|---|---|---|
| Çatı | Laravel 13, PHP 8.3+, Sanctum | Aynı oturum sistemi kullanılır |
| Veritabanı | MySQL (XAMPP), testler SQLite bellek içi | Eşzamanlılık testleri MySQL'de de koşmalı; SQLite satır kilidini sınamaz |
| Yetki | `config/permissions.php`, `permission:` middleware, rol + kişiye özel yetki | Yeni sayfalar buraya eklenir, `permissions:sync` çalışır |
| Menü | `panel/src/app/layout/menu.ts` yetkiye göre süzer | Yeni menü grupları eklenir |
| Ekip | `users.manager_id`, `teamUserIds()` | İK yönetici zinciri bununla tutarlı olmalı (bkz. 6.1) |
| Idempotency | `Idempotency-Key` başlığı, kontrol denetleyicilerde ayrı ayrı | İK için ortak middleware yazılır; mevcut uçlara dokunulmaz |
| Kuyruk | `QUEUE_CONNECTION=database` | Canlıda çalışan bir worker gerekir |
| E-posta | `MAIL_MAILER=log`, SMTP canlıya kaldı | E-posta onayı SMTP kurulmadan gerçek ortamda çalışmaz |
| Zamanlayıcı | `routes/console.php` | Canlıda cron gerekir |
| Dosya | `local` diski `serve => true` | **İK belgeleri bu diske konmaz**, ayrı disk kullanılır |
| Geliştirme klasörü | Proje OneDrive içinde | **Gerçek personel dosyası bu klasöre konmaz**, OneDrive'a eşitlenir |
| Repo | Herkese açık GitHub | Gerçek personel verisi, `.env` ve anahtarlar asla commit edilmez |

## 3. OCA analizi

### 3.1 Yöntem ve lisans

OCA'nın `hr`, `hr-holidays`, `hr-attendance`, `hr-expense` ve `payroll` depolarının 19.0 dalındaki 25 modülün **yalnızca manifest ve README dosyaları** okundu. 21 modül AGPL-3, 4 modül LGPL-3 lisanslıdır (`payroll`, `payroll_account`, `hr_attendance_reason`, `hr_user_role_activity`).

Kurallar:

- Python iş kodu, XML ekranları, veri şemaları ve çeviri dosyaları okunmaz, çevrilmez, uyarlanmaz.
- Alınan şey işlevsel fikirdir: hangi iş var, hangi adımlardan geçiyor.
- Tablo, alan, servis ve endpoint adları bu projenin alanından bağımsız olarak belirlenir.
- Bu bir hukuki görüş değildir. Ürün satılacaksa bir hukukçuya danışılmalıdır.

**Sınır:** İzin, puantaj, masraf ve sözleşmenin temel işleyişi OCA'da değil, Odoo çekirdeğindedir (`hr_holidays`, `hr_attendance`, `hr_expense`). OCA modülleri bunları genişletir. Bu yüzden temel akışlar OCA README'lerinden ve Türkiye'deki iş uygulamasından tasarlanmıştır, Odoo çekirdeği incelenmemiştir.

### 3.2 Modüllerden alınan gereksinimler

| OCA modülü | Çıkarılan işlevsel gereksinim | Bizdeki karşılığı |
|---|---|---|
| `hr_department_code` | Departmanın kodu olur | `hr_departments.code` |
| `hr_employee_firstname` | Ad ve soyad ayrı tutulur | `first_name`, `last_name` |
| `hr_employee_age` | Yaş doğum tarihinden hesaplanır | Saklanmaz, hesaplanır |
| `hr_employee_language` | Personelin dilleri izlenir ve filtrelenir | `hr_employee_languages` |
| `hr_employee_relative` | Aile bilgileri tutulur | `hr_employee_relatives` |
| `hr_job_category` | Pozisyona bağlı kategoriler personele yansır | `hr_position_category` |
| `hr_employee_partner_external` | Dış firma tarafından yönetilen çalışan | `employment_type = external`, `external_company` |
| `hr_employee_document` | Personel ve İK özlük belgelerini görür | Belge modülü |
| `hr_employee_medical_examination` | Toplu oluşturulan, tek tek izlenen sağlık muayenesi | Belge kategorisi + geçerlilik tarihi (ayrı modül yok) |
| `hr_employee_calendar_planning` | Çalışma takvimi tarih aralıklarıyla değişir | `hr_employee_schedules` |
| `hr_contract_reference` | Sözleşme numarası otomatik üretilir | `hr_contracts.number` |
| `hr_course` | Eğitim → oturum → katılımcı ata → başlat → sonuç gir → bitir | Eğitim akışı |
| `hr_holidays_public` | İzin türü resmî tatili hariç tutabilir | `exclude_public_holidays` |
| `hr_holidays_natural_period` | Takvim günü ve yarım takvim günü | `counting = calendar_day` |
| `resource_calendar_flexible_exclude_weekend` | Esnek çalışmada hafta sonu hariç | `exclude_weekends` |
| `hr_holidays_leave_report_calendar_type` | İzin takviminde türe göre filtre | Takvim endpoint filtresi |
| `hr_attendance_reason` | Sapma için sebep seçilir | `hr_attendance_reasons` |
| `hr_attendance_report_theoretical_time` | Olması gereken ve gerçekleşen süre karşılaştırılır; izin türü teorik süreye sayılabilir | Puantaj hesabı, `counts_as_worked` |
| `hr_attendance_employee_calendar_planning` | Değişen takvimde fazla mesai doğru hesaplanır | Puantaj o günün takvimiyle hesaplar |
| `hr_expense_sheet` | Masraflar bir rapor altında toplanır | `hr_expense_reports` |
| `hr_user_role_activity` | Süresi dolacak yetki hatırlatması yöneticiye gider | Hatırlatma alıcı kuralı |
| `payroll`, `payroll_account` | Bordro | Kapsam dışı |
| `hr_employee_second_lastname`, `hr_holidays_settings` | Türkiye'de gereksiz / teknik | Alınmadı |

## 4. Kullanıcılar ve ekranlar

| Rol (yeni: *) | Gördüğü alan | Veri kapsamı |
|---|---|---|
| Personel (her çalışan) | Benim Sayfam: profil, belgelerim, izinlerim, puantajım, eğitimlerim, masraflarım | Kendisi |
| Yönetici (ek yetki) | Ekip izinleri, puantaj onayı, masraf onayı | Bağlı olduğu ekip |
| İK Uzmanı * | Personel, belge, sözleşme, izin, puantaj, eğitim | Tüm personel, sağlık belgesi hariç |
| İK Yöneticisi * | İK Uzmanı + sağlık belgeleri, ayarlar, denetim kaydı | Tüm personel |
| Süper Yönetici | Tüm İK verisi; sağlık belgesi ve açık TC/IBAN erişimi loglanır | Tüm personel |
| Finans * | Masraf ödeme, masraf raporları | Masraf verisi; özlük yok |
| Muhasebe * | Masraf muhasebe onayı, masraf raporları | Masraf verisi; özlük yok |
| Depo Sorumlusu | Mevcut ekranlar + Benim Sayfam | Değişmez |
| Saha Personeli | Mevcut ekranlar + Benim Sayfam | Değişmez |

Panel menüsü:

```text
Benim Sayfam      Profilim · İzinlerim · Puantajım · Eğitimlerim · Masraflarım
Ekibim            İzin Onayları · Puantaj Onayları · Masraf Onayları · Ekip Takvimi
İnsan Kaynakları  Personeller · Belgeler · Sözleşmeler · İzinler · Puantaj · Eğitimler · Ayarlar
Finans            Masraf Onayı · Ödemeler · Masraf Raporları
```

Mevcut "Personeller" sayfası **sistem kullanıcılarını** yönetir. Karışmaması için adı **"Kullanıcılar"** olarak değiştirilir; İK'daki sayfa "Personeller" olur.

Flutter ilk sürümde yalnızca çalışan ve yönetici işlerini kapsar: izin talebi ve bakiye, giriş/çıkış, puantaj düzeltme, masraf + fiş fotoğrafı, onay bekleyenler. İK yönetimi yalnızca paneldedir.

## 5. Modüller ve bağımlılıklar

```text
                 Temel (audit, onay token, idempotency, bildirim, dosya)
                                     │
                                 Personel
              ┌──────────┬───────────┼───────────┬──────────┐
           Belge     Sözleşme      İzin       Eğitim     Masraf
              ▲          │           │           │
              └──────────┴───────────┼───────────┘ (sözleşme/sertifika dosyaları Belge'de)
                                     ▼
                                  Puantaj (izin ve resmî tatili okur)
```

Kod yapısı modüler monolittir:

```text
app/Modules/Hr/
  Shared/       Audit, ApprovalToken, Idempotency, Notifications, Calendar
  Employees/    Models, Services, Http/Controllers, Http/Requests, Policies
  Documents/    ...
  Contracts/    ...
  Leave/        ...
  Attendance/   ...
  Training/     ...
  Expenses/     ...
routes/hr.php
database/migrations/hr/
```

Kurallar:

- Bir modül başka modülün tablosuna doğrudan yazmaz; onun servisini çağırır.
- Modüller arası yan etkiler (ör. izin onaylandı → puantaj güncellensin) transaction sonrası Laravel event'iyle yapılır.
- Tablolar `hr_` önekini, endpoint'ler `/api/hr` önekini kullanır.
- Mevcut stok, vaka ve faturalama koduna İK bağımlılığı eklenmez.

## 6. Veri modeli

Ortak kurallar: `id` bigint, `created_at`, `updated_at`. Yabancı anahtarlar gerçek FK'dir ve aksi yazılmadıkça silmeyi engeller (`restrict`). Para `decimal(14,2)`, kur `decimal(14,6)`, süreler tam sayı dakika. Anlık zamanlar UTC saklanır; `work_date` gibi iş tarihleri Europe/Istanbul takvim günüdür. Personel ve belge kayıtları soft delete kullanır.

### 6.1 Personel

**`hr_departments`:** `code` (unique), `name`, `parent_id` (FK self, null), `manager_employee_id` (FK, null), `is_active`

**`hr_positions`:** `code` (unique), `name`, `department_id` (FK, null), `is_active`

**`hr_categories`:** `name` (unique). **`hr_position_category`:** PK (`position_id`, `category_id`). **`hr_employee_category`:** PK (`employee_id`, `category_id`), `source` (`position`, `manual`)

**`hr_employees`**

| Alan | Tür | Not |
|---|---|---|
| `employee_no` | varchar(20), unique | `P-000001`; sayaçla üretilir, tekrar kullanılmaz |
| `user_id` | FK users, null, unique | Her personelin giriş hesabı olmak zorunda değil |
| `first_name`, `last_name` | varchar | |
| `national_id_encrypted` | text, null | TC Kimlik No, şifreli |
| `national_id_hash` | char(64), null, unique | Ayrı anahtarla HMAC; mükerrer kontrol ve arama için |
| `birth_date`, `gender`, `marital_status` | | Gender ve medeni durum isteğe bağlı |
| `work_email`, `work_phone` | | |
| `personal_email`, `personal_phone`, `address` | şifreli | Kişisel iletişim |
| `iban_encrypted` | text, null | Masraf ödemesi için |
| `department_id`, `position_id` | FK, null | indeks |
| `manager_id` | FK self, null | indeks |
| `employment_type` | varchar | `full_time`, `part_time`, `intern`, `external` |
| `external_company` | varchar, null | |
| `status` | varchar | `draft`, `active`, `on_leave_long`, `terminated` |
| `hire_date`, `seniority_date` | date | Kıdem, önceki hizmet sayılıyorsa farklı olabilir |
| `termination_date`, `termination_reason_id` | null | |

İndeksler: `(status, department_id)`, `(last_name, first_name)`.

Yönetici zinciri: İK onayları yalnızca `hr_employees.manager_id`'yi kullanır. **`users.manager_id`'ye yazılmaz**, çünkü o alan `teamUserIds()` üzerinden stok, teslimat ve vaka görünürlüğünü belirler; İK'daki bir organizasyon değişikliği bu yetkileri sessizce değiştirmemelidir. Döngü oluşturan atama reddedilir. Onaylayıcı doğrudan yöneticidir; hesabı yoksa veya pasifse zincirde hesabı olan ilk üst yöneticiye, o da yoksa İK Yöneticisine düşer. Ekip görünürlüğü tüm alt kademeleri kapsar, onay yetkisi yalnızca belirlenen onaylayıcıdadır.

**`hr_emergency_contacts`:** `employee_id` (FK, cascade), `full_name`, `relationship`, `phone`, `priority`

**`hr_employee_relatives`:** `employee_id` (FK, cascade), `full_name`, `relationship`, `birth_date`, `is_dependent`

**`hr_languages`:** `code` (unique), `name`. **`hr_employee_languages`:** unique (`employee_id`, `language_id`), `reading`, `writing`, `speaking`

**`hr_termination_reasons`:** `code` (unique), `name`, `is_active`

**`hr_employment_events`:** `employee_id`, `type` (`hired`, `transferred`, `position_changed`, `manager_changed`, `terminated`, `rehired`), `effective_date`, `old_values` (json), `new_values` (json), `actor_user_id`. Yalnızca eklenir, değiştirilmez.

İşten ayrılışta: gelecekteki izinler iptal edilir, onay bekleyen talepler yöneticiye düşer, bağlı kullanıcı pasifleştirilir ve token'ları iptal edilir. Üstünde ekip varsa ayrılış, ekip yeni yöneticiye atanmadan tamamlanamaz. Kayıt silinmez.

### 6.2 Belge

**`hr_document_categories`:** `code` (unique), `name`, `sensitivity` (`normal`, `confidential`, `health`), `requires_expiry`, `employee_visible`, `reminder_days` (json, ör. `[60,30,7]`), `is_active`

**`hr_documents`:** `employee_id` (FK), `category_id` (FK), `title`, `issued_at`, `expires_at` (null), `status` (`active`, `archived`), `current_version_id` (null), `source_type` + `source_id` (sözleşme, sertifika, izin raporu). İndeks: `(employee_id, category_id)`, `(status, expires_at)`.

**`hr_document_versions`:** `document_id` (FK), `version_no`, `disk`, `path`, `original_name`, `mime`, `size`, `sha256`, `uploaded_by`, unique (`document_id`, `version_no`). Eski sürüm silinmez.

**`hr_document_access_logs`:** `document_id`, `version_id`, `user_id`, `action` (`view`, `download`, `upload`, `archive`, `denied`), `ip`, `user_agent`, `created_at`. Yalnızca eklenir.

Dosya kuralları: Ayrı `hr_documents` diski (`serve => false`), kök dizin web dışında ve `HR_STORAGE_PATH` ile ayarlanır. Dosya adı rastgele üretilir. Dosya yalnızca yetki kontrolü yapan endpoint üzerinden akıtılır; herkese açık veya imzalı URL yoktur. İçerik türü sunucuda okunur. İzinli türler: PDF, JPG, PNG; üst sınır 15 MB.

### 6.3 Sözleşme

**`hr_contract_types`:** `code` (unique), `name`, `is_fixed_term`, `default_probation_days`, `is_active`

**`hr_contracts`**

| Alan | Not |
|---|---|
| `number` | `SZL-2026-00001`, unique |
| `employee_id`, `contract_type_id` | FK |
| `previous_contract_id` | FK self, null; yenileme zinciri |
| `start_date`, `end_date` | Belirli süreli türde `end_date` zorunlu |
| `probation_end_date` | null |
| `position_id`, `department_id` | Sözleşme anındaki kopya |
| `weekly_minutes` | Kısmi süreli çalışmayı belirler |
| `status` | `draft`, `active`, `expired`, `terminated`, `renewed` |
| `active_employee_id` | Generated: `status='active'` ise `employee_id`, değilse NULL; **unique** |
| `signed_document_id` | FK, null |

Akış: `draft → active → expired | terminated | renewed`. Yenileme, eski sözleşmeyi `renewed` yapıp yenisini aynı transaction'da açar. Etkin sözleşme alanları değiştirilemez; düzeltme için yeni sözleşme veya iptal gerekir. Maaş bilgisi bordro ile birlikte ele alınacağından bu sürümde saklanmaz.

### 6.4 İzin

**`hr_leave_types`:** `code` (unique), `name`, `counting` (`working_day`, `calendar_day`), `exclude_weekends`, `exclude_public_holidays`, `is_paid`, `uses_balance`, `allows_half_day`, `requires_document`, `sensitive`, `counts_as_worked`, `min_notice_days`, `max_days_per_request`, `is_active`

Başlangıç türleri: yıllık, mazeret, ücretsiz, hastalık. Mazeret alt türleri (evlilik, ölüm, babalık vb.) ayrı kayıt olarak eklenir.

**`hr_leave_entitlement_rules`:** `leave_type_id`, `effective_from`, `min_service_years`, `max_service_years`, `min_age`, `max_age`, `days`. Yasal süreler koda yazılmaz; yürürlük tarihiyle tabloda tutulur ve **canlıdan önce mali müşavir veya hukukçu tarafından doğrulanır**. Başlangıç verisi (İş Kanunu md. 53): 1-5 yıl 14 gün, 5-15 yıl 20 gün, 15+ yıl 26 gün; 18 yaş altı ve 50 yaş üstü en az 20 gün.

**`hr_public_holidays`:** `date`, `name`, `part` (`full`, `afternoon`), unique (`date`, `part`). Dinî bayramlar her yıl değiştiği için yıllık girilir; gelecek yılın takvimi eksikse İK uyarılır.

**`hr_work_schedules`:** `name`, `weekly_minutes`, `is_active`. **`hr_work_schedule_days`:** unique (`schedule_id`, `weekday`), `start_time`, `end_time`, `break_minutes`, `grace_minutes`.

**`hr_employee_schedules`:** `employee_id`, `schedule_id`, `start_date`, `end_date` (null). Çakışan aralık servis tarafından reddedilir.

**`hr_leave_requests`**

| Alan | Not |
|---|---|
| `number` | `IZN-2026-00001`, unique |
| `employee_id`, `leave_type_id` | FK; indeks (`employee_id`, `status`) |
| `start_date`, `end_date`, `start_part`, `end_part` | `full`, `morning`, `afternoon` |
| `quantity_days` | decimal(5,2); talep anında hesaplanıp donar |
| `reason` | Hassas türde yöneticiye gösterilmez |
| `status` | aşağıdaki akış |
| `approver_employee_id` | Talep anındaki onaylayıcı |
| `decided_by`, `decided_at`, `decision_note` | |
| `source` | `panel`, `mobile`, `email` |
| `document_id` | Hastalık raporu; tanı bilgisi istenmez |

Akış:

```text
draft → pending → approved → taken
          │          ├→ cancel_requested → cancelled (bakiye iade)
          │          └→ cancelled (izin başlamadan)
          ├→ rejected (bakiye iade)
          └→ withdrawn (bakiye iade)
```

**`hr_leave_request_days`:** `request_id`, `employee_id`, `date`, `part` (`morning`, `afternoon`), `counted`, **unique (`employee_id`, `date`, `part`)**. Çakışan izni veritabanı seviyesinde engeller. Talep reddedilince, geri çekilince veya iptal olunca satırları silinir; tarihçe `hr_leave_events`'te kalır.

**`hr_leave_ledger`** (bakiye defteri, yalnızca eklenir): `employee_id`, `leave_type_id`, `entitlement_year`, `entry_type` (`opening`, `accrual`, `reservation`, `release`, `usage`, `adjustment`), `days` (+/-), `request_id` (null), `idempotency_key` (unique), `note`, `actor_user_id`.

Bakiye = defterdeki toplamdır, ayrı bir kolon olarak güncellenmez. Düzeltme kayıt silerek değil ters kayıtla yapılır. Bakiye işlemleri personel satırı `lockForUpdate` ile kilitlenerek yapılır.

**`hr_leave_events`:** `request_id`, `type`, `actor_user_id`, `actor_channel` (`panel`, `mobile`, `email_link`, `system`), `payload` (json).

**İzin hesap kuralları:**

- **Açılış:** Mevcut personel ve kalan izinler Excel şablonuyla içe aktarılır; önce önizleme ve hata listesi gösterilir, bakiyeler `opening` kaydı olur.
- **Hak ediş:** `seniority_date` yıl dönümünde oluşur. `entitlement_year`, hizmet yılının başladığı takvim yılıdır.
- **Devir:** Kullanılmayan yıllık izin silinmez. Kullanımda en eski hak ediş önce düşer. Yıl sonunu aşan izin de aynı sırayla düşer.
- **Eksi bakiye:** Varsayılan olarak kapalı; yalnızca `adjust_balance` yetkisiyle gerekçeli düzeltme yapılabilir.
- **Gün sayımı:** `working_day` türünde personelin o tarihteki çalışma takvimi esastır. Cumartesinin yıllık izinden sayılması `hr_settings` içinde ayardır ve canlıdan önce mali müşavirle doğrulanır.
- **Başlamış izin:** `taken`, izin bitiş tarihi geçince işaretlenir. Başlamış izin tamamen iptal edilemez, yalnızca bugünden sonrası kısaltılır: gelecekteki günler silinir, o kadar gün bakiyeye iade edilir, puantaj yeniden hesaplanır. İşten ayrılışta da aynı kural uygulanır.
- **Hastalık izni:** Rapor genellikle sonradan geldiği için yönetici onayından geçmez. İK kaydeder, İK Yöneticisi raporu doğrular. Yönetici yalnızca "İzinli" görür; İK Uzmanı türü görür, belgeyi göremez. Kapalı aya düşerse İK Yöneticisi dönemi audit kaydıyla yeniden açar.

### 6.5 Puantaj

**`hr_attendance_reasons`:** `code` (unique), `name`, `is_active`

**`hr_attendance_punches`** (ham kayıt, yalnızca eklenir): `employee_id`, `type` (`in`, `out`), `occurred_at`, `source` (`panel`, `mobile`, `device`, `correction`), `client_uuid` (unique, null), `latitude`, `longitude`, `accuracy_m`, `reason_id`, `correction_request_id`. İndeks: (`employee_id`, `occurred_at`).

**`hr_attendance_days`** (hesaplanmış): unique (`employee_id`, `work_date`), `scheduled_minutes`, `worked_minutes`, `break_minutes`, `late_minutes`, `early_leave_minutes`, `missing_minutes`, `overtime_minutes`, `leave_minutes`, `status` (`present`, `absent`, `leave`, `holiday`, `weekend`, `incomplete`), `overtime_status` (`none`, `pending`, `approved`, `rejected`), `calculated_at`.

Ham kayıt değişmez; gün tablosu ham kayıttan her zaman yeniden hesaplanabilir. Gece vardiyası, başladığı `work_date`'e yazılır.

Hesap: `gerçekleşen = ilk giriş-son çıkış çiftleri - ara`, `planlanan = o günün takvimi - resmî tatil - izin`. Haftalık yasal fazla çalışma sınırı ve yıllık üst sınır ayarlarda tutulur; başlangıç değerleri 45 saat/hafta ve 270 saat/yıl, canlı öncesi doğrulanır.

**`hr_attendance_corrections`:** `employee_id`, `work_date`, `requested_in`, `requested_out`, `reason_id`, `note`, `status` (`pending`, `approved`, `rejected`), `decided_by`, `decided_at`. Onay ham kaydı değiştirmez, `source = correction` yeni kayıt ekler.

**`hr_timesheet_periods`:** unique (`year`, `month`), `status` (`open`, `review`, `closed`), `closed_by`, `closed_at`. Kapanan aydaki kayıt ve düzeltme reddedilir; yeniden açma İK Yöneticisi ister ve audit'e yazılır. Bu kapalı dönem verisi, ileride bordroya girdi olacak.

### 6.6 Eğitim

**`hr_training_categories`:** `name` (unique), `is_active`

**`hr_training_courses`:** `code` (unique), `name`, `category_id`, `provider`, `duration_minutes`, `is_mandatory`, `certificate_valid_months` (null), `is_active`

**`hr_training_sessions`:** `course_id`, `starts_at`, `ends_at`, `location`, `trainer`, `capacity`, `status` (`planned`, `in_progress`, `completed`, `cancelled`)

**`hr_training_participants`:** unique (`session_id`, `employee_id`), `status` (`invited`, `attended`, `absent`, `passed`, `failed`), `score`, `result_note`

**`hr_certificates`:** `employee_id`, `course_id` (null), `participant_id` (null), `name`, `issuer`, `certificate_no`, `issued_at`, `expires_at` (null), `document_id`, `previous_certificate_id`. İndeks: (`employee_id`, `expires_at`).

### 6.7 Masraf

**`hr_expense_categories`:** `code` (unique), `name`, `default_vat_rate`, `requires_receipt`, `max_amount` (null), `is_active`

**`hr_expense_reports`**

| Alan | Not |
|---|---|
| `number` | `MSR-2026-00001`, unique |
| `employee_id` | FK; indeks (`employee_id`, `status`) |
| `title`, `period_start`, `period_end` | |
| `status` | aşağıdaki akış |
| `total_try`, `vat_total_try` | Gönderimde hesaplanıp donar |
| `manager_decided_by/at`, `accounting_decided_by/at` | Ayrı kişiler |
| `paid_at`, `paid_by`, `payment_reference` | |

Akış:

```text
draft → manager_pending → accounting_pending → approved → paid
              │                   │
              └──── returned ─────┘ (düzeltip yeniden gönderilir)
              └──── rejected ─────┘
```

**`hr_expense_items`:** `report_id` (FK, cascade), `category_id`, `expense_date`, `description`, `vendor`, `invoice_no`, `currency` (char 3), `amount`, `exchange_rate`, `rate_date`, `amount_try`, `vat_rate`, `vat_amount`, `document_id`. Satırlar rapor gönderildikten sonra değiştirilemez.

Aynı fatura mükerrer kontrolü: `vendor + invoice_no + expense_date + amount` eşleşirse uyarı verilir; muhasebe karar verir.

Kur: İlk sürümde otomatik kur servisi yoktur. Yabancı para satırında kuru personel girer, muhasebe onayında düzeltebilir; değişiklik audit'e yazılır.

Ödeme: `hr_expenses.pay` yetkilisi yalnızca `approved` durumdaki raporun sahibinin IBAN'ını, ayrı ve audit'li bir endpoint ile görebilir. Finans'a bunun dışında özlük verisi açılmaz.

### 6.8 Ortak tablolar

**`hr_audit_logs`** (yalnızca eklenir): `actor_user_id` (null), `actor_channel`, `action` (`leave.approved` gibi), `subject_type`, `subject_id`, `employee_id` (null), `changed_fields` (json), `ip`, `user_agent`, `request_id`, `created_at`. İndeks: (`subject_type`, `subject_id`), (`employee_id`, `created_at`), (`action`, `created_at`).

Hassas alanların **değeri** audit'e yazılmaz, yalnızca "`iban` değişti" bilgisi yazılır. Uygulama kullanıcısının bu tabloda update/delete yetkisi canlı veritabanında kaldırılır.

**`hr_approval_tokens`:** `token_hash` (char(64), unique), `purpose`, `subject_type`, `subject_id`, `subject_version`, `approver_user_id`, `expires_at`, `used_at` (null), `revoked_at` (null), `used_ip`, `used_user_agent`.

**`hr_idempotency_keys`:** unique (`user_id`, `key`, `route`), `request_hash`, `status_code`, `resource_type`, `resource_id`, `expires_at`. Yanıt gövdesi saklanmaz; tekrar istekte kaynak yetki kontrolüyle yeniden okunur.

**`hr_reminders`:** `kind`, `subject_type`, `subject_id`, `threshold_days`, `due_date`, `sent_at`, **unique (`kind`, `subject_type`, `subject_id`, `due_date`, `threshold_days`)**. Günlük iş iki kez çalışsa da tek bildirim gider.

**`hr_sequences`:** `key` (PK), `next_value`. Numara üretimi satır kilidiyle yapılır.

## 7. Güvenlik ve yetkilendirme

### 7.1 Yetki anahtarları

`config/permissions.php` dosyasına eklenecek sayfalar:

| Sayfa | İşlemler |
|---|---|
| `hr_self` | `view` (Benim Sayfam) |
| `hr_employees` | `view_team`, `view_all`, `create`, `update`, `terminate`, `view_sensitive` |
| `hr_documents` | `view_all`, `manage`, `view_confidential`, `view_health` |
| `hr_contracts` | `view`, `manage` |
| `hr_leave` | `request`, `approve_team`, `view_all`, `manage`, `adjust_balance` |
| `hr_attendance` | `punch`, `approve_team`, `view_all`, `manage`, `close_period` |
| `hr_training` | `view`, `manage` |
| `hr_expenses` | `create`, `approve_team`, `approve_accounting`, `pay`, `view_all` |
| `hr_settings` | `manage` |
| `hr_audit` | `view` |

Her istek üç kontrolden geçer:

1. **Sayfa yetkisi:** route üzerindeki `permission:` middleware.
2. **Kayıt kapsamı:** Policy; kendisi, bağlı ekibi veya tümü.
3. **Alan kapsamı:** API Resource hassas alanları yetkiye göre çıkarır. Yönetici hastalık izni türünü görmez, takvimde yalnızca "İzinli" görür. TC ve IBAN varsayılan olarak maskelidir; açık görüntüleme ayrı endpoint'tir ve audit'e yazılır.

Yetkisiz kaydın varlığı sızdırılmaz: kapsam dışı kayıt `404` döner.

**Süper yönetici:** Sağlık belgelerini ve açık TC/IBAN'ı görebilir (kullanıcı kararı). TC ve IBAN onun için de varsayılan olarak maskelidir; açmak için `reveal` endpoint'i ve zorunlu `reason` alanı kullanılır. Her açma, görüntüleme, indirme ve reddedilen deneme audit'e yazılır. Süper yönetici de bu kayıtları uygulama üzerinden değiştiremez veya silemez.

### 7.2 Görevler ayrılığı

- Kimse kendi izin, puantaj düzeltme veya masrafını onaylayamaz. Süper yönetici dahil.
- Masrafta yönetici onayı ve muhasebe onayı aynı kişi olamaz. Ödemeyi yapan, muhasebe onayını veren kişi olmamalıdır; küçük ekipler için bu kural ayardan gevşetilebilir ve audit'e yazılır.
- Yöneticisi olmayan personelin talebi İK Yöneticisine düşer. Talep sahibi İK Yöneticisi ise başka bir İK Yöneticisine veya süper yöneticiye düşer.

### 7.3 E-posta ile onay

1. 32 bayt rastgele token üretilir, veritabanında yalnızca SHA-256 özeti tutulur.
2. Token tek talebe, tek onaylayıcıya, talebin sürümüne ve tek amaca bağlıdır. Süresi 72 saat.
3. **E-postadaki bağlantı doğrudan onaylamaz.** E-posta güvenlik tarayıcıları bağlantıları otomatik açar. `GET` yalnızca onay sayfasını gösterir; karar sayfadaki düğmeyle `POST` edilir.
4. Token tüketimi atomiktir: `UPDATE ... WHERE used_at IS NULL AND revoked_at IS NULL AND expires_at > now()`; 1 satır etkilenmezse işlem durur.
5. Talep değişir, iptal olur veya panelden karara bağlanırsa token iptal edilir.
6. Onaylayıcı pasifse, onay yetkisini kaybettiyse veya talep sahibiyle aynı kişiyse token çalışmaz.
7. Uç nokta hız sınırlıdır, `Referrer-Policy: no-referrer` ve `Cache-Control: no-store` gönderir. Token log'a yazılmaz.
8. E-postada izin türü, sağlık bilgisi veya kişisel veri bulunmaz; "Ahmet Y. için 3 günlük izin talebi" kadar bilgi yer alır.

### 7.4 KVKK

- Sağlık raporları özel nitelikli kişisel veridir: ayrı yetki, erişim logu, tanı bilgisi yok.
- Personele aydınlatma metni, saklama ve imha süreleri canlıdan önce hukuk danışmanıyla belirlenir.
- Mobil konum yalnızca giriş/çıkış anında alınır, sürekli izlenmez.
- Demo ve test verisi tamamen sahtedir.

### 7.5 Transaction ve idempotency

- Durum değiştiren her işlem: transaction + ilgili satıra `lockForUpdate` + mevcut durum kontrolü. Geçersiz geçiş `409`.
- Audit kaydı aynı transaction içinde yazılır; e-posta ve bildirimler `afterCommit` ile kuyruğa girer.
- Oluşturma ve karar endpoint'leri `Idempotency-Key` kabul eder. Aynı anahtar + aynı gövde: ilk sonuç. Aynı anahtar + farklı gövde: `422`. Mobil çevrimdışı giriş/çıkış `client_uuid` ile tekilleşir.
- Kuyruk işleri tekrar çalışabilir olarak yazılır.

## 8. API sözleşmesi

Taban: `/api/hr`. Kimlik: `Authorization: Bearer <Sanctum>` (e-posta token sayfası hariç). JSON. Liste yanıtları mevcut Laravel sayfalaması: `data`, `meta`, `links`; `per_page` en fazla 100.

Hata biçimi:

```json
{ "message": "Bu izin talebi artık onaylanamaz.", "code": "leave.invalid_state", "errors": {} }
```

| Kod | Anlam |
|---|---|
| 401 / 403 | Oturum yok / sayfa yetkisi yok |
| 404 | Kayıt yok veya kapsam dışı |
| 409 | Geçersiz durum, çakışma, kapalı dönem |
| 422 | Doğrulama, yetersiz bakiye, idempotency anahtarı uyuşmazlığı |
| 429 | Hız sınırı |

### Benim Sayfam

```text
GET  /me                         Profil özeti
GET  /me/documents               Görebildiği belgeler
GET  /me/leave/balances          İzin bakiyeleri
GET  /me/leave/requests          İzin geçmişi
GET  /me/attendance?month=       Aylık puantaj
GET  /me/trainings               Eğitim ve sertifikalar
GET  /me/expenses                Masraf raporları
GET  /me/approvals               Onay bekleyen işler
```

### Personel

```text
GET|POST        /departments         PUT|DELETE /departments/{id}
GET|POST        /positions           PUT|DELETE /positions/{id}
GET|POST        /categories          PUT|DELETE /categories/{id}
GET             /employees?q=&department_id=&status=&category_id=&language_id=
POST            /employees
GET|PUT         /employees/{id}
POST            /employees/{id}/activate
POST            /employees/{id}/transfer
POST            /employees/{id}/terminate
POST            /employees/{id}/rehire
POST            /employees/{id}/link-user
POST            /employees/{id}/reveal           { field: national_id|iban, reason }
GET             /employees/{id}/timeline
GET|POST        /employees/{id}/emergency-contacts   PUT|DELETE .../{contactId}
GET|POST        /employees/{id}/relatives            PUT|DELETE .../{relativeId}
GET|PUT         /employees/{id}/languages
```

### Belge

```text
GET|POST   /document-categories      PUT|DELETE /document-categories/{id}
GET        /documents?employee_id=&category_id=&expires_before=&status=
POST       /documents                          multipart
GET|PUT    /documents/{id}
POST       /documents/{id}/versions            multipart
GET        /documents/{id}/download?version=
POST       /documents/{id}/archive
GET        /documents/{id}/access-log
GET        /documents/expiring?days=60
```

### Sözleşme

```text
GET|POST   /contract-types           PUT|DELETE /contract-types/{id}
GET        /contracts?employee_id=&status=&expires_before=&probation_before=
POST       /contracts
GET|PUT    /contracts/{id}             PUT yalnızca draft
POST       /contracts/{id}/activate
POST       /contracts/{id}/renew
POST       /contracts/{id}/terminate
GET        /contracts/{id}/history
```

### İzin

```text
GET|POST   /leave-types              PUT /leave-types/{id}
GET|POST   /leave-entitlement-rules  PUT|DELETE .../{id}
GET|POST   /public-holidays          PUT|DELETE /public-holidays/{id}
GET|POST   /work-schedules           PUT /work-schedules/{id}
POST       /leave/preview              Gün sayısı ve kalan bakiye (kayıt oluşturmaz)
GET        /leave/requests?employee_id=&status=&type=&from=&to=
POST       /leave/requests
GET|PUT    /leave/requests/{id}        PUT yalnızca draft
POST       /leave/requests/{id}/submit
POST       /leave/requests/{id}/withdraw
POST       /leave/requests/{id}/approve
POST       /leave/requests/{id}/reject
POST       /leave/requests/{id}/cancel
GET        /leave/calendar?from=&to=&department_id=&type=
GET        /leave/balances?employee_id=&year=
POST       /leave/balances/adjust

GET        /public/approvals/{token}            Token sayfası (Sanctum yok)
POST       /public/approvals/{token}            { decision: approve|reject, note }
```

Örnek:

```http
POST /api/hr/leave/requests
Idempotency-Key: 5f4c2c1e-6e8a-4a2d-9f3b-0d7c5a1b2e33

{ "leave_type_id": 1, "start_date": "2026-12-07", "end_date": "2026-12-11",
  "start_part": "full", "end_part": "full", "reason": "Yıllık izin", "submit": true }
```

```json
{ "data": { "id": 42, "number": "IZN-2026-00042", "status": "pending",
  "quantity_days": 5.0, "balance_after": 9.0 } }
```

### Puantaj

```text
POST       /attendance/punch              { type, client_uuid, occurred_at?, location? }
POST       /attendance/punch/sync         Çevrimdışı toplu gönderim
GET        /attendance/days?employee_id=&from=&to=&status=
GET|POST   /attendance/corrections        POST .../{id}/approve|reject
POST       /attendance/days/{id}/overtime/approve|reject
GET|POST   /attendance-reasons            PUT /attendance-reasons/{id}
GET        /timesheet-periods
POST       /timesheet-periods/{year}/{month}/review|close|reopen
GET        /attendance/reports/monthly?year=&month=&department_id=&format=json|xlsx
```

`occurred_at` istemciden yalnızca çevrimdışı senkronda kabul edilir, gelecek tarih reddedilir, sunucu alınma zamanını ayrıca tutar.

### Eğitim

```text
GET|POST   /training-categories      PUT|DELETE .../{id}
GET|POST   /training-courses         GET|PUT /training-courses/{id}
GET|POST   /training-sessions        GET|PUT /training-sessions/{id}
POST       /training-sessions/{id}/participants
DELETE     /training-sessions/{id}/participants/{employeeId}
POST       /training-sessions/{id}/start|complete|cancel
PUT        /training-sessions/{id}/results
GET|POST   /certificates             GET|PUT /certificates/{id}
POST       /certificates/{id}/renew
GET        /certificates/expiring?days=60
```

### Masraf

```text
GET|POST   /expense-categories       PUT /expense-categories/{id}
GET        /expenses?employee_id=&status=&from=&to=
POST       /expenses
GET|PUT    /expenses/{id}              PUT yalnızca draft veya returned
POST|PUT|DELETE /expenses/{id}/items[/{itemId}]
POST       /expenses/{id}/submit
POST       /expenses/{id}/manager-approve
POST       /expenses/{id}/accounting-approve
POST       /expenses/{id}/return
POST       /expenses/{id}/reject
POST       /expenses/{id}/payment-iban  Yalnızca approved rapor, audit'li
POST       /expenses/{id}/pay           { paid_at, payment_reference }
GET        /expenses/reports/summary?from=&to=&group_by=department|category|employee
```

### Yönetim

```text
GET  /audit-logs?subject_type=&subject_id=&employee_id=&action=&from=&to=
GET|PUT /settings
```

## 9. Bildirimler ve zamanlanmış işler

Tüm e-postalar kuyruktan gider.

| Tetik | Alıcı |
|---|---|
| İzin talebi / onay / ret / iptal | Yönetici / talep sahibi |
| Puantaj düzeltme kararı | Talep sahibi |
| Masraf durum değişikliği | Talep sahibi ve sıradaki onaylayıcı |
| Belge, sözleşme, deneme süresi, sertifika bitişi (60/30/7 gün, ayarlanabilir) | İK; ilgili ise yönetici |
| Bekleyen onay 3 gündür işlem görmedi | Onaylayıcı ve İK |

Günlük işler: hatırlatmalar, süresi dolan sözleşme ve belgeler, başlayan/biten izinler, yıllık izin hak edişleri, önceki günün puantaj hesabı, süresi dolan token ve idempotency kayıtlarının temizliği.

## 10. Test senaryoları

Her aşamada Feature testleri. Eşzamanlılık testleri MySQL'de koşar.

**Yetki:** Her endpoint için yetkisiz 403, kapsam dışı 404; İK Uzmanı sağlık belgesini indiremez; Finans özlük göremez; yönetici ekip dışını göremez; yönetici hastalık izninin türünü göremez; TC ve IBAN yanıtta maskelidir.

**Personel:** Mükerrer TC reddedilir; yönetici döngüsü reddedilir; ayrılışta kullanıcı pasifleşir, token iptal olur, gelecekteki izin iptal edilir; ekibi olan yöneticinin ayrılışı engellenir.

**Belge:** Yanlış MIME reddedilir; yeni sürüm eskisini silmez; her indirme loglanır; dosya URL ile doğrudan açılamaz; hatırlatma iki kez gitmez.

**Sözleşme:** Aynı personelde iki aktif sözleşme olamaz; yenileme atomiktir; belirli sürelide bitiş tarihi zorunludur.

**İzin:** Hafta sonu ve resmî tatil hariç hesap; arife yarım günü; yarım gün izin; takvim günü türü; yıl sonunu aşan izin; kıdem eşikleri; yetersiz bakiye; çakışan izin; iki paralel talep bakiyeyi aşamaz; reddedince bakiye geri gelir; başlamış iznin iptali; kendi iznini onaylayamama.

**E-posta onayı:** `GET` durumu değiştirmez; token ikinci kez çalışmaz; iki paralel `POST`'tan biri geçer; süresi dolmuş, iptal edilmiş veya yanlış amaçlı token reddedilir; panelden karar verilince token ölür; veritabanında düz token bulunmaz.

**Puantaj:** Normal gün; geç kalma toleransı; eksik çıkış; gece vardiyası; izinli gün; resmî tatilde çalışma; haftalık fazla mesai; kapalı döneme kayıt reddi; düzeltme ham kaydı değiştirmez; aynı `client_uuid` tek kayıt üretir.

**Eğitim:** Kapasite aşımı; başarılı katılımcıya sertifika; geçerlilik tarihi; yenileme zinciri; hatırlatma.

**Masraf:** Çoklu para birimi ve KDV yuvarlama; gönderimden sonra satır kilidi; iade edilen raporun düzeltilmesi; kendi masrafını onaylayamama; görevler ayrılığı; çift ödeme engeli; mükerrer fatura uyarısı.

**Regresyon:** Mevcut 89 test her aşamada geçer.

## 11. Teslim aşamaları

Her aşama küçük commit'lerle ilerler, API + panel + gerekirse mobil birlikte teslim edilir ve bir sonraki aşama için onay alınır.

| Aşama | İçerik | Bağımlılık |
|---|---|---|
| 0 | Modül iskeleti, audit, idempotency, sequence, bildirim, roller, menü | Yok |
| 1 | Personel | 0 |
| 2 | Belge | 1 |
| 3 | Sözleşme | 2 |
| 4 | İzin (e-posta onayı dahil) | 1, 2 |
| 5 | Puantaj | 4 |
| 6 | Eğitim | 2 |
| 7 | Masraf | 1, 2 |
| 8 | Filo: gerçek araç kartı, zimmet, muayene/sigorta takibi. Detayı sırası gelince tasarlanır. Mevcut "Aracım" stok deposuna (`warehouses.type = field`) isteğe bağlı bağlanır, stok akışı değişmez | 1, 2, 7 |

## 12. Yaklaşımlar

| Yaklaşım | Artı | Eksi | Karar |
|---|---|---|---|
| **Mevcut uygulamada modüler monolit** | Tek oturum, tek yetki, gerçek FK, tek dağıtım | Modül sınırlarına disiplin gerekir | **Seçildi** (görevde istendi) |
| Odoo'yu ayrı kurup API ile bağlamak | Hazır işlevler | İkinci sistem, Python/Odoo bakımı, kullanıcı senkronu, AGPL değerlendirmesi | Elendi; amaç kendi veritabanında kurmak |
| Ayrı Laravel İK servisi | Bağımsız dağıtım | Dağıtık transaction, kullanıcı senkronu, tek ekip için fazla karmaşık | Elendi |

Personel-kullanıcı ilişkisi için **ayrı `hr_employees` + isteğe bağlı `user_id`** seçildi. `users` tablosunu genişletmek, hesabı olmayan çalışanları ve işten ayrılan personelin kayıtlarını taşıyamaz; ayrıca hassas alanları oturum modeline karıştırır.

## 13. Alınan kararlar

**Kullanıcının kararları:** Mevcut MySQL veritabanında kal. OCA yalnızca iş akışı referansı. Bordro ilk sürümde yok. Dış muhasebe entegrasyonu yok. Filo, İK alanında ayrı modül. Geliştirme modül modül. Süper yönetici hassas veriyi görür, her erişimi loglanır. Laravel, Angular, Flutter, RESTful, mevcut kimlik doğrulama, modüler monolit, RBAC, audit, kuyruk, tek kullanımlık token, transaction ve idempotency.

**Tasarım önerileri (düzeltilebilir):** Personel-kullanıcı ayrımı; mevcut "Personeller" sayfasının "Kullanıcılar" olması; İK yönetici zincirinin stok/vaka görünürlüğünden ayrı tutulması; defter tabanlı izin bakiyesi; Excel ile açılış aktarımı; yıl dönümünde hak ediş ve en eski hakkın önce düşmesi; eksi bakiyenin kapalı olması; başlamış iznin yalnızca kısaltılabilmesi; hastalık izninin İK tarafından kaydedilmesi; ham + hesaplanmış puantaj; mobil kapsamın çalışan ve yöneticiyle sınırlı olması; sağlık belgelerinin İK Yöneticisine açık olması; Finans'ın yalnızca ödenecek raporun IBAN'ını görmesi; döviz kurunun elle girilmesi; onay bağlantısının 72 saat geçerli olması.

## 14. Açık sorular

Aşama 0 ve 1'i engelleyen açık soru yoktur. Sırası gelince sorulacaklar:

1. **E-posta ile izin talebi (Aşama 4):** Personel talebi e-posta yazarak mı açacak, yoksa panel/mobilden açıp yalnızca onay e-postayla mı ilerleyecek?
2. **Puantaj kaynağı (Aşama 5):** Giriş/çıkış mobilden mi, PDKS cihazından mı gelecek? Ameliyat saatlerine göre çalışan saha mümessilleri puantaja nasıl girecek?
3. **Filo kapsamı (Aşama 8):** Araçlarla ilgili hangi işler takip edilecek?
4. **Canlı ortam (canlıya çıkmadan önce):** Kalıcı kuyruk worker'ı, cron, web dışı dosya dizini ve audit tablosu için kısıtlı veritabanı kullanıcısı mümkün mü?
5. **Yasal doğrulama (Aşama 4-5 canlıya çıkmadan önce):** İzin süreleri, cumartesi sayımı, fazla çalışma sınırları, KVKK saklama süreleri mali müşavir veya hukukçu tarafından doğrulanacak.
