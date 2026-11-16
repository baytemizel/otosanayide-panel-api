# Otosanayide Panel API — v1

Yönetim paneli (ürün planındaki "Admin paneli" ve "Kısıtlı admin rolleri") için REST API. Laravel 13, PHP 8.3, MySQL 8.4. Karar kaydı: bundle `docs/decision-log.md` K209.

- Uç listesi ve örnekler: [ENDPOINTS.md](ENDPOINTS.md) (üretilir)
- Postman: [postman/otosanayide-panel-api.postman_collection.json](postman/otosanayide-panel-api.postman_collection.json) ve [yerel ortam](postman/otosanayide-panel-api.local.postman_environment.json)
- Veri modeli: [SCHEMA.md](SCHEMA.md) · Komutlar ve kurulum: [COMMANDS.md](COMMANDS.md)

Durum: kod hazırlığı ve bağlantısız yerel test. Hiçbir veritabanına bağlanılmadı; migration ve seeder'lar çalıştırılmadı; gerçek sunucuda, tarayıcıda ve ana API ile birlikte uçtan uca denenmedi.

## 1. Mimari

```
Panel ön yüzü ──HTTPS + Bearer──▶ otosanayide-panel-api ──┐
                                                          ├──▶ MySQL (ana API veritabanı)   
Web / portal / mobil ───────────▶ otosanayide-api ────────┘
```

- **Aynı veritabanı.** Panel, ana API'nin tablolarını okur ve yönetim kararlarını yazar. Kendi verisi yalnız `panel_*` tablolarındadır ve ayrı migration defteri (`panel_migrations`) kullanır. Ana API tablolarına migration yazmaz.
- **Aynı iş kuralları.** Moderasyon, sahiplik, katalog yayını, gizlilik, reklam ve marka finansı kararları ana API'nin alan çekirdeğiyle verilir: `app/Domain`, `app/Support`, `app/Exceptions`, `app/Jobs`, `app/Models` ana API `d4ed6f8` commit'inden **birebir kopyadır** (87 dosya, `core-manifest.json`). Böylece sürüm denetimi, hesap silme çerçeveleri (fence), kendi içeriğini inceleyememe, yorum toplamları, arama projeksiyonu ve CDN yansısı ana API ile aynı çalışır. Kopya elle değiştirilmez; `php artisan panel:core-check` kaymayı bildirir.
- **Panel kendi katmanı.** Kimlik doğrulama (ayrı TOTP ve oturum), rol/yetki, denetim kaydı, ikinci kontrol (dört göz), iş kuyruğu, iç notlar, süreli destek erişimi, sınıflandırma listeleri, ayarlar ve istatistik anlık görüntüleri.
- **Kuyruk.** Katalog yayını gibi kararlar ana API'nin `SyncBusinessMediaCdn` işini paylaşılan `jobs` tablosuna yazabilir; sınıf adı iki uygulamada aynıdır, ana API işçisi işler.

## 2. Temel kurallar

| Konu | Kural |
|---|---|
| Taban adres | `{PANEL_API}/api/v1` (sağlık: `/api/health`). Alan adı tanımsız. |
| İçerik türü | Gövdeli isteklerde `Content-Type: application/json` zorunlu (yoksa 415). |
| Kimlik | `Authorization: Bearer opa_…` (erişim jetonu). Giriş adımları ve sağlık hariç her uç. |
| Zaman | ISO-8601 UTC, milisaniyeli: `2026-11-16T09:00:00.000Z`. Ana API'nin `*_ms` alanları milisaniye sayıdır. |
| Kimlikler | İşletme/yorum/başvuru `[A-Za-z0-9_-]{1,120}`, hesap `{1,128}`, fotoğraf/rapor/onay `uuid`, ödeme incelemesi `sha256`. |
| Önbellek | Her yanıt `Cache-Control: no-store`. |
| İstek kimliği | `X-Request-Id` gönderilirse (8-64, `[A-Za-z0-9-]`) aynen döner; yoksa üretilir. 500 yanıtında `requestId` alanıdır. |

