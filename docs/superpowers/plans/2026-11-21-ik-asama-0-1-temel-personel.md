# İK Aşama 0 (Temel) ve Aşama 1 (Personel) Geliştirme Planı

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mevcut uygulamaya İK modül altyapısını ve departman, pozisyon, personel kartı, işe giriş/çıkış akışlarını API ve panel olarak eklemek.

**Architecture:** `app/Modules/Hr` altında modüler monolit. `HrServiceProvider` kendi route, migration ve config'ini yükler; `/api/hr` altında çalışır. Mevcut Sanctum, `permission:` middleware ve rol sistemi kullanılır. Stok, teslimat, vaka ve faturalama koduna dokunulmaz.

**Tech Stack:** Laravel 13, PHP 8.3+, MySQL (testlerde SQLite + MySQL eşzamanlılık testi), Angular standalone + signals, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-21-insan-kaynaklari-design.md`

## Global Constraints

- OCA kodu, XML ekranı veya şeması okunmaz, kopyalanmaz, uyarlanmaz.
- Veritabanı MySQL'dir. Viemsoft veya dış muhasebe entegrasyonu yoktur. Bordro yoktur.
- Tablolar `hr_`, endpoint'ler `/api/hr` önekini kullanır.
- `users.manager_id` İK tarafından **yazılmaz**; `teamUserIds()` davranışı değişmez.
- Anlık zaman UTC, iş tarihi Europe/Istanbul takvim günüdür.
- Hata biçimi: `{ "message": "...", "code": "...", "errors": {} }`.
- Yetkisiz sayfa `403`, kapsam dışı kayıt `404`, geçersiz durum `409`, doğrulama `422`.
- TC, IBAN, kişisel e-posta, telefon ve adres şifreli saklanır; değerleri log ve audit'e yazılmaz.
- Süper yönetici hassas veriyi görebilir; TC/IBAN onun için de maskelidir, `reveal` + zorunlu `reason` ile açılır ve audit'e yazılır.
- Repo herkese açıktır: gerçek personel verisi, `.env`, anahtar commit edilmez. Test ve demo verisi sahtedir.
- Arayüz kolay anlaşılır olmalı: liste + form düzeni, üstte durum çubuğu, sekmeler, zaman çizelgesi, net Türkçe etiketler ve boş/hata/yükleniyor durumları. Bu genel kalıplar kullanılır; OCA/Odoo kodu, XML'i veya görseli kopyalanmaz.
- Çalışma `ik/asama-0-1` dalındadır, `main`'e doğrudan commit atılmaz. Her görev sonunda Türkçe commit atılır; push'u görev incelemesi geçtikten sonra koordinatör yapar. Yalnızca görevin dosyaları `git add` edilir; `docs/superpowers/specs/2026-11-10-saha-vaka-faturalama-design.md` eklenmez.
- Commit mesajı şu satırla biter: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`
- Her görev sonunda tüm testler geçer: `php artisan test`.

## Review Focus

1. **İki kişi aynı anda personel açar:** Numaralar çakışmaz, boşluk olabilir ama aynı numara iki kez verilmez. (Görev 3)
2. **Bağlantı koptu, aynı istek tekrar geldi:** İkinci personel oluşmaz; aynı anahtar başka gövdeyle gelirse `422`. (Görev 3, 7)
3. **Ekibi olan veya departman yöneten kişi ayrılıyor:** Ayrılış `409` ile durur, yarım işlem kalmaz. (Görev 10)
4. **TC baştaki/sondaki boşlukla veya aynı numarayla tekrar girilir:** Normalize edilir, mükerrer kayıt reddedilir, hata mesajında değer görünmez. (Görev 7)
5. **Ayrılan personelin açık oturumu:** Token'ları silinir, sonraki istek `401/403` alır. (Görev 10)

---

## Dosya yapısı

