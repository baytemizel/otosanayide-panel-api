# Uç referansı

> Bu dosya `docs/source/endpoints.json` kaynağından `composer docs` ile üretilir; elle düzenlemeyin.

Toplam **149** uç. Yanıt zarfı, hata biçimi, oturum ve yetki kuralları için [API.md](API.md).

## 01 Sağlık ve oturum

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/health` | yok | Sağlık |
| POST | `/api/v1/auth/login` | yok | Giriş (e-posta + şifre) |
| POST | `/api/v1/auth/mfa` | yok | MFA doğrulama (TOTP) |
| POST | `/api/v1/auth/refresh` | yok | Oturum yenileme |
| POST | `/api/v1/auth/logout` | yok | Çıkış |
| GET | `/api/v1/auth/me` | giriş | Ben (üye, roller, yetkiler) |
| GET | `/api/v1/auth/sessions` | giriş | Etkin oturumlarım |
| DELETE | `/api/v1/auth/sessions/{familyId}` | giriş | Oturumumu kapat |

### GET `/api/health`

**Sağlık.** Veritabanına dokunmaz. {"data":{"status":"ok"}}.

### POST `/api/v1/auth/login`

**Giriş (e-posta + şifre).** Ana API hesabının e-posta ve şifresi. Panel üyeliği, etkin hesap, doğrulanmış e-posta, rol ve IP koşulu sağlanmazsa nedeni söylemeden 401. Yanıt step=mfa (TOTP kodu istenir) veya step=mfa_enrollment (ilk kurulum: secret ve otpauthUri döner). Hız sınırı: e-posta başına dakikada 5.

```json
{
    "email": "{{panelEmail}}",
    "password": "{{panelPassword}}"
}
```

### POST `/api/v1/auth/mfa`

**MFA doğrulama (TOTP).** Giriş adımındaki challengeToken ve 6 haneli TOTP kodu. Kurulum adımında ilk doğru kod faktörü etkinleştirir. 5 hatalı denemede adım kapanır; hesap başına 15 dakikada 10 hatalı kodda giriş ve doğrulama 429 döner; IP başına dakikada 20 deneme. Origin varsa yenileme jetonu yalnız HttpOnly çerezde döner; Origin yoksa (Postman) gövdede refreshToken döner.

```json
{
    "challengeToken": "{{challengeToken}}",
    "code": "123456"
}
```

### POST `/api/v1/auth/refresh`

**Oturum yenileme.** Dönen yenileme jetonu: her yenilemede yeni çift verilir; eski jeton yeniden kullanılırsa oturum ailesi kapanır (401). İstisna: aynı jeton 10 saniye içinde yeniden gelirse (eşzamanlı yenileme, kaybolan yanıt) ilk ardıl iptal edilir ve yeni çift verilir. Hareketsizlik süresi ve mutlak süre aşılırsa 401.

```json
{
    "refreshToken": "{{refreshToken}}"
}
```

### POST `/api/v1/auth/logout`

**Çıkış.** Bearer varsa o oturumun ailesi kapanır; yoksa gövdedeki (veya çerezdeki) yenileme jetonunun ailesi kapanır. Bilinmeyen jeton sessizce kabul edilir.

```json
{
    "refreshToken": "{{refreshToken}}"
}
```

### GET `/api/v1/auth/me`

**Ben (üye, roller, yetkiler).** Giriş yapmış üyenin hesap kimliği, görünen adı, rolleri ve yetki listesi.

### GET `/api/v1/auth/sessions`

**Etkin oturumlarım.** Aile başına etkin panel oturumları; current=true bu isteğin oturumu.

### DELETE `/api/v1/auth/sessions/{familyId}`

**Oturumumu kapat.** Kendi oturum ailelerinden birini kapatır.

## 02 Genel bakış ve istatistik

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/dashboard` | `dashboard.view` | Genel bakış |
| GET | `/api/v1/dashboard/overview` | `dashboard.view` | Ana API yönetim özeti |
| GET | `/api/v1/stats/catalogue` | `stats.view` | Katalog istatistiği |
| GET | `/api/v1/stats/accounts` | `stats.view` | Hesap istatistiği |
| GET | `/api/v1/stats/snapshots` | `stats.view` | Günlük anlık görüntüler |

### GET `/api/v1/dashboard`

**Genel bakış.** Kaynak kuyruklarda açık kayıt sayıları, panel iş kuyruğu özeti, bekleyen ikinci kontrol ve etkin destek erişimi sayısı, katalog toplamı.

### GET `/api/v1/dashboard/overview`

**Ana API yönetim özeti.** Ana API /api/admin/overview ile aynı sayımlar (AdminOverview::queues).

### GET `/api/v1/stats/catalogue`

**Katalog istatistiği.** Toplam, yayımlanan, taslak, örnek, koordinatı doğrulanmış, sahipli, aranabilir; il kırılımı ve aranabilir kategori sayıları.

### GET `/api/v1/stats/accounts`

**Hesap istatistiği.** Hesap toplamları (doğrulanmış, devre dışı, silme sürecinde, son 7 gün), platform yöneticisi, işletme üyesi ve panel üyesi sayıları.

### GET `/api/v1/stats/snapshots`

**Günlük anlık görüntüler.** panel:stats-snapshot çıktısı. Varsayılan son 90 gün.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `metric` | `businesses.published` | Ölçü adı (zorunlu); ör. businesses.published, accounts.total, queue.open |
| `dimension` |  | İsteğe bağlı boyut (il, kuyruk) |
| `from` | `2026-11-01` | Başlangıç tarihi |
| `to` |  | Bitiş tarihi |

