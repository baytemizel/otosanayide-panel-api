# otosanayide-panel-api

Laravel 13 / PHP 8.3 / MySQL 8.4. Yönetim paneli REST API'si; iki veritabanına bağlanır: ana Otosanayide veritabanı (varsayılan `mysql` bağlantısı, ana API `../otosanayide-api` ile ortak) ve panel veritabanı (`panel` bağlantısı).

- Karar kaynağı: `../../docs/decision-log.md` (K209 ve sonrası). Belge: `docs/API.md`, uç listesi `docs/source/endpoints.json`.
- Panel yalnız panel veritabanındaki `panel_*` tablolarının şemasını yönetir (`panel_migrations` defteri). Ana veritabanına migration yazılmaz.
- Panel tablosu yalnız `PanelDb::table` / `PanelDb::tx` ile; ana veritabanı `DB::table` / `Tx::run` ile. İki veritabanı arasında join, yabancı anahtar ve ortak transaction yok; iki tarafa yazan işlemde ana yazım panel transaction'ının son adımıdır (K210).
- `app/Domain`, `app/Support`, `app/Exceptions`, `app/Jobs`, `app/Models` ana API'den birebir kopyadır (`core-manifest.json`). Elle değiştirilmez; `php artisan panel:core-check`.
- Yeni uç: rota + `docs/source/endpoints.json` + `composer docs`. `RouteCatalogTest` eşleşmeyi denetler.
- `composer test` veritabanına bağlanmaz (DB_PORT=1). Üretime yazan komutu kullanıcı çalıştırır; bu depodan veritabanı bağlantısı kurulmaz.
- Sır, `.env`, gerçek işletme veya kişi verisi commit edilmez.