```text
app/Modules/Hr/
  HrServiceProvider.php
  Shared/
    HrException.php
    Audit/{AuditLog.php, AuditLogger.php, AuditLogController.php}
    Sequences/SequenceGenerator.php
    Idempotency/{IdempotencyKey.php, EnsureIdempotent.php, IdempotentResourceRegistry.php}
    Notifications/{HrNotifier.php, HrMessageMail.php}
  Employees/
    Models/{Department, Position, Category, Language, TerminationReason, Employee,
            EmergencyContact, Relative, EmployeeLanguage, EmploymentEvent}.php
    Services/{EmployeeService, EmployeeLifecycleService, EmployeeScope, SensitiveData}.php
    Http/Controllers/{Department, Position, Category, Language, TerminationReason, Employee,
                      EmployeeLifecycle, EmployeeDetails, Me}Controller.php
    Http/Requests/{StoreEmployeeRequest, UpdateEmployeeRequest}.php
    Http/Resources/EmployeeResource.php
    Events/EmployeeTerminated.php
    Rules/{TurkishNationalId, TurkishIban}.php
config/hr.php
routes/hr.php
database/migrations/hr/
tests/Feature/Hr/
tests/Concurrency/sequence_worker.php
panel/src/app/pages/hr/{me, employees, employee-detail, definitions, audit}/
panel/src/app/core/hr.models.ts
panel/e2e/hr.spec.ts
```

---

# Aşama 0: Temel

### Task 1: Modül iskeleti, yetkiler ve roller

**Files:**
- Create: `app/Modules/Hr/HrServiceProvider.php`, `app/Modules/Hr/Shared/HrException.php`, `app/Modules/Hr/Employees/Http/Controllers/MeController.php`, `config/hr.php`, `routes/hr.php`, `tests/Feature/Hr/HrFoundationTest.php`
- Modify: `bootstrap/providers.php`, `config/permissions.php`, `.env.example`

**Interfaces:**
- Produces: `new HrException(string $errorCode, string $message, int $status = 409, array $errors = [])`; JSON'a kendini render eder.
- Produces: `config('hr.storage_path')`, `config('hr.blind_index_key')` (`HR_BLIND_INDEX_KEY`).
- Produces: `GET /api/hr/me` (bu görevde `{ "data": { "employee": null } }`).

- [ ] **Step 1: Failing testleri yaz**

```php
public function test_hr_routes_require_token(): void {
    $this->getJson('/api/hr/me')->assertStatus(401);
}
public function test_me_requires_hr_self_permission(): void {
    $u = $this->userWithPermissions([]);
    $this->withToken($this->tokenFor($u))->getJson('/api/hr/me')->assertStatus(403);
}
public function test_field_and_warehouse_roles_get_self_service(): void {
    foreach (['personel', 'depocu', 'yonetici'] as $slug) {
        $this->assertTrue($this->userWithRole($slug)->hasPermission('hr_self.view'));
    }
}
public function test_department_roles_are_seeded(): void {
    foreach (['ik-uzmani', 'ik-yoneticisi', 'finans', 'muhasebe'] as $slug) {
        $this->assertDatabaseHas('roles', ['slug' => $slug, 'is_system' => true]);
    }
}
public function test_finance_cannot_view_employees(): void {
    $this->assertFalse($this->userWithRole('finans')->hasPermission('hr_employees.view_all'));
}
public function test_reseeding_keeps_panel_changes_on_existing_roles(): void {
    $role = Role::where('slug', 'depocu')->first();
    $role->permissions()->detach(Permission::where('key', 'hr_self.view')->pluck('id'));
    $this->seed();
    $this->assertFalse($role->fresh()->permissions()->where('key', 'hr_self.view')->exists());
}
public function test_hr_exception_uses_contract_shape(): void { /* test route'u HrException atar */
    // assertStatus(409)->assertJsonPath('code', 'test.conflict')->assertJsonStructure(['message', 'code', 'errors'])
}
```

- [ ] **Step 2: Çalıştır, FAIL gör:** `php artisan test --filter=HrFoundationTest`

- [ ] **Step 3: Uygula**

`HrServiceProvider`: `mergeConfigFrom`, `loadMigrationsFrom(database_path('migrations/hr'))`, `Route::prefix('api/hr')->middleware(['api', 'auth:sanctum', 'active', 'throttle:api'])->group(base_path('routes/hr.php'))`.

`config/permissions.php` sayfaları (yalnızca bu aşamada çalışan yetkiler; diğerleri kendi aşamasında eklenir):