## 03 Panel üyeleri, roller ve yetkiler

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/members` | `members.view` | Üyeler |
| POST | `/api/v1/members` | `members.manage` | Üye ekle |
| GET | `/api/v1/members/{accountId}` | `members.view` | Üye ayrıntısı |
| PATCH | `/api/v1/members/{accountId}` | `members.manage` | Üye güncelle |
| PUT | `/api/v1/members/{accountId}/roles` | `members.manage` | Rolleri değiştir |
| POST | `/api/v1/members/{accountId}/suspend` | `members.manage` | Askıya al |
| POST | `/api/v1/members/{accountId}/reactivate` | `members.manage` | Yeniden etkinleştir |
| POST | `/api/v1/members/{accountId}/mfa-reset` | `members.manage` | MFA sıfırla |
| POST | `/api/v1/members/{accountId}/sessions/revoke` | `members.manage` | Üye oturumlarını kapat |
| GET | `/api/v1/roles` | `roles.view` | Roller |
| POST | `/api/v1/roles` | `roles.manage` | Rol oluştur |
| PATCH | `/api/v1/roles/{key}` | `roles.manage` | Rol güncelle |
| DELETE | `/api/v1/roles/{key}` | `roles.manage` | Rol sil |
| GET | `/api/v1/permissions` | `roles.view` | Yetki kataloğu |

### GET `/api/v1/members`

**Üyeler.** Sayfalı panel üyeleri.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `active` | active\|suspended |
| `role` |  | Rol anahtarı |
| `q` |  | Ad veya e-posta başı |
| `page` | `1` |  |
| `perPage` | `25` | En fazla 100 |

### POST `/api/v1/members`

**Üye ekle.** Ana API'de var olan, e-postası doğrulanmış hesabı panel üyesi yapar; şifre oluşturmaz. Ayrıcalıklı rol (approvals.privileged_roles listesindeki ya da hassas yetki taşıyan rol) içeriyorsa 202 ve ikinci kontrol talebi döner. Üye ilk girişte TOTP kurar (varsayılan 72 saat).

```json
{
    "email": "moderator@example.test",
    "displayName": "Moderatör Bir",
    "roles": [
        "moderator"
    ],
    "ipAllowlist": [],
    "reason": "Yorum kuyruğu için yeni moderatör."
}
```

### GET `/api/v1/members/{accountId}`

**Üye ayrıntısı.** Üye, roller, MFA durumu ve etkin oturumlar.

### PATCH `/api/v1/members/{accountId}`

**Üye güncelle.** Görünen ad ve üyeye özel IP/CIDR listesi. version zorunlu (iyimser kilit).

```json
{
    "version": 1,
    "displayName": "Moderatör Bir",
    "ipAllowlist": [
        "203.0.113.0/24"
    ]
}
```

### PUT `/api/v1/members/{accountId}/roles`

**Rolleri değiştir.** Rol listesini değiştirir; üyenin panel oturumları kapanır. Yeni eklenen rol ayrıcalıklıysa 202 ve ikinci kontrol. Son etkin süper yönetici rolünü kaybedemez.

```json
{
    "version": 1,
    "roles": [
        "moderator",
        "catalogue_editor"
    ],
    "reason": "Katalog kuyruğuna destek verecek."
}
```

### POST `/api/v1/members/{accountId}/suspend`

**Askıya al.** Oturumlar kapanır. Kendini askıya alamaz; son süper yönetici askıya alınamaz.

```json
{
    "version": 1,
    "reason": "Görev değişikliği nedeniyle erişim kapatıldı."
}
```

### POST `/api/v1/members/{accountId}/reactivate`

**Yeniden etkinleştir.** Askıdaki üyeyi açar.

```json
{
    "version": 2,
    "reason": "Görevine geri döndü, erişim açıldı."
}
```

### POST `/api/v1/members/{accountId}/mfa-reset`

**MFA sıfırla.** Panel TOTP faktörlerini kapatır, yeni kurulum penceresi açar, oturumları kapatır. Kendi kaydına uygulanamaz.

```json
{
    "version": 1,
    "reason": "Telefon kayboldu, yeni cihaza kurulacak."
}
```

### POST `/api/v1/members/{accountId}/sessions/revoke`

**Üye oturumlarını kapat.** Üyenin bütün panel oturumlarını kapatır.

```json
{
    "reason": "Şüpheli giriş bildirimi üzerine."
}
```

### GET `/api/v1/roles`

**Roller.** Roller, yetkileri (super_admin için ['*']), üye sayısı, ayrıcalıklı olup olmadığı.

### POST `/api/v1/roles`

**Rol oluştur.** Özel rol. Yetkiler katalogdan seçilir.

```json
{
    "key": "catalogue_reader",
    "name": "Katalog okuyucu",
    "description": "Yalnız katalog görüntüleme",
    "permissions": [
        "dashboard.view",
        "businesses.view"
    ]
}
```

### PATCH `/api/v1/roles/{key}`

**Rol güncelle.** Ad ve açıklama; özel rolde yetki listesi. Sistem rolü yetkileri katalogdan gelir (422). Role hassas yetki ekleniyorsa 202 ve ikinci kontrol (role.permissions; onaylayan bu role sahip olmamalı). Yetki değişince role sahip üyelerin oturumları kapanır.

```json
{
    "version": 1,
    "name": "Katalog okuyucu",
    "permissions": [
        "dashboard.view",
        "businesses.view",
        "stats.view"
    ],
    "reason": "İstatistik görüntüleme eklendi."
}
```

### DELETE `/api/v1/roles/{key}`

**Rol sil.** Yalnız sistem dışı ve üyesiz rol.

### GET `/api/v1/permissions`

**Yetki kataloğu.** Yetki anahtarları, grup ve açıklama.

## 04 İkinci kontrol, denetim kaydı ve ayarlar

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/approvals` | `approvals.view` | İkinci kontrol talepleri |
| GET | `/api/v1/approvals/{approvalId}` | `approvals.view` | Talep ayrıntısı |
| POST | `/api/v1/approvals/{approvalId}/approve` | `approvals.decide` | Talebi onayla |
| POST | `/api/v1/approvals/{approvalId}/reject` | `approvals.decide` | Talebi reddet |
| POST | `/api/v1/approvals/{approvalId}/cancel` | giriş | Talebi iptal et |
| GET | `/api/v1/audit-logs` | `audit.view` | Denetim kaydı |
| GET | `/api/v1/audit-logs/{auditId}` | `audit.view` | Denetim kaydı ayrıntısı |
| GET | `/api/v1/settings` | `settings.view` | Ayarlar |
| PUT | `/api/v1/settings/{settingKey}` | `settings.manage` | Ayar değiştir |

### GET `/api/v1/approvals`

**İkinci kontrol talepleri.** Sayfalı. İşlemler: member.create, member.roles, account.claims, role.permissions, setting.update.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved\|rejected\|cancelled\|expired\|failed |
| `action` |  | member.create\|member.roles\|account.claims\|role.permissions\|setting.update |
| `mine` | `false` | true: yalnız benim açtıklarım |
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/approvals/{approvalId}`

**Talep ayrıntısı.** Talep, yük (payload) ve karar.

### POST `/api/v1/approvals/{approvalId}/approve`

**Talebi onayla.** Talep eden ve talebin konusu olan kişi (yetki verilen hesap, değişen rolün sahibi) karar veremez. Onaylayan ayrıca işlemin yetkisine sahip olmalı (members.manage, accounts.claims, roles.manage veya settings.manage). İşlem aynı transaction'da kuralları yeniden uygulayarak yürür; uygulanamazsa talep failed olur ve 409 döner.

```json
{
    "version": 1,
    "reason": "Görev tanımı kontrol edildi, onaylandı."
}
```

### POST `/api/v1/approvals/{approvalId}/reject`

**Talebi reddet.** Talep eden reddedemez.

```json
{
    "version": 1,
    "reason": "Rol gereksinimi doğrulanamadı."
}
```

### POST `/api/v1/approvals/{approvalId}/cancel`

**Talebi iptal et.** Talep eden veya approvals.decide yetkilisi iptal eder.

```json
{
    "version": 1,
    "reason": "Yanlış kişi için açılmıştı."
}
```

### GET `/api/v1/audit-logs`

**Denetim kaydı.** Yalnız ekleme yapılan kayıt; en yeni önce. Okuma da kayda yazılır (audit.viewed).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `actorId` |  | Hesap kimliği |
| `action` |  | Tam ad veya önek: claim.* |
| `subjectType` |  | account, business, review, claim ... |
| `subjectId` |  |  |
| `outcome` |  | success\|denied\|failed |
| `from` |  | ISO tarih |
| `to` |  | ISO tarih |
| `page` | `1` |  |
| `perPage` | `50` |  |

### GET `/api/v1/audit-logs/{auditId}`

**Denetim kaydı ayrıntısı.** Tek kayıt.

### GET `/api/v1/settings`

**Ayarlar.** Anahtar, değer, varsayılan, sürüm. Anahtarlar: approvals.privileged_roles, approvals.ttl_hours, support_access.max_minutes, work_items.sla_hours, audit.retention_days.

### PUT `/api/v1/settings/{settingKey}`

**Ayar değiştir.** Hiç değiştirilmemiş ayarın sürümü 0'dır. Değer anahtara göre doğrulanır. approvals.* anahtarları ikinci kontrolle değişir (202, setting.update).

```json
{
    "version": 1,
    "value": 240,
    "reason": "Destek erişimi en fazla 4 saat."
}
```

## 05 İş kuyruğu ve iç notlar

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/work-items` | `work_items.view` | İş kuyruğu |
| GET | `/api/v1/work-items/summary` | `work_items.view` | İş kuyruğu özeti |
| GET | `/api/v1/work-items/{workItemId}` | `work_items.view` | İş ayrıntısı |
| PATCH | `/api/v1/work-items/{workItemId}` | `work_items.work` / `work_items.manage` | İşi güncelle |
| POST | `/api/v1/work-items/{workItemId}/claim` | `work_items.work` / `work_items.manage` | İşi üstlen |
| POST | `/api/v1/work-items/{workItemId}/release` | `work_items.work` / `work_items.manage` | İşi bırak |
| GET | `/api/v1/notes` | `notes.view` | İç notlar |
| POST | `/api/v1/notes` | `notes.write` | Not ekle |
| DELETE | `/api/v1/notes/{noteId}` | `notes.write` | Notu sil |

