<?php

use Illuminate\Support\Env;

// Panel API ayarlari. Degerler yalniz ortamdan okunur; depoda gercek deger (alan adi, IP, anahtar) yoktur.
// Sureler oneridir (kullanici kabulu bekler); bkz. docs/API.md "Oturum".
$env = static fn (string $key): ?string => ($v = Env::getRepository()->get($key)) === null || $v === '' ? null : (string) $v;
$flag = static fn (string $key): bool => Env::getRepository()->get($key) === 'true';
$int = static fn (string $key, int $default, int $min, int $max): int => max($min, min($max, (int) ($env($key) ?? $default)));

return [
    // Panel on yuzunun tek origin'i (CORS). Bos: tarayici istegi CORS almaz; Postman/CLI etkilenmez.
    'web_origin' => $env('PANEL_WEB_ORIGIN'),

    // Virgulle ayrilmis IP/CIDR listesi. Bos: genel kisit yok (uyeye ozel liste yine uygulanir).
    'ip_allowlist' => $env('PANEL_IP_ALLOWLIST'),

    // IP ozeti icin HMAC anahtari. Bos: APP_KEY kullanilir. Ham IP veritabanina yazilmaz.
    'ip_hash_key' => $env('PANEL_IP_HASH_KEY'),

    'session' => [
        'access_ttl_minutes' => $int('PANEL_ACCESS_TTL_MINUTES', 15, 5, 60),
        // Bu sure icinde yenilenmeyen oturum kapanir (K169 tasarim onerisi: 30 dk hareketsizlik).
        'idle_minutes' => $int('PANEL_IDLE_MINUTES', 30, 10, 240),
        // Yenileme ne kadar surerse sursun oturumun mutlak omru.
        'absolute_hours' => $int('PANEL_ABSOLUTE_HOURS', 12, 1, 24),
    ],

    'refresh_cookie' => [
        'name' => 'otosanayide_panel_refresh',
        'path' => '/api/v1/auth',
        'domain' => $env('PANEL_COOKIE_DOMAIN'),
        // Yalniz yerel gelistirmede false yapilir.
        'secure' => Env::getRepository()->get('PANEL_COOKIE_SECURE') !== 'false',
    ],

    'mfa' => [
        'issuer' => 'Otosanayide Panel',
        'challenge_seconds' => 300,
        'max_attempts' => 5,
        // Uye olusturulduktan veya MFA sifirlandiktan sonra ilk TOTP kurulumu icin pencere.
        'enrollment_hours' => $int('PANEL_MFA_ENROLLMENT_HOURS', 72, 1, 168),
    ],

    'rate_limits' => [
        'login_per_minute' => $int('PANEL_LOGIN_PER_MINUTE', 5, 1, 60),
        'member_per_minute' => $int('PANEL_MEMBER_PER_MINUTE', 120, 30, 1200),
    ],

    'pagination' => ['default' => 25, 'max' => 100],

    // Uzerinde degisiklik yapilan ayarlarin varsayilanlari; kalici degerler panel_settings tablosundadir.
    'defaults' => [
        'approvals.privileged_roles' => ['super_admin', 'operations_admin', 'finance'],
        'approvals.ttl_hours' => 72,
        'support_access.max_minutes' => 240,
        'work_items.sla_hours' => [
            'claims' => 48, 'reviews' => 24, 'replies' => 24, 'review_cases' => 24, 'review_photos' => 24,
            'business_photos' => 24, 'business_changes' => 48, 'business_reports' => 72, 'feature_activations' => 48,
            'privacy_requests' => 72, 'privacy_exports' => 168, 'ad_campaigns' => 24, 'brand_campaigns' => 24,
            'ad_payment_cases' => 24, 'brand_payment_cases' => 24, 'brand_cancellations' => 24, 'notification_jobs' => 24,
        ],
        'audit.retention_days' => null,
    ],

    // Zamanlanmis isler varsayilan kapali (ana API kurali); acmadan once kuru calisma incelenir.
    'workers' => [
        'housekeeping' => $flag('PANEL_HOUSEKEEPING_ENABLED'),
        'work_items_sync' => $flag('PANEL_WORK_ITEMS_SYNC_ENABLED'),
        'stats_snapshot' => $flag('PANEL_STATS_SNAPSHOT_ENABLED'),
    ],

    // Vendor edilen alan cekirdeginin kaynagi (panel:core-check). Goreli yol proje kokune goredir.
    'core' => [
        'source_path' => $env('PANEL_CORE_SOURCE') ?? '../otosanayide-api',
        'manifest' => 'core-manifest.json',
    ],
];