```php
'hr_self' => ['label' => 'Benim Sayfam', 'actions' => ['view' => 'Görüntüle']],
'hr_employees' => ['label' => 'İK Personel', 'actions' => [
    'view_team' => 'Ekibini gör', 'view_all' => 'Tümünü gör', 'create' => 'Ekle',
    'update' => 'Düzenle', 'terminate' => 'İşten çıkış', 'view_sensitive' => 'Hassas bilgileri gör',
]],
'hr_settings' => ['label' => 'İK Ayarları', 'actions' => ['manage' => 'Yönet']],
'hr_audit' => ['label' => 'İK Denetim Kayıtları', 'actions' => ['view' => 'Görüntüle']],
```

Varsayılan roller:

| Rol | Yetkiler |
|---|---|
| `ik-uzmani` (İK Uzmanı) | `hr_self.view`, `hr_employees.view_all/create/update/terminate/view_sensitive` |
| `ik-yoneticisi` (İK Yöneticisi) | İK Uzmanı + `hr_settings.manage`, `hr_audit.view` |
| `finans` (Finans), `muhasebe` (Muhasebe) | `hr_self.view` |
| `yonetici` | Mevcutlar + `hr_self.view`, `hr_employees.view_team` |
| `personel`, `depocu` | Mevcutlar + `hr_self.view` |

- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: modül iskeleti, yetkiler ve departman rolleri`

### Task 2: Değiştirilemez audit log

**Files:**
- Create: `database/migrations/hr/2026_11_22_000100_create_hr_audit_logs_table.php`, `Shared/Audit/{AuditLog,AuditLogger,AuditLogController}.php`, `tests/Feature/Hr/AuditLogTest.php`
- Modify: `routes/hr.php`

**Interfaces:**
- Produces: `AuditLogger::record(string $action, string $subjectType, int $subjectId, ?int $employeeId = null, array $changedFields = [], string $channel = 'panel'): AuditLog`. `$changedFields` yalnızca **alan adlarıdır**.
- Produces: `GET /api/hr/audit-logs?subject_type=&subject_id=&employee_id=&action=&from=&to=` (`hr_audit.view`).

Tablo spec 6.8'deki alanlarla. `request_id` istek başına bir UUID'dir.

- [ ] **Step 1: Failing testler**

```php
test_record_stores_actor_ip_and_field_names_only
test_audit_row_cannot_be_updated     // $log->update(...) → RuntimeException, satır değişmez
test_audit_row_cannot_be_deleted     // $log->delete() → RuntimeException
test_audit_is_rolled_back_with_business_transaction
test_audit_list_requires_permission  // ik-uzmani 403, ik-yoneticisi 200
test_audit_list_filters_by_employee_and_action
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=AuditLogTest`
- [ ] **Step 3: Uygula.** `AuditLog`, `updating` ve `deleting` olaylarında `RuntimeException` atar; `UPDATED_AT = null`.
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: değiştirilemez denetim kaydı`

### Task 3: Numara üretimi ve idempotency

**Files:**
- Create: `database/migrations/hr/2026_11_22_000200_create_hr_sequences_and_idempotency_tables.php`, `Shared/Sequences/SequenceGenerator.php`, `Shared/Idempotency/{IdempotencyKey,EnsureIdempotent,IdempotentResourceRegistry}.php`, `tests/Feature/Hr/SequenceTest.php`, `tests/Feature/Hr/IdempotencyTest.php`, `tests/Concurrency/sequence_worker.php`
- Modify: `HrServiceProvider.php`

**Interfaces:**
- Produces: `SequenceGenerator::next(string $key, string $format): string`, örnek `next('employee_no', 'P-%06d')` → `P-000001`.
- Produces: `hr.idempotent` middleware alias.
- Produces: Controller başarıda `$request->attributes->set('hr.resource', ['type' => 'employee', 'id' => 5])` yazar.
- Produces: `IdempotentResourceRegistry::register(string $type, Closure $resolver)`; resolver `(int $id, Request $r): JsonResponse` yetkiyi yeniden kontrol ederek güncel kaydı döner.

Middleware algoritması:

```text
Başlık yoksa → normal devam.
Uzunluk 8-100 değilse → 422.
request_hash = sha256(method + path + canonical JSON gövde).
Satırı (user_id, key, route, status_code=null, expires_at=+24 saat) ekle.
Unique çakışırsa:
  hash farklı → 422 code=idempotency.key_reused
  status_code null → 409 code=idempotency.in_progress
  aksi halde → resolver ile kaydı yeniden oku, ilk status_code ile dön
Handler çalışır.
2xx ise status_code + resource yazılır; değilse satır silinir (tekrar denenebilir).
```

- [ ] **Step 1: Failing testler**

```php
test_sequence_formats_and_increments            // P-000001, P-000002
test_sequences_are_independent_by_key
test_rolled_back_transaction_does_not_leave_a_visible_record
test_parallel_workers_get_unique_numbers        // yalnızca DB_CONNECTION=mysql; aksi halde markTestSkipped
test_same_key_same_body_replays_without_side_effect
test_same_key_different_body_returns_422
test_failed_request_can_be_retried_with_same_key
test_key_is_scoped_to_user
test_replay_rechecks_authorization              // yetkisi alınan kullanıcı kaydı göremez
test_invalid_key_length_returns_422
```

`test_parallel_workers_get_unique_numbers`: `Symfony\Component\Process\Process` ile `sequence_worker.php` dört kez paralel başlatılır, her biri 50 numara üretir; toplam **200 benzersiz** numara beklenir.

- [ ] **Step 2: FAIL:** `php artisan test --filter="SequenceTest|IdempotencyTest"`
- [ ] **Step 3: Uygula.** `next`, yoksa `insertOrIgnore` ile satırı oluşturur, transaction içinde `lockForUpdate` ile okuyup artırır.
- [ ] **Step 4: PASS (SQLite):** `php artisan test`
- [ ] **Step 5: PASS (MySQL ayrı test veritabanı).** Geliştirme veritabanı kullanılmaz:

```powershell
C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE IF NOT EXISTS medikal_stok_hr_test CHARACTER SET utf8mb4"
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='medikal_stok_hr_test'; php artisan test --filter=SequenceTest; Remove-Item Env:DB_CONNECTION,Env:DB_DATABASE
```

Beklenen: paralel test **skipped değil, passed**.

- [ ] **Step 6: Commit:** `İK: güvenli numara üretimi ve idempotency`

### Task 4: Kuyruklu bildirim

**Files:**
- Create: `Shared/Notifications/{HrNotifier,HrMessageMail}.php`, `resources/views/mail/hr-message.blade.php`, `tests/Feature/Hr/HrNotifierTest.php`

**Interfaces:**
- Produces: `HrNotifier::send(User $to, string $subject, string $body): void`. `HrMessageMail implements ShouldQueue`, `afterCommit()`.

- [ ] **Step 1: Failing testler**

```php
test_message_is_queued_not_sent_synchronously   // Mail::assertQueued
test_message_is_not_queued_when_transaction_rolls_back
test_inactive_user_is_skipped
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=HrNotifierTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: kuyruklu bildirim altyapısı`

### Task 5: Panel menüsü ve Kullanıcılar adı

**Files:**
- Create: `panel/src/app/pages/hr/me/me.ts`, `me.html`, `panel/src/app/core/hr.models.ts`, `panel/e2e/hr.spec.ts`
- Modify: `panel/src/app/layout/menu.ts:49-56`, `panel/src/app/app.routes.ts:74-79`, `panel/src/app/pages/users/users.html:3`, `config/permissions.php:11`, `panel/e2e/permissions.spec.ts:10`

**Interfaces:**
- Produces: Route `/benim` (`hr_self.view`). `/personeller` adresi **değişmez**, yalnızca başlığı "Kullanıcılar" olur.

Menü: en üste `Benim Sayfam › Profilim` grubu eklenir. Finans grubu ilk sayfası gelene (Aşama 7) kadar eklenmez.

- [ ] **Step 1: Failing e2e**

```ts
test('saha personeli Benim Sayfam görür, İK menüsünü görmez', ...)
test('yönetim menüsünde Kullanıcılar yazar', ...)
```