### GET `/api/v1/work-items`

**İş kuyruğu.** Öncelik, son tarih ve açılışa göre sıralı. İşler panel:work-items-sync ile kaynak kuyruklardan açılır/kapanır; panelden verilen karar ilgili işi günceller.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `queue` |  | claims, reviews, replies, review_cases, review_photos, business_photos, business_changes, business_reports, feature_activations, privacy_requests, privacy_exports, ad_campaigns, brand_campaigns, ad_payment_cases, brand_payment_cases, brand_cancellations, notification_jobs |
| `status` | `active` | active\|open\|in_progress\|waiting\|done\|dismissed\|all |
| `assignee` |  | me\|unassigned\|<hesap kimliği> |
| `priority` |  | low\|normal\|high\|urgent |
| `overdue` |  | true |
| `subjectId` |  | Kaynak kayıt kimliği |
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/work-items/summary`

**İş kuyruğu özeti.** Kuyruk başına etkin, sahipsiz, gecikmiş; bana atanmış.

### GET `/api/v1/work-items/{workItemId}`

**İş ayrıntısı.** İş ve olay geçmişi (son 200).

### PATCH `/api/v1/work-items/{workItemId}`

**İşi güncelle.** work_items.work: yalnız kendisine atanmış veya sahipsiz işte status ve note. work_items.manage: ayrıca assigneeId, priority, dueAt. İş kuyruğunun karar yetkisi (ör. claims için claims.review) olmayan üye işi değiştiremez (work_items.manage hariç).

```json
{
    "version": 2,
    "status": "waiting",
    "note": "Başvurandan ek belge bekleniyor."
}
```

### POST `/api/v1/work-items/{workItemId}/claim`

**İşi üstlen.** Sahipsiz veya bana atanmış iş in_progress olur. Kuyruğun karar yetkisi gerekir (work_items.manage hariç).

```json
{
    "version": 1
}
```

### POST `/api/v1/work-items/{workItemId}/release`

**İşi bırak.** Atama kalkar, iş open olur.

```json
{
    "version": 2
}
```

### GET `/api/v1/notes`

**İç notlar.** Bir kayda ait iç notlar (yalnız panelde görünür).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `subjectType` | `claim` | account, business, review, reply, review_case, review_photo, business_photo, claim, business_change, business_report, feature_activation, privacy_request, privacy_export, ad_campaign, brand_campaign, ad_payment_case, brand_payment_case, brand_cancellation, notification_job, work_item, panel_member |
| `subjectId` | `{{claimId}}` |  |
| `page` | `1` |  |
| `perPage` | `25` |  |

### POST `/api/v1/notes`

**Not ekle.** 2-4000 karakter.

```json
{
    "subjectType": "claim",
    "subjectId": "{{claimId}}",
    "body": "Vergi levhası adı ile başvuru adı farklı; telefonla teyit edilecek."
}
```

### DELETE `/api/v1/notes/{noteId}`

**Notu sil.** Yalnız yazarı veya süper yönetici; kayıt silinmez, gizlenir.

## 06 Platform hesapları ve destek erişimi

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/accounts` | `accounts.view` | Hesaplar |
| GET | `/api/v1/accounts/{accountId}` | `accounts.view` | Hesap ayrıntısı |
| GET | `/api/v1/accounts/{accountId}/auth-events` | `accounts.view` | Giriş olayları |
| GET | `/api/v1/accounts/{accountId}/sessions` | `accounts.view` | Platform oturumları |
| GET | `/api/v1/accounts/{accountId}/reviews` | `accounts.view` | Hesabın yorumları |
| GET | `/api/v1/accounts/{accountId}/claims` | `accounts.view` | Hesabın sahiplik başvuruları |
| POST | `/api/v1/accounts/{accountId}/disable` | `accounts.manage` | Hesabı devre dışı bırak |
| POST | `/api/v1/accounts/{accountId}/enable` | `accounts.manage` | Hesabı etkinleştir |
| POST | `/api/v1/accounts/{accountId}/sessions/revoke` | `accounts.manage` | Platform oturumlarını kapat |
| PUT | `/api/v1/accounts/{accountId}/claims` | `accounts.claims` | Platform yetkisi değiştir (ikinci kontrol) |
| GET | `/api/v1/accounts/{accountId}/garage` | `support.access` | Destek: garaj |
| GET | `/api/v1/accounts/{accountId}/requests` | `support.access` | Destek: talepler |
| GET | `/api/v1/accounts/{accountId}/notifications` | `support.access` | Destek: bildirimler |
| GET | `/api/v1/accounts/{accountId}/favorites` | `support.access` | Destek: favoriler |
| GET | `/api/v1/support-access` | `support.access` / `support.manage` | Destek erişimleri |
| POST | `/api/v1/support-access` | `support.access` | Destek erişimi aç |
| POST | `/api/v1/support-access/{grantId}/revoke` | `support.access` / `support.manage` | Destek erişimini kapat |

### GET `/api/v1/accounts`

**Hesaplar.** Sayfalı platform hesapları. q e-posta başıyla veya tam hesap kimliğiyle arar.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `q` |  | E-posta başı veya hesap kimliği |
| `status` |  | active\|disabled\|erasing |
| `verified` |  | true\|false |
| `admin` |  | true\|false |
| `panelMember` |  | true\|false |
| `createdFrom` |  | ISO tarih |
| `createdTo` |  | ISO tarih |
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/accounts/{accountId}`

**Hesap ayrıntısı.** Hesap, platform yetkileri, MFA, etkin oturum sayısı, silme çerçevesi, sayımlar, işletme üyelikleri, son gizlilik talepleri. Okuma denetime yazılır.

### GET `/api/v1/accounts/{accountId}/auth-events`

**Giriş olayları.** auth_events (IP özeti dönmez).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/accounts/{accountId}/sessions`

**Platform oturumları.** Son 100 ana API oturumu (jeton özetleri dönmez).

### GET `/api/v1/accounts/{accountId}/reviews`

