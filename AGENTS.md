# otosanayide-panel-api

Laravel 13 / PHP 8.3 / MySQL 8.4. Yönetim paneli REST API'si; ana API (`../otosanayide-api`) ile aynı veritabanını kullanır.

- Karar kaynağı: `../../docs/decision-log.md` (K209 ve sonrası). Belge: `docs/API.md`, uç listesi `docs/source/endpoints.json`.
- Panel yalnız `panel_*` tablolarının şemasını yönetir (`panel_migrations` defteri). Ana API tablolarına migration yazılmaz.
- `app/Domain`, `app/Support`, `app/Exceptions`, `app/Jobs`, `app/Models` ana API'den birebir kopyadır (`core-manifest.json`). Elle değiştirilmez; `php artisan panel:core-check`.
- Yeni uç: rota + `docs/source/endpoints.json` + `composer docs`. `RouteCatalogTest` eşleşmeyi denetler.
- `composer test` veritabanına bağlanmaz (DB_PORT=1). Üretime yazan komutu kullanıcı çalıştırır; bu depodan veritabanı bağlantısı kurulmaz.
- Sır, `.env`, gerçek işletme veya kişi verisi commit edilmez.