- [ ] **Step 2: FAIL:** `npm --prefix panel run e2e -- hr.spec.ts`
- [ ] **Step 3: Uygula.** `MePage`, kart yoksa "Personel kartınız henüz oluşturulmadı" gösterir.
- [ ] **Step 4: PASS:** `npm --prefix panel run build` ve `npm --prefix panel run e2e -- hr.spec.ts permissions.spec.ts`
- [ ] **Step 5: Commit:** `Panel: Benim Sayfam ve Kullanıcılar menüsü`

---

# Aşama 1: Personel

### Task 6: Departman, pozisyon ve tanımlar

**Files:**
- Create: `database/migrations/hr/2026_11_22_000300_create_hr_org_tables.php`, `Employees/Models/{Department,Position,Category,Language,TerminationReason}.php`, ilgili controller'lar, `tests/Feature/Hr/OrgDefinitionsTest.php`
- Modify: `routes/hr.php`, `database/seeders/DatabaseSeeder.php`

Tablolar spec 6.1: `hr_departments`, `hr_positions`, `hr_categories`, `hr_position_category`, `hr_languages`, `hr_termination_reasons`. `manager_employee_id` kolonu burada FK'siz açılır, FK Görev 7'de eklenir.

**Interfaces:**
- Produces: `GET|POST /departments`, `PUT|DELETE /departments/{id}`; aynısı `/positions`, `/categories`. `GET /languages`, `GET /termination-reasons`.
- Okuma: `hr_employees.view_team|view_all|hr_settings.manage`. Yazma: `hr_settings.manage`.

Seed: Türkçe, İngilizce, Almanca, Arapça; ayrılış nedenleri `resignation`, `dismissal`, `retirement`, `contract_end`, `other`.

- [ ] **Step 1: Failing testler**

```php
test_settings_manager_can_crud_department
test_department_code_is_unique
test_department_cannot_be_its_own_ancestor     // 422
test_department_with_children_or_positions_cannot_be_deleted  // 409
test_position_categories_sync
test_writes_are_audited
test_specialist_cannot_write_definitions       // 403
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=OrgDefinitionsTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: departman, pozisyon ve tanımlar`

### Task 7: Personel kartı ve kapsamlı erişim

**Files:**
- Create: `database/migrations/hr/2026_11_22_000400_create_hr_employees_table.php`, `Models/Employee.php`, `Services/{EmployeeService,EmployeeScope,SensitiveData}.php`, `Rules/{TurkishNationalId,TurkishIban}.php`, `Http/Requests/*`, `Http/Resources/EmployeeResource.php`, `Http/Controllers/EmployeeController.php`, `tests/Feature/Hr/EmployeeTest.php`, `tests/Unit/Hr/SensitiveRulesTest.php`
- Modify: `routes/hr.php`, `HrServiceProvider.php` (idempotent resolver), `phpunit.xml` (`HR_BLIND_INDEX_KEY` sahte değer)

**Interfaces:**
- Consumes: `SequenceGenerator`, `AuditLogger`, `hr.idempotent`.
- Produces: `EmployeeScope::visibleTo(User $actor): Builder`, `EmployeeScope::descendantIds(Employee $e): array`.
- Produces: `SensitiveData::nationalIdHash(string $id): string`, `mask(string $field, ?string $value): ?string`.
- Produces: `EmployeeService::create(array $data, User $actor): Employee`, `update(Employee $e, array $data, User $actor): Employee`.
- Produces: `GET /employees`, `POST /employees`, `GET|PUT /employees/{id}`.

Tablo: spec 6.1 `hr_employees`. Eklenen şifreli kolonlar: `personal_email_encrypted`, `personal_phone_encrypted`, `address_encrypted`. Durum yeni kayıtta `draft`.

Görünürlük:

| Görüntüleyen | Görür |
|---|---|
| `view_all` | Tüm personel, genel + iş bilgileri |
| `view_team` | Kendisi + hr zincirindeki tüm alt kademe; yalnızca ad, numara, departman, pozisyon, iş iletişimi, durum |
| `view_sensitive` (+`view_all`) | Ek olarak doğum tarihi, kişisel iletişim, maskeli TC/IBAN |
| Kapsam dışı | `404` |

Maske: TC `*********21`, IBAN `TR** **** **** **** **** **45 21` biçiminde son 4 hane. Arama (`q`) yalnızca ad, soyad, `employee_no`, `work_email` üzerinde çalışır.