**Hesabın yorumları.** Sayfalı yorum özeti.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/accounts/{accountId}/claims`

**Hesabın sahiplik başvuruları.** Son 100 başvuru.

### POST `/api/v1/accounts/{accountId}/disable`

**Hesabı devre dışı bırak.** status=disabled; ana API oturumları kapanır, tokens_valid_after şimdi olur; panel oturumları da kapanır. Silme sürecindeki hesaba uygulanmaz. Kendi hesabına uygulanamaz. Hedef panel üyesiyse ayrıca members.manage gerekir ve son etkin süper yönetici kapatılamaz.

```json
{
    "version": 0,
    "reason": "Toplu sahte yorum bildirimi incelemesi sürüyor."
}
```

### POST `/api/v1/accounts/{accountId}/enable`

**Hesabı etkinleştir.** status=active.

```json
{
    "version": 1,
    "reason": "İnceleme tamamlandı, hesap açıldı."
}
```

### POST `/api/v1/accounts/{accountId}/sessions/revoke`

**Platform oturumlarını kapat.** Bütün ana API oturumlarını kapatır ve mevcut erişim jetonlarını geçersiz kılar.

```json
{
    "reason": "Kullanıcı hesabının ele geçirildiğini bildirdi."
}
```

### PUT `/api/v1/accounts/{accountId}/claims`

**Platform yetkisi değiştir (ikinci kontrol).** Ana API'nin is_admin, is_brand_finance, is_account_erasure_operator kolonları. Her zaman 202 ve ikinci kontrol talebi; onayda oturumlar kapanır.

```json
{
    "version": 0,
    "admin": false,
    "brandFinance": true,
    "accountErasureOperator": false,
    "reason": "Marka finans işlemleri için yetki verilecek."
}
```

### GET `/api/v1/accounts/{accountId}/garage`

**Destek: garaj.** Etkin ve 'garage' kapsamlı destek erişimi gerekir. Araçlar, son 100 bakım, hatırlatmalar, yedek ve geri dönüşüm durumu. Her okuma denetime yazılır.

### GET `/api/v1/accounts/{accountId}/requests`

**Destek: talepler.** 'requests' kapsamı. Son 100 teklif/randevu talebi.

### GET `/api/v1/accounts/{accountId}/notifications`

**Destek: bildirimler.** 'notifications' kapsamı. Son 100 bildirim ve okundu durumu.

### GET `/api/v1/accounts/{accountId}/favorites`

**Destek: favoriler.** 'favorites' kapsamı. Favoriler ve listeler.

### GET `/api/v1/support-access`

**Destek erişimleri.** Varsayılan yalnız benimkiler; support.manage ile scope=all.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `scope` | `mine` | mine\|all |
| `active` | `true` | true: yalnız etkin |
| `accountId` |  |  |
| `page` | `1` |  |
| `perPage` | `25` |  |

### POST `/api/v1/support-access`

**Destek erişimi aç.** Açık, süreli (5 dk - ayar, varsayılan en fazla 240 dk) ve gerekçeli (20+ karakter). Kullanıcının oturumunu açmaz; yalnız salt okunur veri görünümü verir. Kapsam: garage, requests, notifications, favorites.

```json
{
    "accountId": "{{accountId}}",
    "scopes": [
        "garage"
    ],
    "minutes": 30,
    "reason": "Destek kaydı #2026-1116: kullanıcının araç hatırlatması görünmüyor."
}
```

### POST `/api/v1/support-access/{grantId}/revoke`

**Destek erişimini kapat.** Kendi erişimi veya support.manage ile başkasınınki.

## 07 İşletmeler ve katalog

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/businesses` | `businesses.view` | İşletmeler |
| GET | `/api/v1/businesses/{businessId}` | `businesses.view` | İşletme ayrıntısı |
| GET | `/api/v1/businesses/{businessId}/source` | `businesses.source` | Kaynak kanıtı (ham satır) |
| GET | `/api/v1/businesses/{businessId}/members` | `businesses.view` | İşletme ekibi |
| GET | `/api/v1/businesses/{businessId}/reviews` | `businesses.view` | İşletmenin yorumları |
| GET | `/api/v1/businesses/{businessId}/reports` | `businesses.view` | İşletme bildirimleri |
| GET | `/api/v1/businesses/{businessId}/claims` | `businesses.view` / `claims.view` | İşletme başvuruları |
| GET | `/api/v1/businesses/{businessId}/history` | `businesses.view` | Katalog geçmişi |
| GET | `/api/v1/businesses/{businessId}/draft` | `businesses.edit` | Katalog taslağı |
| PUT | `/api/v1/businesses/{businessId}/draft` | `businesses.edit` | Katalog taslağını kaydet |
| POST | `/api/v1/businesses/{businessId}/publication` | `businesses.publish` | Yayımla / yayından kaldır |
| GET | `/api/v1/catalogue/queue` | `businesses.view` | Katalog kuyruğu |

### GET `/api/v1/businesses`

**İşletmeler.** Sayfalı işletme listesi (yayımlanmış ve taslak).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `q` |  | Ad parçası veya tam kimlik |
| `city` |  | İl |
| `district` |  | İlçe |
| `published` |  | true\|false |
| `ownership` |  | unclaimed\|pending\|verified |
| `coordinateStatus` |  | verified\|unverified |
| `sample` |  | true\|false |
| `category` |  | Kategori kimliği |
| `imported` |  | true\|false |
| `hasOwner` |  | true\|false |
| `sort` | `-updatedAt` | name\|-name\|createdAt\|-createdAt\|updatedAt\|-updatedAt |
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/businesses/{businessId}`

**İşletme ayrıntısı.** Kayıt, kategoriler/hizmetler/markalar, fotoğraf kimlikleri, aramada olup olmadığı, kaynak özeti ve bayrakları, bekleyen profil değişikliği, özellik başvurusu, yorum sayımları, açık başvuru ve bildirim sayısı.

### GET `/api/v1/businesses/{businessId}/source`

**Kaynak kanıtı (ham satır).** catalogue_sources ham satırı ve aday değerler. Okuma denetime yazılır.

### GET `/api/v1/businesses/{businessId}/members`

**İşletme ekibi.** Üyelikler (e-postayla) ve son 50 ekip denetim kaydı.

### GET `/api/v1/businesses/{businessId}/reviews`

**İşletmenin yorumları.** Sayfalı.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` |  | pending\|approved\|rejected\|hidden\|withdrawn |
| `page` | `1` |  |
| `perPage` | `25` |  |

### GET `/api/v1/businesses/{businessId}/reports`

**İşletme bildirimleri.** Son 100 yanlış bilgi bildirimi.

### GET `/api/v1/businesses/{businessId}/claims`

**İşletme başvuruları.** Son 100 sahiplik başvurusu.

### GET `/api/v1/businesses/{businessId}/history`

**Katalog geçmişi.** Ana API CatalogueAdmin::history (imleçli).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `after` |  | Önceki sayfanın son kimliği |

### GET `/api/v1/businesses/{businessId}/draft`

**Katalog taslağı.** Ana API CatalogueAdmin::draft: düzenlenebilir profil, sürümler ve kaynak önerileri.

### PUT `/api/v1/businesses/{businessId}/draft`

**Katalog taslağını kaydet.** Ana API catalogueDraft şeması (strict): profileVersion, publicationVersion, reason, profile. Kayıt profil sürümünü artırır; yayımlanmış kayıtta arama projeksiyonu güncellenir.

```json
{
    "profileVersion": 1,
    "publicationVersion": 1,
    "reason": "Adres ve ilçe kaynak kanıtına göre düzeltildi.",
    "profile": {
        "name": "Örnek Oto Servis",
        "city": "Ankara",
        "district": "Ostim",
        "address": "Ostim OSB 1234. Cadde No 5",
        "phone": "+90 312 000 00 00",
        "website": "",
        "contactEmail": "",
        "hours": "",
        "description": "",
        "categories": [
            "mechanical"
        ],
        "services": [
            "Periyodik bakım"
        ],
        "brands": [],
        "location": {
            "latitude": 39.97,
            "longitude": 32.74
        }
    }
}
```

