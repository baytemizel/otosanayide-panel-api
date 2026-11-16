# otosanayide-panel-api

Otosanayide yönetim paneli REST API'si. Laravel 13, PHP 8.3, MySQL 8.4. İki veritabanına bağlanır: ana Otosanayide veritabanı (`DB_*`, ana API ile ortak; ana API tablolarını okur ve kararları yazar) ve ayrı **panel veritabanı** (`PANEL_DB_*`; panelin kendi verisi, yalnız `panel_*` tabloları). Kararlar ana API ile **aynı alan çekirdeğiyle** verilir. Karar kayıtları kaynak repoda `docs/decision-log.md` K209 ve sonrası.

Durum: kod hazırlığı ve bağlantısız yerel test. Veritabanına bağlanılmadı, migration ve seeder çalıştırılmadı, sunucuya dağıtılmadı, panel ön yüzü yok.

## Neler var

- **149 uç** (`/api/v1`): oturum (şifre + panelin kendi TOTP'si, dönen yenileme jetonu), panel üyeleri/roller/yetkiler, ikinci kontrol (dört göz), denetim kaydı, ayarlar, iş kuyruğu ve iç notlar, platform hesapları ve süreli destek erişimi, işletmeler ve katalog yayını, sahiplik başvuruları, içerik moderasyonu, gizlilik talepleri, bildirim işleri, işletme reklamı ve ödeme incelemeleri, marka reklamı, sınıflandırma listeleri, istatistik, operasyon.
- **7 sistem rolü, 52 yetki** (`config/panel_permissions.php`): super_admin, operations_admin, moderator, catalogue_editor, finance, support, auditor.
- **4 migration, 22 tablo** (`panel_*`, yalnız panel veritabanında, defter `panel_migrations`), **6 seeder**, **10 konsol komutu**, 3 zamanlanmış iş (varsayılan kapalı).
- **Belgeler:** [docs/API.md](docs/API.md), [docs/ENDPOINTS.md](docs/ENDPOINTS.md), [docs/SCHEMA.md](docs/SCHEMA.md), [docs/COMMANDS.md](docs/COMMANDS.md), Postman koleksiyonu ve ortamı `docs/postman/`.

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

`composer test` veritabanına bağlanmaz. Kurulum ve sunucu adımları: [docs/COMMANDS.md](docs/COMMANDS.md).

## Kurallar

- Dış çıktı `{"data": …}` / `{"error": "…"}`; ana API çekirdeğine giden uçlar ana API'nin şema iletilerini korur.
- `app/Domain`, `app/Support`, `app/Exceptions`, `app/Jobs`, `app/Models` ana API'den birebir kopyadır (`core-manifest.json`); elle değiştirilmez, `php artisan panel:core-check`.
- Şema yalnız `panel_*` migration'larıyla ve yalnız panel veritabanında değişir. Ana veritabanına migration yazılmaz.
- Panel tablosuna yalnız `App\Panel\Support\PanelDb` ile erişilir; ana veritabanı varsayılan bağlantıdır (`DB::table`). İki veritabanı arasında join ve yabancı anahtar yoktur (`DatabaseSeparationTest`).
- Yeni uç: rota + `docs/source/endpoints.json` + `composer docs`.
- Sır, `.env`, gerçek işletme veya kişi verisi commit edilmez.
