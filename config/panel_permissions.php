<?php

// Yetki katalogu ve sistem rolleri: tek kaynak. Seeder ve panel:permissions-sync buradan yazar.
// Urun plani: "Kisitli admin rolleri: Moderator, finans, destek"; "Moderator odeme iadesi yapamaz;
// finans rolu yorum silemez." Rol icerikleri oneridir (kullanici kabulu bekler).

return [
    'permissions' => [
        // Genel
        'dashboard.view' => ['group' => 'general', 'description' => 'Genel bakış ve kuyruk sayıları'],
        'stats.view' => ['group' => 'general', 'description' => 'Katalog ve hesap istatistikleri, günlük anlık görüntüler'],

        // Panel erişimi
        'members.view' => ['group' => 'access', 'description' => 'Panel üyelerini görüntüleme'],
        'members.manage' => ['group' => 'access', 'description' => 'Panel üyesi ekleme, askıya alma, rol ve MFA sıfırlama'],
        'roles.view' => ['group' => 'access', 'description' => 'Rol ve yetkileri görüntüleme'],
        'roles.manage' => ['group' => 'access', 'description' => 'Rol oluşturma ve yetki değiştirme'],
        'audit.view' => ['group' => 'access', 'description' => 'Denetim kaydını görüntüleme'],
        'approvals.view' => ['group' => 'access', 'description' => 'İkinci kontrol taleplerini görüntüleme'],
        'approvals.decide' => ['group' => 'access', 'description' => 'Başkasının açtığı ikinci kontrol talebini onaylama veya reddetme'],
        'settings.view' => ['group' => 'access', 'description' => 'Panel ayarlarını görüntüleme'],
        'settings.manage' => ['group' => 'access', 'description' => 'Panel ayarlarını değiştirme'],

        // Platform hesaplari
        'accounts.view' => ['group' => 'accounts', 'description' => 'Platform hesaplarını, oturum ve giriş olaylarını görüntüleme'],
        'accounts.manage' => ['group' => 'accounts', 'description' => 'Hesabı devre dışı bırakma, açma ve oturumlarını kapatma'],
        'accounts.claims' => ['group' => 'accounts', 'description' => 'Platform yetkilerini (yönetici, marka finansı, silme operatörü) değiştirme talebi'],
        'support.access' => ['group' => 'accounts', 'description' => 'Süreli ve gerekçeli destek erişimi açma'],
        'support.manage' => ['group' => 'accounts', 'description' => 'Başkasının destek erişimini görüntüleme ve kapatma'],

        // Katalog
        'businesses.view' => ['group' => 'catalogue', 'description' => 'İşletme kayıtlarını ve geçmişini görüntüleme'],
        'businesses.source' => ['group' => 'catalogue', 'description' => 'Katalog kaynak kanıtını (ham satır) görüntüleme'],
        'businesses.edit' => ['group' => 'catalogue', 'description' => 'Katalog taslağı düzenleme'],
        'businesses.publish' => ['group' => 'catalogue', 'description' => 'Dört teyitle yayımlama ve yayından kaldırma'],
        'business_changes.review' => ['group' => 'catalogue', 'description' => 'İşletme profil değişikliği onayı'],
        'activations.review' => ['group' => 'catalogue', 'description' => 'Teklif/randevu özellik başvurusu kararı'],
        'reports.review' => ['group' => 'catalogue', 'description' => 'Yanlış bilgi bildirimlerini kapatma'],
        'taxonomy.view' => ['group' => 'catalogue', 'description' => 'Kategori, hizmet, eş anlamlı ve kapsam listelerini görüntüleme'],
        'taxonomy.manage' => ['group' => 'catalogue', 'description' => 'Kategori, hizmet, eş anlamlı ve kapsam listelerini düzenleme'],

        // Sahiplik
        'claims.view' => ['group' => 'claims', 'description' => 'Sahiplik başvurularını görüntüleme'],
        'claims.review' => ['group' => 'claims', 'description' => 'Sahiplik başvurusu eşleme ve kararı'],
        'claims.evidence' => ['group' => 'claims', 'description' => 'Sahiplik kanıtı doğrulama'],

        // Icerik moderasyonu
        'reviews.moderate' => ['group' => 'moderation', 'description' => 'Yorum yayın, ret ve gizleme kararı'],
        'reviews.ai' => ['group' => 'moderation', 'description' => 'Yorum ve yanıtlar için AI kontrolü başlatma'],
        'replies.moderate' => ['group' => 'moderation', 'description' => 'İşletme yanıtı kararı'],
        'review_cases.moderate' => ['group' => 'moderation', 'description' => 'Yorum bildirimi ve itiraz kararı'],
        'review_photos.moderate' => ['group' => 'moderation', 'description' => 'Yorum fotoğrafı kararı'],
        'business_photos.moderate' => ['group' => 'moderation', 'description' => 'İşletme fotoğrafı kararı'],

        // Gizlilik ve bildirim
        'privacy.view' => ['group' => 'privacy', 'description' => 'Veri ve silme taleplerini görüntüleme'],
        'privacy.manage' => ['group' => 'privacy', 'description' => 'Veri ve silme talebi işlemleri'],
        'notifications.view' => ['group' => 'privacy', 'description' => 'Bildirim işlerini görüntüleme'],
        'notifications.retry' => ['group' => 'privacy', 'description' => 'Başarısız bildirim işini yeniden kuyruğa alma'],

        // Reklam ve finans
        'ads.view' => ['group' => 'finance', 'description' => 'İşletme reklam kampanyalarını ve tarifeleri görüntüleme'],
        'ads.moderate' => ['group' => 'finance', 'description' => 'İşletme reklam kampanyası onayı'],
        'ads.tariffs' => ['group' => 'finance', 'description' => 'İşletme reklam tarifesi değiştirme'],
        'finance.payment_cases' => ['group' => 'finance', 'description' => 'Reklam ödeme uyuşmazlıkları'],
        'finance.brand' => ['group' => 'finance', 'description' => 'Marka tarifesi, kapasite, kampanya, rezervasyon, sipariş ve kabul'],
        'finance.brand_review' => ['group' => 'finance', 'description' => 'Marka ödeme incelemesi'],
        'finance.refunds' => ['group' => 'finance', 'description' => 'Marka iptal ve iade uzlaştırma kararları'],

        // Is akisi
        'work_items.view' => ['group' => 'workflow', 'description' => 'İş kuyruğunu görüntüleme'],
        'work_items.work' => ['group' => 'workflow', 'description' => 'İş üstlenme ve kendi işini güncelleme'],
        'work_items.manage' => ['group' => 'workflow', 'description' => 'İşi başkasına atama, öncelik ve son tarih değiştirme'],
        'notes.view' => ['group' => 'workflow', 'description' => 'İç notları görüntüleme'],
        'notes.write' => ['group' => 'workflow', 'description' => 'İç not ekleme ve kendi notunu silme'],

        // Operasyon
        'ops.view' => ['group' => 'ops', 'description' => 'Zamanlanmış işler, kuyruklar ve başarısız işler'],
        'ops.manage' => ['group' => 'ops', 'description' => 'Başarısız kuyruk işini yeniden deneme veya silme'],
    ],

    // Hassas yetkiler: bunlardan birini tasiyan rol "ayricalikli" sayilir; bu role atama ve role bu yetkilerin
    // eklenmesi ikinci kontrol ister (approvals.privileged_roles listesine ek olarak).
    'sensitive' => [
        'members.manage', 'roles.manage', 'approvals.decide', 'settings.manage', 'accounts.manage', 'accounts.claims',
        'support.manage', 'privacy.manage', 'ops.manage', 'ads.tariffs',
        'finance.payment_cases', 'finance.brand', 'finance.brand_review', 'finance.refunds',
    ],

    'roles' => [
        'super_admin' => [
            'name' => 'Süper yönetici',
            'description' => 'Bütün yetkiler. En az bir etkin süper yönetici kalır.',
            'permissions' => ['*'],
        ],
        'operations_admin' => [
            'name' => 'Operasyon yöneticisi',
            'description' => 'Katalog, moderasyon, sahiplik, destek ve iş kuyruğu; finans ve panel erişim yönetimi hariç.',
            'permissions' => [
                'dashboard.view', 'stats.view', 'members.view', 'roles.view', 'audit.view', 'approvals.view', 'approvals.decide', 'settings.view',
                'accounts.view', 'accounts.manage', 'accounts.claims', 'support.access', 'support.manage',
                'businesses.view', 'businesses.source', 'businesses.edit', 'businesses.publish', 'business_changes.review', 'activations.review', 'reports.review', 'taxonomy.view', 'taxonomy.manage',
                'claims.view', 'claims.review', 'claims.evidence',
                'reviews.moderate', 'reviews.ai', 'replies.moderate', 'review_cases.moderate', 'review_photos.moderate', 'business_photos.moderate',
                'privacy.view', 'privacy.manage', 'notifications.view', 'notifications.retry',
                'ads.view', 'ads.moderate',
                'work_items.view', 'work_items.work', 'work_items.manage', 'notes.view', 'notes.write', 'ops.view',
            ],
        ],
        'moderator' => [
            'name' => 'Moderatör',
            'description' => 'Yorum, yanıt, bildirim ve fotoğraf kararları. Ödeme ve iade yetkisi yoktur.',
            'permissions' => [
                'dashboard.view', 'businesses.view', 'reports.review',
                'reviews.moderate', 'reviews.ai', 'replies.moderate', 'review_cases.moderate', 'review_photos.moderate', 'business_photos.moderate',
                'work_items.view', 'work_items.work', 'notes.view', 'notes.write',
            ],
        ],
        'catalogue_editor' => [
            'name' => 'Katalog editörü',
            'description' => 'İşletme taslağı, yayın, profil değişikliği, sahiplik ve sınıflandırma listeleri.',
            'permissions' => [
                'dashboard.view', 'stats.view',
                'businesses.view', 'businesses.source', 'businesses.edit', 'businesses.publish', 'business_changes.review', 'activations.review', 'reports.review', 'taxonomy.view', 'taxonomy.manage',
                'claims.view', 'claims.review', 'claims.evidence',
                'work_items.view', 'work_items.work', 'notes.view', 'notes.write',
            ],
        ],
        'finance' => [
            'name' => 'Finans',
            'description' => 'Reklam ve marka finans akışları. Yorum ve içerik kararı veremez.',
            'permissions' => [
                'dashboard.view', 'stats.view', 'businesses.view',
                'ads.view', 'ads.tariffs', 'finance.payment_cases', 'finance.brand', 'finance.brand_review', 'finance.refunds',
                'work_items.view', 'work_items.work', 'notes.view', 'notes.write',
            ],
        ],
        'support' => [
            'name' => 'Destek',
            'description' => 'Hesap görüntüleme, süreli destek erişimi, veri talepleri ve bildirim işleri.',
            'permissions' => [
                'dashboard.view', 'accounts.view', 'support.access', 'businesses.view', 'claims.view',
                'privacy.view', 'notifications.view', 'notifications.retry',
                'work_items.view', 'work_items.work', 'notes.view', 'notes.write',
            ],
        ],
        'auditor' => [
            'name' => 'Denetçi',
            'description' => 'Salt okunur: denetim kaydı, üyeler, ayarlar ve kuyruklar.',
            'permissions' => [
                'dashboard.view', 'stats.view', 'members.view', 'roles.view', 'audit.view', 'approvals.view', 'settings.view',
                'accounts.view', 'businesses.view', 'claims.view', 'privacy.view', 'notifications.view', 'ads.view', 'taxonomy.view',
                'work_items.view', 'notes.view', 'ops.view',
            ],
        ],
    ],
];
