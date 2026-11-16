<?php

/** Cloudflare R2 S3 uyumlu disk tanimi: tek kova, her disk kendi dizin kokunde (K206, K207). */
$r2 = static fn (?string $root): array => [
    'driver' => 's3',
    'key' => env('R2_ACCESS_KEY_ID'),
    'secret' => env('R2_SECRET_ACCESS_KEY'),
    'region' => 'auto',
    'bucket' => env('R2_BUCKET'),
    'root' => (string) $root,
    'endpoint' => env('R2_ENDPOINT'),
    'use_path_style_endpoint' => true,
    // AWS SDK'nin varsayilan CRC32 sagma toplamlari yalniz gerektiginde gonderilir (S3 uyumlu saglayici).
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
    'throw' => false,
    'report' => false,
];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        /*
         * Cloudflare R2 (K204-K207). Tek kova (R2_BUCKET), tek CDN alan adi; ayrim dizinle.
         * Dizin adlari depoda yazmaz, yalniz sunucu .env'inden okunur ve tahmin edilemez secilir (K207):
         * - r2 -> R2_PRIVATE_ROOT : ozel medya (FILESYSTEM_DISK=r2). Yorum/isletme fotografi, sahiplik belgesi.
         * - r2-brand -> R2_BRAND_ROOT : marka gorseli (BRAND_MEDIA_DISK=r2-brand).
         * - r2-public -> R2_PUBLIC_ROOT : CDN'den sunulan dizin (MEDIA_CDN_DISK=r2-public). Yalniz onayli ve
         *   yayindaki isletme fotograflari MediaCdn ile buraya kopyalanir.
         * CDN alan adinda yalniz R2_PUBLIC_ROOT yolu acik olmalidir (Cloudflare WAF kurali, docs/MEDIA_CDN.md).
         * `visibility` bilincli olarak verilmez: R2 nesne ACL'si kullanmaz.
         */
        'r2' => $r2(env('R2_PRIVATE_ROOT')),
        'r2-brand' => $r2(env('R2_BRAND_ROOT')),
        'r2-public' => $r2(env('R2_PUBLIC_ROOT')),

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
