# Panel veri modeli

Panel iki MySQL 8.4 veritabanına bağlanır (K210):

| Bağlantı | Veritabanı | Ne var | Ortam |
|---|---|---|---|
| `mysql` (varsayılan) | Ana Otosanayide veritabanı (ana API ile ortak) | Ana API tabloları; panel bunları okur ve kararları ana API çekirdeğiyle yazar. Ayrıca paylaşılan `cache` ve `jobs` | `DB_*` |
| `panel` | Panel veritabanı (ayrı) | Yalnız `panel_*` tabloları ve `panel_migrations` defteri | `PANEL_DB_*` |

**Yalnız `panel_*` tabloları bu deponun migration'larıyla ve yalnız panel veritabanında oluşur**; ana veritabanına migration yazılmaz (`App\Panel\Support\PanelMigrator` `--database=mysql`'i reddeder, `--database` verilmezse `panel` kullanılır). Karakter seti ve sıralama ana API ile aynıdır (`utf8mb4_tr_0900_ai_ci`, kimlikler `utf8mb4_bin`, zamanlar `DATETIME(3)` UTC).

**İki veritabanı arasında** join, yabancı anahtar ve ortak transaction yoktur. Hesap bilgisi (e-posta, durum) ana veritabanından ayrı sorguyla okunur; panel üyeliği oluşturulurken hesabın varlığı ana veritabanında denetlenir; giriş ve her istek hesabı yeniden doğrular. Hesabı ana veritabanında bulunmayan üye giriş yapamaz ve `panel:doctor --db` tarafından raporlanır. İki tarafa yazan işlemde panel yazımı önce `PanelDb::tx` içinde yapılır, ana veritabanı yazımı o transaction'ın son adımıdır (hesabı devre dışı bırakma, platform yetkisi onayı).

**Ön koşul:** ana API'nin migration'ları ana veritabanında uygulanmış olmalı (panel bu tabloları okur). Panel veritabanı boş oluşturulur; sıra: önce ana API `migrate`, sonra panel `migrate`.

| Migration | Tablolar |
|---|---|
| `2026_11_16_100001_create_panel_access_tables` | `panel_roles`, `panel_permissions`, `panel_role_permissions`, `panel_members`, `panel_member_roles`, `panel_mfa_factors`, `panel_login_challenges`, `panel_sessions`, `panel_access_tokens` |
| `2026_11_16_100002_create_panel_operation_tables` | `panel_audit_logs`, `panel_work_items`, `panel_work_item_events`, `panel_notes`, `panel_approvals`, `panel_support_grants`, `panel_settings` |
| `2026_11_16_100003_create_panel_taxonomy_tables` | `panel_taxonomy_categories`, `panel_taxonomy_services`, `panel_taxonomy_aliases`, `panel_taxonomy_cities`, `panel_taxonomy_districts` |
| `2026_11_16_100004_create_panel_stat_snapshots` | `panel_stat_snapshots` |

Migration'lar yalnız `CREATE TABLE` üretir (`tests/Unit/MigrationsCompileTest.php` derlenen DDL'i denetler); `down()` yalnız panel tablolarını siler.

## Erişim

| Tablo | Amaç | Önemli kolonlar |
|---|---|---|
| `panel_roles` | Sistem ve özel roller | `key` UNIQUE, `is_system`, `version` |
| `panel_permissions` | Yetki kataloğu | `key` UNIQUE, `group` |
| `panel_role_permissions` | Rol–yetki | PK (`role_id`,`permission_id`), iki FK cascade |
| `panel_members` | Panel üyeliği | PK `account_id` (ana veritabanındaki `auth_accounts.id`; yabancı anahtar yok), `status` active/suspended, `ip_allowlist` JSON, `mfa_enroll_until`, `last_login_ip_hash`, `version` |
| `panel_member_roles` | Üye–rol | PK (`account_id`,`role_id`), rol silme kısıtlı |
| `panel_mfa_factors` | Panel TOTP | `secret_ciphertext` (panel `APP_KEY` ile `Crypt`), `last_used_step` (tekrar kullanım engeli), `verified_at`, `disabled_at` |
| `panel_login_challenges` | Şifre sonrası tek kullanımlık MFA/kurulum adımı | PK `token_hash` (SHA-256), `purpose`, `attempts`, `expires_at`, `consumed_at` |
| `panel_sessions` | Yenileme jetonu başına satır | `family_id`, `refresh_token_hash` UNIQUE, `mfa_at`, `idle_expires_at`, `expires_at`, `revoked_at`, `revoke_reason` (rotated, reuse, logout, roles_changed, suspended, mfa_reset, account_disabled …), `replaced_by` |
| `panel_access_tokens` | Kısa ömürlü erişim jetonu | `token_hash` UNIQUE, `session_id` FK cascade, `expires_at` |