### POST `/api/v1/businesses/{businessId}/publication`

**Yayımla / yayından kaldır.** Ana API publicationAction şeması. publish dört teyit ister (rights, identity, location, duplicates: checked=true ve 10-800 karakter reference). withdraw yalnız gerekçe ister. Arama projeksiyonu ve CDN yansısı ana API kuralıyla güncellenir.

```json
{
    "action": "publish",
    "version": 1,
    "profileVersion": 2,
    "reason": "Dört teyit tamamlandı, yayına alınıyor.",
    "checks": {
        "rights": {
            "checked": true,
            "reference": "Kaynak kullanım hakkı K200 kararı"
        },
        "identity": {
            "checked": true,
            "reference": "Vergi levhası görüldü 2026-11-16"
        },
        "location": {
            "checked": true,
            "reference": "Harita ve sokak görünümü karşılaştırıldı"
        },
        "duplicates": {
            "checked": true,
            "reference": "Aynı ad/adres araması: mükerrer yok"
        }
    }
}
```

### GET `/api/v1/catalogue/queue`

**Katalog kuyruğu.** Ana API CatalogueAdmin::page: yayımlanmamış (varsayılan) veya yayımlanmış kayıtlar, 20'lik imleç.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `published` | `false` | true\|false |
| `after` |  | İmleç |

## 08 Sahiplik başvuruları

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/claims` | `claims.view` | Açık başvurular |
| GET | `/api/v1/claims/{claimId}` | `claims.view` | Başvuru ayrıntısı |
| GET | `/api/v1/claims/targets/{businessId}` | `claims.review` | Hedef işletme uygunluğu |
| POST | `/api/v1/claims/{claimId}/target` | `claims.review` | Başvuruyu işletmeye eşle |
| POST | `/api/v1/claims/{claimId}/evidence-verification` | `claims.evidence` | Kanıt doğrula |
| POST | `/api/v1/claims/{claimId}/decision` | `claims.review` | Başvuru kararı |

### GET `/api/v1/claims`

**Açık başvurular.** Ana API ClaimAdmin::open.

### GET `/api/v1/claims/{claimId}`

**Başvuru ayrıntısı.** Başvuru, kanıt üst verisi (dosya değil), doğrulamalar ve geçmiş. Okuma denetime yazılır.

### GET `/api/v1/claims/targets/{businessId}`

**Hedef işletme uygunluğu.** Ana API ClaimAdmin::target.

### POST `/api/v1/claims/{claimId}/target`

**Başvuruyu işletmeye eşle.** Ana API claimTarget şeması: mode=existing (businessId) veya mode=new (duplicateChecked, profile). identityChecked=true zorunlu.

```json
{
    "mode": "existing",
    "businessId": "{{businessId}}",
    "identityChecked": true,
    "reason": "Başvuru adı ve adresi katalog kaydıyla eşleşiyor."
}
```

### POST `/api/v1/claims/{claimId}/evidence-verification`

**Kanıt doğrula.** Ana API evidenceVerification şeması; kanıt deposu yapılandırılmamışsa 503. Dönen verificationReference onay kararında kullanılır.

```json
{
    "evidenceIds": [
        "00000000-0000-4000-8000-000000000001"
    ],
    "evidenceRevision": 1,
    "attested": true,
    "reason": "Vergi levhası ve yetki belgesi incelendi."
}
```

### POST `/api/v1/claims/{claimId}/decision`

**Başvuru kararı.** Ana API claimReview şeması: approve (evidenceChecked=true, verificationReference) veya request_information / reject. Kendi başvurusuna karar verilemez.

```json
{
    "decision": "approve",
    "reason": "Kanıt doğrulandı, işletme sahipsiz.",
    "evidenceChecked": true,
    "verificationReference": "{{verificationReference}}"
}
```

## 09 İçerik moderasyonu

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/moderation/reviews` | `reviews.moderate` | Yorum kuyruğu |
| GET | `/api/v1/moderation/reviews/{reviewId}` | `reviews.moderate` | Yorum ayrıntısı |
| POST | `/api/v1/moderation/reviews/{reviewId}/decision` | `reviews.moderate` | Yorum kararı |
| POST | `/api/v1/moderation/reviews/{reviewId}/ai-analysis` | `reviews.ai` | AI kontrolü |
| GET | `/api/v1/moderation/replies` | `replies.moderate` | Yanıt kuyruğu |
| POST | `/api/v1/moderation/replies/{reviewId}/decision` | `replies.moderate` | Yanıt kararı |
| GET | `/api/v1/moderation/review-cases` | `review_cases.moderate` | Bildirim/itiraz kuyruğu |
| POST | `/api/v1/moderation/review-cases/{caseId}/decision` | `review_cases.moderate` | Bildirim/itiraz kararı |
| GET | `/api/v1/moderation/review-photos` | `review_photos.moderate` | Yorum fotoğrafı kuyruğu |
| POST | `/api/v1/moderation/review-photos/{photoId}/decision` | `review_photos.moderate` | Yorum fotoğrafı kararı |
| GET | `/api/v1/moderation/business-photos` | `business_photos.moderate` | İşletme fotoğrafı kuyruğu |
| POST | `/api/v1/moderation/business-photos/{photoId}/decision` | `business_photos.moderate` | İşletme fotoğrafı kararı |
| GET | `/api/v1/moderation/business-changes` | `business_changes.review` | Profil değişikliği kuyruğu |
| POST | `/api/v1/moderation/business-changes/{businessId}/decision` | `business_changes.review` | Profil değişikliği kararı |
| GET | `/api/v1/moderation/business-reports` | `reports.review` | Yanlış bilgi bildirimleri |
| POST | `/api/v1/moderation/business-reports/{reportId}/decision` | `reports.review` | Bildirim kararı |
| GET | `/api/v1/moderation/feature-activations` | `activations.review` | Özellik başvuruları |
| GET | `/api/v1/moderation/feature-activations/{businessId}` | `activations.review` | Özellik başvurusu ayrıntısı |
| POST | `/api/v1/moderation/feature-activations/{businessId}/decision` | `activations.review` | Özellik başvurusu kararı |

### GET `/api/v1/moderation/reviews`

**Yorum kuyruğu.** Ana API ReviewModeration::page; otomatik kontrol sonuçlarıyla.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved |
| `after` |  | İmleç |

### GET `/api/v1/moderation/reviews/{reviewId}`

**Yorum ayrıntısı.** Yorum, otomatik kontrol, yayın durumu, yanıt, fotoğraflar, bildirimler ve geçmiş.

### POST `/api/v1/moderation/reviews/{reviewId}/decision`

**Yorum kararı.** Ana API reviewDecision şeması. approve için contentReviewed=true. Kendi yorumunu veya yetkilisi olduğun işletmenin yorumunu inceleyemezsin.

```json
{
    "version": 1,
    "decision": "approve",
    "reason": "Kurallara uygun deneyim anlatımı.",
    "contentReviewed": true
}
```

### POST `/api/v1/moderation/reviews/{reviewId}/ai-analysis`

**AI kontrolü.** AI_MODERATION_ENABLED ve OPENAI_API_KEY yoksa 503. kind=review|reply.

```json
{
    "version": 1,
    "kind": "review"
}
```

### GET `/api/v1/moderation/replies`

**Yanıt kuyruğu.** Ana API ReplyModeration::page.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved |
| `after` |  | İmleç |

### POST `/api/v1/moderation/replies/{reviewId}/decision`

**Yanıt kararı.** Yanıtın ait olduğu yorum kimliği; reviewDecision şeması.