- [ ] **Step 1: Failing testler**

```php
// Unit
test_valid_and_invalid_turkish_national_ids     // 11 hane, 0 ile başlamaz, iki kontrol hanesi
test_turkish_iban_mod97
test_masks_keep_last_digits

// Feature
test_hr_creates_employee_with_sequential_number // employee_no = P-000001, status = draft
test_sensitive_fields_are_encrypted_at_rest     // raw DB değeri düz metni içermez
test_duplicate_national_id_rejected_after_normalizing_spaces // hata metninde TC yok
test_idempotent_create_makes_one_employee
test_response_never_contains_plain_national_id_or_iban
test_manager_sees_team_with_limited_fields
test_manager_gets_404_outside_team
test_manager_chain_cycle_rejected               // A→B→A 422
test_self_as_manager_rejected
test_finance_gets_403
test_update_audits_field_names_without_values
test_hr_update_does_not_change_users_manager_id
test_search_filters_and_pagination_max_100
```

- [ ] **Step 2: FAIL:** `php artisan test --filter="EmployeeTest|SensitiveRulesTest"`
- [ ] **Step 3: Uygula.** Yönetici atamasında döngü kontrolü transaction + `lockForUpdate` içindedir. `hr_departments.manager_employee_id` FK'si bu migration'da eklenir.
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: personel kartı, şifreli alanlar ve kapsamlı erişim`

### Task 8: Hassas alan açma (reveal)

**Files:**
- Modify: `EmployeeController.php`, `routes/hr.php`
- Test: `tests/Feature/Hr/EmployeeRevealTest.php`

**Interfaces:**
- Produces: `POST /employees/{id}/reveal` `{ field: national_id|iban, reason: string(10..255) }` → `{ "data": { "field": "...", "value": "..." } }`, `Cache-Control: no-store`. Yetki: `hr_employees.view_sensitive`. Hız sınırı: kullanıcı başına dakikada 10.

- [ ] **Step 1: Failing testler**

```php
test_authorized_reveal_returns_value_and_no_store_header
test_reveal_requires_reason
test_reveal_is_audited_with_reason_and_without_value
test_super_admin_reveal_is_audited
test_denied_reveal_is_audited                   // yetkisiz deneme kaydı
test_out_of_scope_employee_returns_404
test_rate_limit_returns_429
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=EmployeeRevealTest`
- [ ] **Step 3: Uygula.** Reddedilen deneme için yetki kontrolü route middleware yerine controller içinde yapılır, böylece audit yazılabilir.
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: hassas alanların kayıtlı açılması`

### Task 9: Acil durum kişileri, yakınlar, diller, kategoriler

**Files:**
- Create: `database/migrations/hr/2026_11_22_000500_create_hr_employee_detail_tables.php`, `Models/{EmergencyContact,Relative,EmployeeLanguage}.php`, `Http/Controllers/EmployeeDetailsController.php`, `tests/Feature/Hr/EmployeeDetailsTest.php`
- Modify: `routes/hr.php`, `Models/Employee.php`, `Services/EmployeeService.php`

**Interfaces:**
- Produces: `GET|POST /employees/{id}/emergency-contacts`, `PUT|DELETE .../{contactId}`; aynısı `/relatives`; `GET|PUT /employees/{id}/languages`.

Bu migration `hr_emergency_contacts`, `hr_employee_relatives`, `hr_employee_languages` ve `hr_employee_category` tablolarını açar. `GET /employees` bu görevde `category_id` ve `language_id` filtrelerini de kazanır.

Okuma ve yazma: `view_sensitive` veya `update` + kapsam. Ekip yöneticisi yakın bilgilerini görmez. Pozisyon değişince `source=position` kategoriler yeni pozisyona göre güncellenir, `source=manual` kategoriler korunur.

- [ ] **Step 1: Failing testler**

```php
test_employee_list_filters_by_category_and_language
test_hr_manages_emergency_contacts_and_relatives
test_manager_cannot_read_relatives             // 403 veya 404
test_nested_id_from_other_employee_returns_404
test_languages_replace_and_unique
test_position_change_updates_position_categories_keeps_manual
test_changes_are_audited
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=EmployeeDetailsTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: acil durum, yakın, dil ve kategori bilgileri`