Jetonlar ve yenileme jetonları yalnız SHA-256 özetiyle saklanır; ham IP yazılmaz (HMAC).

## Operasyon

| Tablo | Amaç | Önemli kolonlar |
|---|---|---|
| `panel_audit_logs` | Yalnız ekleme denetim kaydı | `occurred_at`, `actor_type` member/console/system, `actor_id`, `action`, `outcome`, `subject_type`/`subject_id`, `reason`, `details` JSON, `request_id`, `ip_hash` |
| `panel_work_items` | İş kuyruğu | UNIQUE (`queue`,`subject_id`), `priority`, `status` open/in_progress/waiting/done/dismissed, `assignee_id`, `source_state`, `due_at`, `last_action`, `version` |
| `panel_work_item_events` | İş geçmişi | `work_item_id` FK cascade, `action`, `from_status`, `to_status` |
| `panel_notes` | İç notlar | `subject_type`/`subject_id`, `author_id`, `deleted_at` (yumuşak silme) |
| `panel_approvals` | İkinci kontrol | `action`, `payload` JSON, `requested_by`, `status` pending/approved/rejected/cancelled/expired/failed, `decided_by`, `expires_at`, `version` |
| `panel_support_grants` | Süreli destek erişimi | `member_id`, `account_id`, `scopes` JSON, `reason`, `expires_at`, `revoked_at` |
| `panel_settings` | Ayarlar | PK `key`, `value` JSON, `version` |

Ana veritabanı tablolarına yabancı anahtar yoktur (ayrı veritabanı): konu kaydı silinse de denetim ve not izi kalır.

## Sınıflandırma

| Tablo | Kaynak dosya (ana API `resources/data`) |
|---|---|
| `panel_taxonomy_categories` (+ `panel_taxonomy_services`) | `catalogue.json` `categories[].id/name/services` |
| `panel_taxonomy_aliases` (`kind` alias/english) | `catalogue.json` `aliases`, `englishExtra` |
| `panel_taxonomy_cities` (+ `panel_taxonomy_districts`) | `provinces.json` (81 il, plaka sırası), `coverage.json` `cities`, `metropolitanCities`, `areas` |

Kategori kimliği ana API'deki `business_values.value` ve `business_search_facets.value` ile aynı anahtardır.

## İstatistik

`panel_stat_snapshots`: PK (`snapshot_date`,`metric`,`dimension`), yalnız sayı. Ölçüler: `businesses.*`, `businesses.published_by_city`, `accounts.*`, `queue.open`, `reviews.published`.

## Panelin yazdığı ana API tabloları (ana veritabanı)

Panel, ana veritabanındaki aşağıdaki tablolara **yalnız ana API çekirdeği aracılığıyla** (birebir kopya kod) veya ana API'nin aynı kuralıyla yazar:

| Akış | Tablolar |
|---|---|
| Moderasyon | `reviews`, `review_history`, `public_reviews`, `public_review_photos`, `review_replies`, `review_reply_history`, `review_cases`, `review_case_history`, `review_photos`, `review_photo_history`, `business_photos`, `business_photo_history`, `businesses` (yorum toplamları), `business_search` (yorum özeti) |
| Katalog | `businesses`, `business_values`, `business_history`, `business_search`, `business_search_facets`, `business_changes`, `business_change_values`, `feature_activations`, `business_reports`, `jobs` (CDN yansısı) |
| Sahiplik | `claims`, `claim_history`, `claim_evidence`, `claim_verifications`, `claim_verification_evidence`, `business_memberships`, `businesses` |
| Gizlilik/bildirim | `privacy_requests`, `privacy_request_history`, `privacy_state`, `privacy_exports`, `notification_jobs`, `notification_job_history` |
| Reklam/marka | `ad_*`, `brand_*`, `payment_checkout_sessions` |
| Hesap (panelin kendi kuralı) | `auth_accounts` (`status`, platform yetkileri, `version`, `tokens_valid_after`), `auth_sessions.revoked_at`, `auth_events` (`panel_disabled`, `panel_enabled`, `panel_sessions_revoked`, `panel_claims_changed`) |
| Yalnız yerel seeder | `auth_accounts` (`LocalPanelFixtureSeeder`, yalnız local/testing) |