```json
{
    "version": 1,
    "decision": "approve",
    "reason": "Yanıt kibar ve kişisel veri içermiyor.",
    "contentReviewed": true
}
```

### GET `/api/v1/moderation/review-cases`

**Bildirim/itiraz kuyruğu.** Ana API CaseModeration::page.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `after` |  | İmleç |

### POST `/api/v1/moderation/review-cases/{caseId}/decision`

**Bildirim/itiraz kararı.** caseDecision şeması: dismiss|remove|reopen|superseded, reviewed=true.

```json
{
    "decision": "dismiss",
    "reason": "Yorum kurallara aykırı değil.",
    "reviewed": true
}
```

### GET `/api/v1/moderation/review-photos`

**Yorum fotoğrafı kuyruğu.** Ana API PhotoModeration::reviewPage.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved |
| `after` |  | İmleç |

### POST `/api/v1/moderation/review-photos/{photoId}/decision`

**Yorum fotoğrafı kararı.** reviewPhotoDecision şeması (attested).

```json
{
    "version": 1,
    "decision": "approve",
    "reason": "Fotoğraf yorumla ilgili, kişi yüzü yok.",
    "attested": true
}
```

### GET `/api/v1/moderation/business-photos`

**İşletme fotoğrafı kuyruğu.** Ana API PhotoModeration::businessPage (after uuid).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved |
| `after` |  | İmleç (uuid) |

### POST `/api/v1/moderation/business-photos/{photoId}/decision`

**İşletme fotoğrafı kararı.** businessPhotoDecision şeması. Onaylı ve yayındaki fotoğraf CDN'e yansıtılır (ana API kuralı).

```json
{
    "version": 1,
    "decision": "approve",
    "reason": "İşyeri cephesi, hak beyanı var.",
    "attested": true
}
```

### GET `/api/v1/moderation/business-changes`

**Profil değişikliği kuyruğu.** Ana API BusinessChangeReview::pending (v=2 biçimi, çalışma saatleri dahil).

### POST `/api/v1/moderation/business-changes/{businessId}/decision`

**Profil değişikliği kararı.** profileDecision şeması: revisionId, approve|reject.

```json
{
    "revisionId": "00000000-0000-4000-8000-000000000002",
    "decision": "approve",
    "reason": "Yeni telefon numarası işletmeyle doğrulandı."
}
```

### GET `/api/v1/moderation/business-reports`

**Yanlış bilgi bildirimleri.** Ana API BusinessReports::queue.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `submitted` | submitted\|resolved\|dismissed |
| `after` |  | İmleç (uuid) |

### POST `/api/v1/moderation/business-reports/{reportId}/decision`

**Bildirim kararı.** Yalnız bildirimi kapatır; profil düzeltmesi katalog/değişiklik akışındadır.

```json
{
    "expectedVersion": 1,
    "decision": "resolved",
    "resolution": "Adres katalogda düzeltildi."
}
```

### GET `/api/v1/moderation/feature-activations`

**Özellik başvuruları.** Ana API Activations::queue (teklif/randevu).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | pending\|approved\|rejected\|paused |
| `after` |  | İmleç |

### GET `/api/v1/moderation/feature-activations/{businessId}`

**Özellik başvurusu ayrıntısı.** Ana API Activations::view.

### POST `/api/v1/moderation/feature-activations/{businessId}/decision`

**Özellik başvurusu kararı.** activationAction şeması; panelden yalnız approve|reject|pause.

```json
{
    "action": "approve",
    "version": 1,
    "reason": "Talep kutusu izleniyor, kapasite hazır."
}
```

## 10 Gizlilik talepleri ve bildirim işleri

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/privacy/requests` | `privacy.view` | Silme/gizlilik talepleri |
| POST | `/api/v1/privacy/requests/{requestId}/action` | `privacy.manage` | Talep işlemi |
| GET | `/api/v1/privacy/exports` | `privacy.view` | Veri kopyası talepleri |
| POST | `/api/v1/privacy/exports/{requestId}/decision` | `privacy.manage` | Veri kopyası kararı |
| GET | `/api/v1/notification-jobs` | `notifications.view` | Bildirim işleri |
| GET | `/api/v1/notification-jobs/{jobId}` | `notifications.view` | Bildirim işi ayrıntısı |
| POST | `/api/v1/notification-jobs/{jobId}/retry` | `notifications.retry` | Bildirim işini yeniden kuyruğa al |

### GET `/api/v1/privacy/requests`

**Silme/gizlilik talepleri.** DATA_REQUESTS_ENABLED kapalıysa 503. after: önceki sayfanın imleci (JSON).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `submitted` | submitted\|reviewing\|needs_confirmation |
| `after` |  | {"createdAtMs":..,"ownerUid":..,"requestId":..} |

### POST `/api/v1/privacy/requests/{requestId}/action`

**Talep işlemi.** Ana API Privacy::adminAction (start_review, request_confirmation ...). Son TOTP adımı 5 dakikadan eskiyse 428. Silme yürütmesi bu sürümde açık değildir (execution=not_enabled).

```json
{
    "ownerUid": "{{accountId}}",
    "expectedVersion": 1,
    "operationId": "00000000-0000-4000-8000-000000000003",
    "action": "start_review"
}
```

### GET `/api/v1/privacy/exports`

**Veri kopyası talepleri.** Ana API PrivacyExports::queue.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `submitted` | submitted\|in_progress |
| `after` |  | uuid |

### POST `/api/v1/privacy/exports/{requestId}/decision`

**Veri kopyası kararı.** start|deliver|reject (reject için reason). Hazırlık elle yapılır (preparation=manual).

```json
{
    "expectedVersion": 1,
    "action": "start"
}
```

### GET `/api/v1/notification-jobs`

**Bildirim işleri.** 20'lik imleç.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `state` | `failed` | pending\|processing\|retry\|failed\|delivered\|superseded |
| `after` |  | İmleç |

### GET `/api/v1/notification-jobs/{jobId}`

**Bildirim işi ayrıntısı.** İş ve geçmişi.

### POST `/api/v1/notification-jobs/{jobId}/retry`

**Bildirim işini yeniden kuyruğa al.** Yalnız failed iş; ana API kuralı (hesap çerçeveleri denetlenir).

```json
{
    "reason": "Sağlayıcı kesintisi giderildi, yeniden deneniyor."
}
```

## 11 İşletme reklamı ve ödeme incelemeleri

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/ads/campaigns` | `ads.view` | Reklam kampanyaları |
| POST | `/api/v1/ads/campaigns/{campaignId}/decision` | `ads.moderate` | Kampanya kararı |
| GET | `/api/v1/ads/tariffs` | `ads.view` | Reklam tarifesi |
| PUT | `/api/v1/ads/tariffs` | `ads.tariffs` | Reklam tarifesini kaydet |
| GET | `/api/v1/finance/payment-cases` | `finance.payment_cases` | Reklam ödeme uyuşmazlıkları |
| POST | `/api/v1/finance/payment-cases/{caseId}/decision` | `finance.payment_cases` | Uyuşmazlık kararı |
| GET | `/api/v1/finance/brand-payment-review` | `finance.brand_review` | Marka ödeme incelemesi |
| POST | `/api/v1/finance/brand-payment-review/{caseId}/decision` | `finance.brand_review` | Marka ödeme incelemesi kararı |

### GET `/api/v1/ads/campaigns`

**Reklam kampanyaları.** Ana API Campaigns::list (yönetici kuyruğu).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `status` | `pending` | draft\|pending\|approved\|rejected\|cancelled\|scheduled |
| `after` |  | İmleç |

