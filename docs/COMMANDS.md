# Komutlar, seeder'lar ve kurulum

Bu depodan hiçbir veritabanına bağlanılmadı; aşağıdaki veritabanı komutları sunucuda kullanıcı tarafından çalıştırılır. Yazan her komut varsayılan olarak **kuru çalışır**; `--apply` olmadan yazmaz.

## Kurulum sırası

1. Ana API migration'ları aynı veritabanında uygulanmış olmalı (K195: 14 migration uygulandı).
2. `.env`: `.env.example`'dan; `APP_KEY` (`php artisan key:generate`), `DB_*`, `PANEL_WEB_ORIGIN`, `TRUSTED_PROXIES` ve **ana API ile aynı olması gereken** bayrak/depolama değerleri.
3. Kontrol (bağlantısız): `php artisan panel:doctor` · `php artisan panel:core-check`
4. Şema ön koşulu (salt okunur): `php artisan panel:doctor --db`
5. Panel tabloları: `php artisan migrate --force` (yalnız `panel_*`, defter `panel_migrations`)
6. Referans veri: `php artisan db:seed --force` (yetki/rol, ayar varsayılanları, sınıflandırma; idempotent)
7. İlk süper yönetici (ana API'de var olan ve e-postası doğrulanmış hesap):
   `SEED_PANEL_ADMIN_EMAIL=... php artisan db:seed --class=PanelBootstrapAdminSeeder --force`
   veya `php artisan panel:member-grant <e-posta> --role=super_admin --reason="..." --apply`
8. İlk girişte TOTP kurulumu (72 saat içinde).
9. İsteğe bağlı: iş kuyruğu ilk doldurma `php artisan panel:work-items-sync` (kuru) → `--apply`.
10. Zamanlayıcı: `* * * * * php artisan schedule:run` ve istenen `PANEL_*_ENABLED` bayrakları.

## Konsol komutları

| Komut | Yazar mı | Açıklama |
|---|---|---|
| `panel:doctor [--db]` | hayır | Yapılandırma denetimi; `--db` ile salt okunur tablo varlığı ve panel sayımları |
| `panel:core-check [--source=] [--sync] [--write-manifest]` | dosya | Vendor edilen ana API çekirdeğinin yerel ve kaynak kayması. Veritabanı yok. `--sync` değişen dosyaları ana API'den kopyalar ve manifesti yeniler; yeni bağımlılık için `python3 scripts/core-closure.py ../otosanayide-api` |
| `panel:permissions-sync [--apply]` | `--apply` | Yetki kataloğu ve sistem rollerini `config/panel_permissions.php` ile eşitler; katalogdan kalkan yetkiyi silmez, raporlar |
| `panel:member-grant {email} --role=* --reason= [--name=] [--force] [--apply]` | `--apply` | Var olan hesabı panel üyesi yapar (ikinci kontrolü atlar). Etkin süper yönetici varken ayrıcalıklı rol `--force` ister |
| `panel:member-security {suspend\|reactivate\|mfa-reset\|revoke-sessions} {email} --reason= [--apply]` | `--apply` | Panele girilemezken acil güvenlik işlemi |
| `panel:work-items-sync [--queue=*] [--apply]` | `--apply` | İş kuyruğunu kaynak kuyruklarla uzlaştırır (ana API tablolarını yalnız okur) |
| `panel:housekeeping [--apply]` | `--apply` | Süresi dolan erişim jetonu ve MFA adımı, 7 günden eski kapalı oturum silinir; süresi dolan ikinci kontrol `expired` olur |
| `panel:stats-snapshot [--date=] [--apply]` | `--apply` | Günlük sayım anlık görüntüsü (Europe/Istanbul günü) |
| `panel:audit-prune [--days=] [--confirm-count=] [--apply]` | `--apply` | Saklama süresi (en az 365 gün) dolan denetim kaydını siler; tanımsızsa hiçbir şey silmez; geri alınamaz, sayı onayı ister |
| `panel:taxonomy import [--source=] [--apply]` | `--apply` | `resources/data` JSON'larından eksik sınıflandırma kayıtlarını ekler |
| `panel:taxonomy export [--out=]` | dosya | Ana API biçiminde `catalogue.json` ve `coverage.json` yazar (varsayılan `storage/app/private/taxonomy-export`) |

Laravel'in kendi `queue:retry`/`queue:forget` komutları `/ops/failed-jobs/*` uçlarında kullanılır.

## Zamanlanmış işler

| İş | Sıklık | Bayrak (varsayılan kapalı) |
|---|---|---|
| `panel:housekeeping --apply` | saatlik | `PANEL_HOUSEKEEPING_ENABLED=true` |
| `panel:work-items-sync --apply` | 5 dakika | `PANEL_WORK_ITEMS_SYNC_ENABLED=true` |
| `panel:stats-snapshot --apply` | her gün 03:15 Europe/Istanbul | `PANEL_STATS_SNAPSHOT_ENABLED=true` |

Hepsi `withoutOverlapping` ve `onOneServer` ile tanımlıdır.

## Seeder'lar

| Seeder | Ne yazar | Ne zaman |
|---|---|---|
| `DatabaseSeeder` | aşağıdaki üçünü çağırır | `db:seed --force` |
| `PanelAccessSeeder` | 52 yetki, 7 sistem rolü ve rol yetkileri (hassas yetki listesi config dosyasında) | idempotent |
| `PanelSettingSeeder` | ayar varsayılanları; değiştirilmiş değere dokunmaz | idempotent |
| `TaxonomySeeder` | ana API `resources/data`: 20 kategori ve 85 hizmet, 48 eş anlamlı, 81 il, 41 kapsam ili ve ilçe koordinatları | idempotent; gerçek işletme verisi yok |
| `PanelBootstrapAdminSeeder` | ilk süper yönetici (`SEED_PANEL_ADMIN_EMAIL`) | yalnız açık `--class`; etkin süper yönetici varsa çalışmaz |
| `LocalPanelFixtureSeeder` | her rol için `<rol>@panel.test` sentetik hesap ve üyelik (`LOCAL_PANEL_PASSWORD`) | yalnız `local`/`testing`; üretimde reddeder |

## Geliştirme

```bash
composer install
```
```bash
composer test
```
```bash
composer analyse
```
```bash
composer docs
```
```bash
php artisan panel:core-check
```

`composer test` veritabanına bağlanmaz (`DB_PORT=1`; aksi hâlde test paketi başlamaz). `composer docs` `docs/source/endpoints.json` kaynağından `docs/ENDPOINTS.md` ve Postman dosyalarını üretir; rota tablosu ile katalog `RouteCatalogTest` ile denetlenir.

Yerel sunucu (veritabanı olmadan yalnız `/api/health` ve kimlik doğrulama öncesi yanıtlar çalışır):

```bash
php artisan serve --port=8010
```