### Task 10: İşe giriş, transfer, ayrılış, yeniden giriş

**Files:**
- Create: `database/migrations/hr/2026_11_22_000600_create_hr_employment_events_table.php`, `Models/EmploymentEvent.php`, `Services/EmployeeLifecycleService.php`, `Events/EmployeeTerminated.php`, `Http/Controllers/EmployeeLifecycleController.php`, `tests/Feature/Hr/EmployeeLifecycleTest.php`
- Modify: `routes/hr.php`

**Interfaces:**
- Produces: `EmployeeLifecycleService::activate|transfer|terminate|rehire|linkUser`.
- Produces: `POST /employees/{id}/activate|transfer|terminate|rehire|link-user`, `GET /employees/{id}/timeline`.
- Produces: `EmployeeTerminated(int $employeeId, string $terminationDate)`, transaction sonrası yayınlanır; Aşama 4 izin iptali bunu dinleyecek.

Kurallar:

| İşlem | Geçerli durum | Sonuç |
|---|---|---|
| `activate` | `draft` | `active`; `hire_date` zorunlu, `seniority_date` boşsa `hire_date` |
| `transfer` | `active` | Departman/pozisyon/yönetici değişir, olay yazılır |
| `terminate` | `active`, `on_leave_long` | `terminated`; tarih gelecekte olamaz ve `hire_date`'ten önce olamaz; `termination_reason_id` zorunlu |
| `rehire` | `terminated` | `active`; yeni `hire_date` ayrılıştan sonra olmalı; kullanıcı yalnızca `reactivate_user=true` ise açılır |
| `link-user` | `terminated` dışında | Kullanıcı başka personele bağlı olamaz |

Ayrılışta tek transaction: personel kilitlenir; alt ekip varsa `409 employee.has_team`; departman yönetiyorsa `409 employee.manages_department`; durum ve olay yazılır; bağlı kullanıcı `is_active=false`, token'ları silinir; audit yazılır.

- [ ] **Step 1: Failing testler**

```php
test_activate_sets_status_and_writes_event
test_invalid_transitions_return_409
test_transfer_records_old_and_new_values_in_timeline
test_termination_disables_user_and_revokes_tokens   // sonraki istek 401
test_termination_blocked_when_employee_has_team     // kayıt değişmez
test_termination_blocked_when_managing_department
test_future_termination_date_rejected
test_termination_event_dispatched_after_commit
test_failed_termination_rolls_back_everything
test_rehire_does_not_reactivate_user_by_default
test_rehire_with_flag_reactivates_user
test_user_cannot_be_linked_to_two_employees
test_timeline_events_are_append_only
test_terminate_requires_permission
test_existing_stock_visibility_unchanged_after_transfer // teamUserIds aynı
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=EmployeeLifecycleTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: işe giriş, transfer, ayrılış ve zaman çizelgesi`

### Task 11: Benim Sayfam API

**Files:**
- Modify: `Http/Controllers/MeController.php`
- Test: `tests/Feature/Hr/MeTest.php`

**Interfaces:**
- Produces: `GET /me` → `{ "data": { "employee": EmployeeResource|null } }`. Kendi kaydında kişisel iletişim görünür, TC/IBAN maskelidir.

- [ ] **Step 1: Failing testler**

```php
test_user_without_card_gets_null_employee
test_employee_sees_own_card_with_masked_ids
test_employee_never_gets_other_employee_via_me
```