### POST `/api/v1/ads/campaigns/{campaignId}/decision`

**Kampanya kararı.** CampaignRules::action; panelden yalnız approve|reject.

```json
{
    "businessId": "{{businessId}}",
    "version": 2,
    "action": "approve",
    "reason": "Başlık ve açıklama reklam kurallarına uygun."
}
```

### GET `/api/v1/ads/tariffs`

**Reklam tarifesi.** Pazar: placement + il + kategori.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `placement` | `search` | search\|category\|home |
| `city` | `Ankara` |  |
| `category` | `mechanical` |  |

### PUT `/api/v1/ads/tariffs`

**Reklam tarifesini kaydet.** AdRules::tariffUpdate; tutarlar kuruş. Gerçek fiyat ve vergi kararı verilmedi (örnek değerdir).

```json
{
    "version": 0,
    "reason": "Pilot il için tarife açıldı.",
    "value": {
        "placement": "search",
        "city": "Ankara",
        "category": "mechanical",
        "enabled": false,
        "capacity": 3,
        "sevenDaysMinor": 100000,
        "thirtyDaysMinor": 350000,
        "taxBasisPoints": 2000
    }
}
```

### GET `/api/v1/finance/payment-cases`

**Reklam ödeme uyuşmazlıkları.** PaymentCases::list.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `after` |  | İmleç (sha256) |

### POST `/api/v1/finance/payment-cases/{caseId}/decision`

**Uyuşmazlık kararı.** PaymentCases::action: status open|reviewing|refund_required|closed, note.

```json
{
    "version": 0,
    "status": "reviewing",
    "note": "Sağlayıcı kaydı ile tutar karşılaştırılıyor."
}
```

### GET `/api/v1/finance/brand-payment-review`

**Marka ödeme incelemesi.** BrandReview::query: after | recordId | caseId(+before).

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `after` |  | İmleç |

### POST `/api/v1/finance/brand-payment-review/{caseId}/decision`

**Marka ödeme incelemesi kararı.** PaymentCases::action gövdesi.

```json
{
    "version": 0,
    "status": "reviewing",
    "note": "Makbuz ve banka kaydı inceleniyor."
}
```

## 12 Marka reklamı

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/brand/tariffs` | `finance.brand` | Marka tarifesi |
| PUT | `/api/v1/brand/tariffs` | `finance.brand` | Marka tarifesini kaydet |
| PUT | `/api/v1/brand/capacities` | `finance.brand` | Marka kapasitesini kaydet |
| POST | `/api/v1/brand/quotes` | `finance.brand` | Fiyat teklifi |
| GET | `/api/v1/brand/campaigns` | `finance.brand` | Marka kampanyaları |
| POST | `/api/v1/brand/campaigns/{brandCampaignId}/actions` | `finance.brand` | Marka kampanyası işlemi |
| GET | `/api/v1/brand/campaigns/{brandCampaignId}/metrics` | `finance.brand` | Marka kampanyası ölçümü |
| GET | `/api/v1/brand/reservations` | `finance.brand` | Rezervasyon durumu |
| POST | `/api/v1/brand/reservations` | `finance.brand` | Rezervasyon yap |
| DELETE | `/api/v1/brand/reservations/{brandCampaignId}` | `finance.brand` | Rezervasyonu bırak |
| GET | `/api/v1/brand/orders` | `finance.brand` | Sipariş durumu |
| POST | `/api/v1/brand/orders` | `finance.brand` | Sipariş hazırla |
| GET | `/api/v1/brand/acceptances` | `finance.brand` | Kabul durumu |
| POST | `/api/v1/brand/acceptances` | `finance.brand` | Kabul et |
| GET | `/api/v1/brand/cancellations` | `finance.refunds` | İptal durumu |
| POST | `/api/v1/brand/cancellations` | `finance.refunds` | İptal talebi |
| POST | `/api/v1/brand/cancellations/decision` | `finance.refunds` | İptal kararı |
| GET | `/api/v1/brand/cancellations/queue` | `finance.refunds` | İptal kuyruğu |
| GET | `/api/v1/brand/cancellations/history` | `finance.refunds` | İptal geçmişi |
| GET | `/api/v1/brand/refund-reviews` | `finance.refunds` | İade uzlaştırma |
| POST | `/api/v1/brand/refund-reviews` | `finance.refunds` | İade uzlaştırma kararı |

### GET `/api/v1/brand/tariffs`

**Marka tarifesi.** BrandRules::market.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `placement` | `home_banner` | home_banner\|category_banner |
| `city` | `Ankara` |  |
| `category` |  | category_banner için zorunlu |

### PUT `/api/v1/brand/tariffs`

**Marka tarifesini kaydet.** BrandRules::tariffUpdate (dailyMinor kuruş).

```json
{
    "version": 0,
    "reason": "Pilot ana sayfa bandı tarifesi.",
    "value": {
        "market": {
            "placement": "home_banner",
            "city": "Ankara",
            "category": ""
        },
        "enabled": false,
        "dailyMinor": 50000,
        "taxBasisPoints": 2000
    }
}
```

### PUT `/api/v1/brand/capacities`

**Marka kapasitesini kaydet.** BrandRules::capacityUpdate.

```json
{
    "placement": "home_banner",
    "version": 0,
    "enabled": false,
    "capacity": 2,
    "reason": "Ana sayfada en fazla iki marka bandı."
}
```

### POST `/api/v1/brand/quotes`

**Fiyat teklifi.** BrandRules::quoteInput.

```json
{
    "campaignId": "{{brandCampaignId}}",
    "campaignVersion": 3,
    "tariffVersion": 1
}
```

### GET `/api/v1/brand/campaigns`

**Marka kampanyaları.** BrandAdmin::campaigns.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `after` |  | İmleç (uuid) |

### POST `/api/v1/brand/campaigns/{brandCampaignId}/actions`

**Marka kampanyası işlemi.** BrandRules::action: save (yeni kampanya için yeni uuid ve version 0), submit, pause, remove_image, approve, reject (note, rightsChecked).

```json
{
    "version": 0,
    "action": "save",
    "value": {
        "brand": "Örnek Yağ",
        "headline": "Kış bakımında yanınızda",
        "description": "Yetkili servislerde kış bakımı kampanyası.",
        "url": "https://example.test/kampanya",
        "placement": "home_banner",
        "city": "Ankara",
        "category": "",
        "startDate": "2026-12-01",
        "endDate": "2026-12-14"
    }
}
```

### GET `/api/v1/brand/campaigns/{brandCampaignId}/metrics`

**Marka kampanyası ölçümü.** BrandServing::report; BRAND_METRICS_ENABLED durumu enabled alanında.

### GET `/api/v1/brand/reservations`

**Rezervasyon durumu.** BrandInventory::read.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `campaignId` | `{{brandCampaignId}}` |  |

### POST `/api/v1/brand/reservations`

**Rezervasyon yap.** BRAND_RESERVATIONS_ENABLED kapalıysa 503.

```json
{
    "campaignId": "{{brandCampaignId}}",
    "campaignVersion": 3,
    "tariffVersion": 1,
    "capacityVersion": 1,
    "requestId": "00000000-0000-4000-8000-000000000004"
}
```

### DELETE `/api/v1/brand/reservations/{brandCampaignId}`

**Rezervasyonu bırak.** BrandInventory::releaseTx.

### GET `/api/v1/brand/orders`

**Sipariş durumu.** BrandOrders::read; ödeme geçidi durumu.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `reservationId` | `{{reservationId}}` |  |

### POST `/api/v1/brand/orders`

**Sipariş hazırla.** BRAND_ORDERS_ENABLED kapalıysa 503.

```json
{
    "reservationId": "{{reservationId}}"
}
```

### GET `/api/v1/brand/acceptances`

**Kabul durumu.** BrandAcceptance::read.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `reservationId` | `{{reservationId}}` |  |

### POST `/api/v1/brand/acceptances`

**Kabul et.** BRAND_ACCEPTANCE_ENABLED kapalıysa 503.

```json
{
    "reservationId": "{{reservationId}}"
}
```

### GET `/api/v1/brand/cancellations`

**İptal durumu.** BrandCancellation::read.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `reservationId` | `{{reservationId}}` |  |

### POST `/api/v1/brand/cancellations`

**İptal talebi.** BRAND_CANCELLATIONS_ENABLED kapalıysa 503.

```json
{
    "reservationId": "{{reservationId}}",
    "reason": "Reklamveren kampanyayı erteledi."
}
```

### POST `/api/v1/brand/cancellations/decision`

**İptal kararı.** BrandCancellation::decision.

```json
{
    "reservationId": "{{reservationId}}",
    "version": 1,
    "decision": "approve",
    "note": "Yayın başlamadan iptal, iade kuralı uygulanır."
}
```

### GET `/api/v1/brand/cancellations/queue`

**İptal kuyruğu.** BrandCancellation::queue.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `filter` | `pending` | Ana API FILTERS |
| `after` |  | uuid |

### GET `/api/v1/brand/cancellations/history`

**İptal geçmişi.** BrandCancellation::history.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `reservationId` | `{{reservationId}}` |  |
| `after` |  | <ms>_<kimlik> |

### GET `/api/v1/brand/refund-reviews`

**İade uzlaştırma.** BrandRefundReview::read.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `reservationId` | `{{reservationId}}` |  |
| `eventId` | `{{refundEventId}}` |  |
| `before` |  | Sürüm |

### POST `/api/v1/brand/refund-reviews`

**İade uzlaştırma kararı.** BRAND_REFUND_REVIEW_ENABLED kapalıysa 503. closed için acknowledged=true.

```json
{
    "reservationId": "{{reservationId}}",
    "eventId": "{{refundEventId}}",
    "version": 0,
    "status": "reviewing",
    "note": "Sağlayıcı iade kaydı bekleniyor.",
    "acknowledged": false
}
```

## 13 Sınıflandırma listeleri

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/taxonomy/categories` | `taxonomy.view` | Kategoriler |
| POST | `/api/v1/taxonomy/categories` | `taxonomy.manage` | Kategori ekle |
| PATCH | `/api/v1/taxonomy/categories/{categoryId}` | `taxonomy.manage` | Kategori güncelle |
| GET | `/api/v1/taxonomy/aliases` | `taxonomy.view` | Eş anlamlılar |
| PUT | `/api/v1/taxonomy/aliases` | `taxonomy.manage` | Eş anlamlı kaydet |
| DELETE | `/api/v1/taxonomy/aliases/{aliasKind}/{aliasTerm}` | `taxonomy.manage` | Eş anlamlı sil |
| GET | `/api/v1/taxonomy/cities` | `taxonomy.view` | İller ve kapsam |
| PATCH | `/api/v1/taxonomy/cities/{cityName}` | `taxonomy.manage` | İl kapsamını güncelle |
| GET | `/api/v1/taxonomy/export` | `taxonomy.view` | Ana API biçiminde dışa aktar |