### Yanıt zarfı

```json
{ "data": { "...": "..." } }
```

Sayfa numaralı listeler (`?page=1&perPage=25`, en fazla 100):

```json
{ "data": [ ], "meta": { "page": 1, "perPage": 25, "total": 312, "lastPage": 13 } }
```

İmleçli listeler (ana API çekirdeğinden gelen kuyruklar, 20'lik sayfa): `data` içindeki `nextCursor`/`next` değeri sonraki istekte `after` olarak gönderilir. Bu listelerin içeriği ana API `/api/admin/*` yanıtıyla aynıdır.

İkinci kontrole düşen işlem: **202**

```json
{ "data": { "approvalRequired": true, "approval": { "id": "…", "action": "member.roles", "status": "pending", "version": 1 } } }
```

### Hatalar

Tek biçim: `{"error": "Türkçe ileti"}`.

| Durum | Ne zaman |
|---|---|
| 400 | Ana API çekirdek şeması (Z) reddi: `Bilgileri kontrol edin.` veya alan iletisi; geçersiz sorgu/imleç |
| 401 | Jeton yok/geçersiz/süresi dolmuş; giriş veya MFA hatalı (neden açıklanmaz) |
| 403 | Panel yetkisi yok (denetime `access.denied` yazılır); IP listesi dışı; yabancı origin; kendi içeriğine karar |
| 404 | Kayıt veya yol yok |
| 405 | Yöntem desteklenmiyor |
| 409 | Sürüm çakışması (`version`/`expectedVersion` güncel değil), durum geçişi geçersiz, hesap silme sürecinde, bekleyen ikinci kontrol |
| 413 | Gövde 20 KB'tan büyük (çekirdek uçları) |
| 415 | `application/json` değil |
| 422 | Panel uçları doğrulaması: `{"error":"Bilgileri kontrol edin.","fields":{"alan":["ileti"]}}` |
| 428 | Gizlilik kararları için yakın zamanlı TOTP gerekir (yeniden giriş) |
| 429 | Hız sınırı |
| 503 | İlgili özellik bayrağı kapalı (ana API ile aynı ileti) veya veritabanı işlemi tamamlanamadı |
| 500 | Beklenmeyen hata: `{"error":"…","requestId":"…"}` |

**Doğrulama iki türlüdür:** panelin kendi uçları (üyeler, roller, iş kuyruğu, notlar, ayarlar, hesaplar, sınıflandırma) Laravel doğrulayıcısıyla **422 + fields**; ana API çekirdeğine giden uçlar (moderasyon, başvuru, katalog, gizlilik, reklam, marka) ana API şemasıyla **400** döner. Çekirdek şemaları katıdır (strict): fazladan alan 400'dür.

**Yol ve gövde:** çekirdek uçlarında yol parametresi (`{id}`, `{businessId}` …) gövdeye eklenir; gövdede aynı alan farklı değerle gelirse 400.

## 3. Oturum ve kimlik doğrulama

Panel kimliği ana API hesabıdır (`auth_accounts`): aynı e-posta ve şifre. Panel ayrıca şunları ister:

1. `panel_members` üyeliği `active` ve en az bir rol,
2. hesap `status=active`, e-posta doğrulanmış, hesap silme çerçevesi (`account_fences`) yok,
3. genel IP listesi (`PANEL_IP_ALLOWLIST`) ve üyeye özel IP listesi,
4. **panelin kendi TOTP faktörü** (ana API'nin MFA faktöründen ayrı; panel `APP_KEY` ile şifreli).

```
POST /api/v1/auth/login {email,password}
  ├─ step=mfa              → challengeToken (5 dk, 5 deneme)
  └─ step=mfa_enrollment   → challengeToken + secret + otpauthUri (yalnız kurulum penceresinde)
POST /api/v1/auth/mfa {challengeToken, code}
  → accessToken (15 dk) [+ refreshToken: yalnız Origin yoksa gövdede]
POST /api/v1/auth/refresh {refreshToken} veya çerez
  → yeni çift; eski yenileme jetonu tekrar kullanılırsa bütün aile kapanır
POST /api/v1/auth/logout
```

- **İlk TOTP kurulumu** yalnız üye oluşturulduktan veya MFA sıfırlandıktan sonra `PANEL_MFA_ENROLLMENT_HOURS` (varsayılan 72 saat) içinde yapılabilir; süre geçerse 403 ve yöneticiden MFA sıfırlaması gerekir.
- **Süreler (öneri):** erişim jetonu 15 dk; 30 dk yenilenmeyen oturum kapanır (K169 tasarım önerisi); mutlak oturum 12 saat.
- **Tarayıcı:** `Origin` başlığı varsa yenileme jetonu gövdeye yazılmaz, `otosanayide_panel_refresh` HttpOnly, Secure, SameSite=Strict, yolu `/api/v1/auth` olan çerezde döner. CORS kimlik bilgisi yalnız `/api/v1/auth/*` altında açıktır; panel ön yüzü bu istekleri `credentials: 'include'` ile, diğerlerini Bearer ile gönderir.
- **Postman/CLI:** `Origin` göndermediği için yenileme jetonu gövdede döner.
- **Oturum iptali:** rol veya yetki değişimi, askıya alma, MFA sıfırlama ve hesabın devre dışı bırakılması panel oturumlarını kapatır. Kullanıcı şifresini ana uygulamada değiştirirse (`tokens_valid_after`) panel oturumu da geçersizleşir.
- **Hız sınırı:** giriş için e-posta başına dakikada 5 (IP başına 20); MFA doğrulaması IP başına dakikada 20; hesap başına 15 dakikada 10 hatalı TOTP kodundan sonra giriş ve doğrulama 15 dakika 429 döner (her girişte yeni adım açılsa da toplam deneme sınırlıdır); üye başına dakikada 120 istek.
- **Yenileme toleransı:** aynı yenileme jetonu 10 saniye içinde yeniden gelirse (iki sekme aynı anda yeniledi ya da yanıt kayboldu) aile kapanmaz; ilk ardıl iptal edilir, yeni çift verilir. Daha geç tekrar kullanım bütün aileyi kapatır.
- Başarısız giriş, MFA hatası, yenileme jetonu tekrar kullanımı ve yetki reddi denetim kaydına yazılır (e-posta yerine HMAC anahtarı).

## 4. Roller ve yetkiler

Yetki kataloğu ve sistem rolleri `config/panel_permissions.php` dosyasındadır (seeder ve `panel:permissions-sync` buradan yazar). Rota başına yetki `perm:` katmanıdır ve [ENDPOINTS.md](ENDPOINTS.md) içinde her uçta yazılıdır.

| Rol | Özet | Ayrıcalıklı (ikinci kontrol) |
|---|---|---|
| `super_admin` | Bütün yetkiler; en az bir etkin süper yönetici kalır | evet |
| `operations_admin` | Katalog, moderasyon, sahiplik, hesaplar, destek, gizlilik, iş kuyruğu, reklam onayı; finans ve panel erişim yönetimi hariç | evet |
| `moderator` | Yorum, yanıt, bildirim/itiraz, fotoğraf kararları, yanlış bilgi bildirimleri | hayır |
| `catalogue_editor` | Taslak, yayın, profil değişikliği, özellik başvurusu, sahiplik, sınıflandırma | hayır |
| `finance` | Reklam tarifesi, ödeme uyuşmazlıkları, marka satış/finans akışı | evet |
| `support` | Hesap görüntüleme, süreli destek erişimi, veri talepleri, bildirim işleri | hayır |
| `auditor` | Salt okunur: denetim kaydı, üyeler, ayarlar, kuyruklar | hayır |

Ürün kuralları test ile korunur: moderatörde finans/reklam yetkisi yok, finans rolünde içerik kararı yok, denetçide yazma yetkisi yok. Sistem rollerinin yetkileri katalogdan gelir (panelden değişmez); farklı bir set için özel rol oluşturulur.

**Ayrıcalıklı rol** anahtara göre değil yetkiye göre belirlenir: `approvals.privileged_roles` listesindeki rol, `super_admin` ya da **hassas yetki** taşıyan herhangi bir rol (`config/panel_permissions.php` `sensitive`: üye/rol/ayar/onay yönetimi, hesap kapatma ve platform yetkisi, başkasının destek erişimi, gizlilik kararları, başarısız iş yönetimi, reklam tarifesi ve bütün finans yetkileri). Böylece hassas yetkili özel rol oluşturup atamak ya da rolü ayardan çıkarmak ikinci kontrolü atlatamaz.

**İş kuyruğu yetkisi:** iş üstlenmek veya güncellemek için `work_items.work` yanında o kuyruğun karar yetkisi de gerekir (ör. `claims` kuyruğu için `claims.review`); `work_items.manage` bu koşulu taşımaz.

**Hesap kapatma:** hedef hesap bir panel üyesiyse `accounts.manage` yetmez, `members.manage` de gerekir ve son etkin süper yöneticinin hesabı kapatılamaz.

Çekirdeğe giden kararlarda panel üyesi ana API'ye `admin` olarak tanıtılır; yetki kapısı panel rolüdür. Çekirdeğin kendi içindeki kurallar (kendi yorumunu/başvurusunu inceleyememe, yetkilisi olduğu işletmeye karar verememe, sürüm, durum geçişi, hesap çerçevesi) aynen uygulanır.

## 5. İkinci kontrol (dört göz)

Ürün planı: "Sahiplik devri, ayrıcalıklı rol verme ve yüksek tutarlı iade için ikinci kontrol kuralı yapılandırılabilir."

Bu sürümde ikinci kontrole düşen işlemler:

| İşlem | Ne zaman | Onaylayanın ek yetkisi |
|---|---|---|
| `member.create` | Yeni üyeye ayrıcalıklı rol veriliyorsa | `members.manage` |
| `member.roles` | Var olan üyeye yeni ayrıcalıklı rol ekleniyorsa | `members.manage` |
| `account.claims` | Ana API platform yetkileri (`is_admin`, `is_brand_finance`, `is_account_erasure_operator`) her değişiklikte | `accounts.claims` |
| `role.permissions` | Özel role hassas yetki ekleniyorsa | `roles.manage` (varsayılan yalnız süper yönetici; bu yüzden iki süper yönetici gerekir) |
| `setting.update` | `approvals.*` ayarlarından biri değişiyorsa | `settings.manage` |

Talep eden onaylayamaz/reddedemez. **Talebin konusu olan kişi de karar veremez:** yetki verilen hesap kendi talebini onaylayamaz; rol yetkisi değişikliğinde onaylayan o role sahip olmamalıdır. Onayda işlem aynı transaction'da kurallar yeniden uygulanarak yürür; uygulanamazsa talep `failed` olur ve 409 döner; veritabanı kilitlenmesi gibi geçici hatada talep beklemede kalır. Talepler `approvals.ttl_hours` (varsayılan 72) sonra `expired` olur (`panel:housekeeping`). Sahiplik devri ve yüksek tutarlı iade için ikinci kontrol **uygulanmadı**: ana API'de sahiplik devri işlemi yoktur; iade eşiği tutarı tanımsızdır.

## 6. İş kuyruğu ve notlar

Ürün planı: "Her işte sorumlu, öncelik, durum, son işlem ve gerekçe bulunur."

- Kaynak: 17 ana API kuyruğu (açık sahiplik başvurusu, bekleyen yorum/yanıt/fotoğraf, açık bildirim/itiraz, profil değişikliği, yanlış bilgi bildirimi, özellik başvurusu, gizlilik ve veri kopyası talepleri, reklam/marka kampanyası, ödeme incelemeleri, marka iptalleri, başarısız bildirim işleri). Tanım: `app/Panel/WorkItems/Queues.php`.
- `panel:work-items-sync` kaynakta açık her kayıt için iş açar, kapanan kaydın işini kapatır (`source_resolved`), yeniden açılanı yeniden açar. Son tarih `work_items.sla_hours` ayarından gelir.
- Panelden verilen her karar ilgili işi günceller: kaynak hâlâ açıksa `sourceState` ve son işlem yazılır, kapandıysa iş `done` olur.
- `work_items.work`: üstlenme, bırakma, kendi işinin durumu ve notu. `work_items.manage`: atama, öncelik, son tarih.
- İç notlar (`/notes`) yalnız panelde görünür; silinen not gizlenir, kayıt kalır.

## 7. Destek erişimi

Ürün planı: "Admin hesabı gizlice işletme sahibinin hesabına girmez; destek erişimi varsa açık, süreli ve kayıtlıdır."

- Panel kullanıcı adına oturum açmaz (impersonation yok).
- Kişisel kullanım verisi (garaj, talepler, bildirimler, favoriler) yalnız etkin destek erişimiyle, salt okunur görülür.
- Erişim gerekçeli (20+ karakter), kapsamlı ve süreli açılır (5 dk – `support_access.max_minutes`, varsayılan 240); her okuma `support.data_viewed` olarak denetime yazılır.
- Kullanıcıya erişim bildirimi gönderilmez (karar verilmedi).

## 8. Denetim kaydı

`panel_audit_logs` yalnız ekleme yapılan kayıttır: aktör, işlem, sonuç (`success|denied|failed`), konu, gerekçe, ayrıntı (önce/sonra), istek kimliği, IP özeti (HMAC), tarayıcı. Panelden yapılan her yazım, hesap ve başvuru ayrıntısı okuması, ham kaynak okuması, destek verisi okuması, giriş olayları ve yetki retleri yazılır. Denetim kaydını okumak da yazılır. Saklama süresi tanımsızdır (silinmez); `audit.retention_days` en az 365 gün olarak ayarlanırsa `panel:audit-prune` açık sayı onayıyla siler.

Ana API çekirdeği kendi geçmiş tablolarına (`review_history`, `claim_history`, `business_history` …) aktör olarak panel üyesinin hesap kimliğini yazar; panel denetimi bunun üstüne ek kayıttır.

## 9. Özellik bayrakları ve ortam eşitliği

Panel, ana API çekirdeğini çalıştırdığı için şu değerler **sunucudaki ana API `.env` ile aynı olmalıdır**: `DATA_REQUESTS_ENABLED`, `*_PHOTOS_ENABLED`, `CLAIM_EVIDENCE_ENABLED`, `AI_MODERATION_ENABLED` + `OPENAI_API_KEY`, `AD_*`, `BRAND_*`, `PAYMENTS_ENABLED` ve ödeme geçidi değerleri, `FILESYSTEM_DISK`, `BRAND_MEDIA_DISK`, `R2_*`, `MEDIA_CDN_*`, `CLOUDFLARE_*`, `TRUSTED_PROXIES`. Farklı olursa panel kararı ile ana API davranışı ayrışır (ör. CDN yansısı). Bayrak kapalıyken ilgili yazım uçları ana API ile aynı 503 iletisini döner. `php artisan panel:doctor` açık bayrakları listeler.

## 10. Sınıflandırma listeleri

Kategoriler, hizmetler, eş anlamlılar ve kapsam (il/ilçe) panelde `panel_taxonomy_*` tablolarında düzenlenir. Ana API bugün bu listeleri **dosyadan** (`resources/data/catalogue.json`, `coverage.json`) okur; panel değişikliği canlıya kendiliğinden geçmez. `GET /taxonomy/export` veya `php artisan panel:taxonomy export` ana API biçiminde dosya üretir; ana API deposuna inceleme ve testle alınır. Kategori kimliği kayıtlarda kullanıldığı için silinmez, pasifleştirilir.

## 11. Güvenlik başlıkları ve CORS

Her yanıt: `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `X-Request-Id`. CORS yalnız `PANEL_WEB_ORIGIN` ile birebir eşleşen origin'e açılır; başka origin'den gelen durum değiştiren istek 403 alır. Proxy arkasında gerçek IP için `TRUSTED_PROXIES` (Cloudflare) gerekir; aksi hâlde IP listesi ve hız sınırı proxy adresine düşer.

## 12. Ana API `/api/admin/*` ile ilişki

Ana API'deki yönetim uçları (web `/yonetim` için) yerinde kalır; panel API aynı çekirdeği kullandığı için iki yoldan verilen karar aynı kurala tabidir. Hangi istemcinin hangi API'yi kullanacağı ve web `/yonetim` alanının taşınıp taşınmayacağı kullanıcı kararıdır (bkz. bundle `docs/REMAINING_WORK_TR.md`).

| Panel | Ana API karşılığı |
|---|---|
| `GET /moderation/reviews`, `POST /moderation/reviews/{id}/decision` | `GET/PATCH /api/admin/reviews` |
| `POST /moderation/reviews/{id}/ai-analysis` | `POST /api/admin/reviews/analyze` |
| `…/replies`, `…/review-cases`, `…/review-photos`, `…/business-photos` | `/api/admin/replies`, `review-cases`, `review-photos`, `business-photos` |
| `…/business-changes` | `/api/admin/business-changes?v=2` |
| `…/business-reports` | `/api/admin/business-reports` |
| `…/feature-activations` | `/api/businesses/activation?queue=true` ve PATCH (yönetici kararı) |
| `/claims*` | `/api/admin/claims`, `claim-targets`, `claim-evidence` |
| `/businesses/{id}/draft`, `/publication`, `/history`, `/catalogue/queue` | `/api/admin/catalogue/draft`, `catalogue`, `catalogue/history` |
| `/privacy/*` | `/api/admin/privacy` (+ `kind=export`) |
| `/notification-jobs*` | `/api/admin/notifications` |
| `/ads/*`, `/finance/*` | `/api/campaigns?queue=true`, `/api/admin/ad-tariffs`, `payment-cases`, `brand-payment-review` |
| `/brand/*` | `/api/admin/brand-*` |
| `/dashboard/overview` | `/api/admin/overview` |

Panelde olmayanlar: marka görseli yükleme ve önizleme görüntüsü (`/api/admin/brand-media`, `brand-preview`), yorum/işletme fotoğrafı ve sahiplik kanıtı dosyasının kendisini görüntüleme (dosya akışı ve görüntüleme kaydı ana API'dedir), özellik bayrağı listesi (`/api/admin/flags`; panel ana API'nin ortamını okuyamaz).

## 13. Doğrulama durumu

| Yapılan | Sonuç |
|---|---|
| `composer test` (bağlantısız, DB_PORT=1) | 37 test, 990 doğrulama geçti: migration'ların MySQL DDL'ine derlenmesi, rota–belge eşleşmesi, yetki kataloğu, hassas yetki ve ürün kuralları, kimlik doğrulama öncesi HTTP davranışı, CORS, IP listesi, çekirdek kopyası özetleri |
| Bağımsız kod incelemesi (veritabanı yolları) | 10 bulgu düzeltildi: denetim kaydı istek kimliği uzunluğu, hassas yetkiyle ikinci kontrol atlatma, talebin konusunun onaylaması, panel üyesinin hesabının `accounts.manage` ile kapatılması, MFA tahmin sınırı, yenileme yarışı, iş kuyruğu yetkisi, reklam kampanyası `id` filtresi, çıkışta yenileme ailesi, boşta kalan oturum listesi ve sürüm metni |
| PHPStan seviye 6 | Hatasız (vendor edilen `ActorResolver` için tek yol kısıtlı istisna) |
| Pint | Panel dosyalarında temiz (çekirdek kopyası hariç) |
| `panel:core-check` | 87 dosya ana API `d4ed6f8` ile aynı |

Yapılmayan: veritabanı bağlantısı (kullanıcı talimatı), migration ve seeder çalıştırma, giriş/MFA/yenileme akışının gerçek veritabanıyla denenmesi, çekirdek kararlarının panelden uçtan uca denenmesi, sunucu dağıtımı, panel ön yüzü.