- [ ] **Step 2: FAIL:** `php artisan test --filter=MeTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `php artisan test`
- [ ] **Step 5: Commit:** `İK: Benim Sayfam API`

### Task 12: Panel: tanımlar ve personel listesi

**Files:**
- Create: `panel/src/app/pages/hr/definitions/*`, `panel/src/app/pages/hr/employees/{employees.ts,employees.html,employee-dialog.ts,employee-dialog.html}`
- Modify: `panel/src/app/app.routes.ts`, `panel/src/app/layout/menu.ts`, `panel/src/app/core/hr.models.ts`, `panel/e2e/hr.spec.ts`

**Interfaces:**
- Produces: Route'lar `/ik/personeller` (`hr_employees.view_all|view_team`), `/ik/tanimlar` (`hr_settings.manage`).
- Menü: `İnsan Kaynakları › Personeller, Tanımlar`. Yalnızca `view_team` olan için `Ekibim › Ekip Listesi` aynı sayfaya gider.

`users.ts` sayfasındaki kalıp izlenir: signals, `toParams`, `Pagination`, `openModal`, `errorMessage`, `ToastService`. Oluşturma isteği her form açılışında üretilen `Idempotency-Key` (UUID) gönderir.

- [ ] **Step 1: Failing e2e**

```ts
test('İK departman ve personel ekler, listede görür', ...)
test('çift tıklama tek personel oluşturur', ...)
test('finans İK menüsünü görmez, /ik/personeller yetkisiz', ...)
test('yönetici yalnızca ekibini görür', ...)
```

- [ ] **Step 2: FAIL:** `npm --prefix panel run e2e -- hr.spec.ts`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `npm --prefix panel run build` ve `npm --prefix panel run e2e -- hr.spec.ts`
- [ ] **Step 5: Commit:** `Panel: İK tanımları ve personel listesi`

### Task 13: Panel: personel kartı, ayrılış, denetim, Benim Sayfam

**Files:**
- Create: `panel/src/app/pages/hr/employee-detail/*`, `panel/src/app/pages/hr/audit/*`
- Modify: `panel/src/app/pages/hr/me/*`, `app.routes.ts`, `menu.ts`, `hr.models.ts`, `panel/e2e/hr.spec.ts`

**Interfaces:**
- Produces: `/ik/personeller/:id`, `/ik/denetim` (`hr_audit.view`).

Sekmeler: Genel, İletişim, Acil Durum, Yakınlar, Diller, Zaman Çizelgesi. Yetkisi olmayan sekme gösterilmez. `Göster` düğmesi sebep ister, açılan değer **30 saniye sonra** ve sayfadan çıkınca tekrar maskelenir, sinyal dışında saklanmaz.

- [ ] **Step 1: Failing e2e**

```ts
test('İK kartta TC görmek için sebep yazar, değer yeniden maskelenir', ...)
test('işe giriş, transfer, ayrılış zaman çizelgesinde görünür', ...)
test('ekibi olan kişinin ayrılışında hata mesajı görünür', ...)
test('personel Benim Sayfam’da kendi kartını görür', ...)
test('denetim kaydı yalnızca İK Yöneticisine görünür', ...)
```

- [ ] **Step 2: FAIL:** `npm --prefix panel run e2e -- hr.spec.ts`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: PASS:** `npm --prefix panel run build` ve `npm --prefix panel run e2e -- hr.spec.ts`
- [ ] **Step 5: Commit:** `Panel: personel kartı, ayrılış akışı ve denetim kayıtları`

### Task 14: Aşama kapanışı

**Files:**
- Modify: `database/seeders/DemoSeeder.php` (yalnızca sahte İK kullanıcıları ve personel), `tests/Feature/DemoSeederTest.php`

- [ ] **Step 1: Failing test:** `test_demo_seeder_creates_fake_hr_users_and_employees`; e-postalar `.test` alan adındadır.
- [ ] **Step 2: FAIL:** `php artisan test --filter=DemoSeederTest`
- [ ] **Step 3: Uygula**
- [ ] **Step 4: Tam doğrulama**

```powershell
php artisan test
$env:DB_CONNECTION='mysql'; $env:DB_DATABASE='medikal_stok_hr_test'; php artisan test --filter=Hr; Remove-Item Env:DB_CONNECTION,Env:DB_DATABASE
npm --prefix panel run build
npm --prefix panel run e2e
vendor/bin/pint --test app/Modules config/hr.php routes/hr.php
```

Beklenen: mevcut 89 test + yeni testlerin tamamı geçer, panel derlenir, e2e geçer.

- [ ] **Step 5: Gizli veri taraması:** `git diff 66b7e8c --name-only` içinde `.env` yok; yeni dosyalarda gerçek TC/IBAN yok.
- [ ] **Step 6: Commit:** `İK: demo verisi ve Aşama 0-1 kapanışı`