### GET `/api/v1/taxonomy/categories`

**Kategoriler.** Kategoriler ve hizmetleri; withUsage=true ile katalogdaki kullanım sayısı.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `withUsage` | `true` | true\|false |

### POST `/api/v1/taxonomy/categories`

**Kategori ekle.** Kimlik küçük harf ve tire. Ana API'ye export ile geçer.

```json
{
    "id": "glass",
    "name": "Oto Cam",
    "services": [
        "Cam değişimi",
        "Cam tamiri"
    ]
}
```

### PATCH `/api/v1/taxonomy/categories/{categoryId}`

**Kategori güncelle.** Ad, sıra, etkinlik ve hizmet listesi. Kategori silinmez; active=false ile export dışı kalır.

```json
{
    "version": 1,
    "name": "Oto Cam",
    "active": true,
    "services": [
        "Cam değişimi",
        "Cam tamiri",
        "Cam filmi"
    ]
}
```

### GET `/api/v1/taxonomy/aliases`

**Eş anlamlılar.** alias: Türkçe yazım varyantı; english: İngilizce arama terimi.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `kind` | `alias` | alias\|english |

### PUT `/api/v1/taxonomy/aliases`

**Eş anlamlı kaydet.** Varsa günceller.

```json
{
    "kind": "alias",
    "term": "egzozcu",
    "target": "egzoz"
}
```

### DELETE `/api/v1/taxonomy/aliases/{aliasKind}/{aliasTerm}`

**Eş anlamlı sil.** Terim URL kodlu gönderilir.

### GET `/api/v1/taxonomy/cities`

**İller ve kapsam.** 81 il, kapsam ve büyükşehir bayrakları, ilçe sayısı.

| Sorgu | Örnek | Açıklama |
|---|---|---|
| `inCoverage` |  | true\|false |

### PATCH `/api/v1/taxonomy/cities/{cityName}`

**İl kapsamını güncelle.** Koordinatı olmayan il kapsama alınamaz.

```json
{
    "version": 1,
    "inCoverage": true,
    "reason": "Kapsam genişletme kararı (K166) uygulanıyor."
}
```

### GET `/api/v1/taxonomy/export`

**Ana API biçiminde dışa aktar.** catalogue.json ve coverage.json içerikleri ve depodaki sürümle fark. Canlıya alma ana API deposunda incelemeyle yapılır.

## 14 Operasyon

| Yöntem | Yol | Yetki | Açıklama |
|---|---|---|---|
| GET | `/api/v1/ops/scheduled-runs` | `ops.view` | Zamanlanmış işler |
| GET | `/api/v1/ops/queues` | `ops.view` | Kuyruklar |
| GET | `/api/v1/ops/failed-jobs` | `ops.view` | Başarısız işler |
| POST | `/api/v1/ops/failed-jobs/{failedJobUuid}/retry` | `ops.manage` | Başarısız işi yeniden dene |
| POST | `/api/v1/ops/failed-jobs/{failedJobUuid}/forget` | `ops.manage` | Başarısız işi sil |

### GET `/api/v1/ops/scheduled-runs`

**Zamanlanmış işler.** Ana API scheduled_runs defteri.

### GET `/api/v1/ops/queues`

**Kuyruklar.** jobs tablosu kuyruk başına bekleyen/rezerve ve başarısız iş sayısı.

### GET `/api/v1/ops/failed-jobs`

**Başarısız işler.** Son 100; yük (payload) dönmez, yalnız iş adı ve istisnanın ilk satırı.

### POST `/api/v1/ops/failed-jobs/{failedJobUuid}/retry`

**Başarısız işi yeniden dene.** queue:retry. İş sınıfı ana API'de çalışır (aynı jobs tablosu).

```json
{
    "reason": "R2 kesintisi bitti, CDN yansısı yeniden deneniyor."
}
```

### POST `/api/v1/ops/failed-jobs/{failedJobUuid}/forget`

**Başarısız işi sil.** queue:forget.

```json
{
    "reason": "Kaynak kayıt silinmiş, yeniden denemenin anlamı yok."
}
```
