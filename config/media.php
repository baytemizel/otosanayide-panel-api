<?php

use Illuminate\Support\Env;

// K205-K207: Cloudflare R2 CDN yansisi. Degerler yalniz ortamdan okunur; depoda gercek deger yoktur.
$env = static fn (string $key): ?string => ($v = Env::getRepository()->get($key)) === null || $v === '' ? null : (string) $v;
$flag = static fn (string $key): bool => Env::getRepository()->get($key) === 'true';

return [
    'cdn' => [
        // CDN dizininin diski (r2-public -> kova icinde R2_PUBLIC_ROOT). Bos: CDN yansisi kapali, fotograflar yalniz API'den akar.
        'disk' => $env('MEDIA_CDN_DISK'),
        // Kovaya Cloudflare panelinden baglanan ozel alan adi; https, yol ve sonda / olmadan. Yalniz R2_PUBLIC_ROOT yolu acik olmali.
        'url' => $env('MEDIA_CDN_URL'),
        // Nesne Cache-Control suresi (sn). Geri cekilen fotograf, temizleme (purge) yoksa en cok bu sure onbellekte kalir.
        'max_age' => max(60, min(31536000, (int) ($env('MEDIA_CDN_MAX_AGE') ?? 3600))),
        // Istege bagli: geri cekmede Cloudflare onbellegini URL ile temizler. Token yalniz "Cache Purge" izni tasir.
        'purge_zone' => $env('CLOUDFLARE_ZONE_ID'),
        'purge_token' => $env('CLOUDFLARE_CACHE_PURGE_TOKEN'),
    ],

    'workers' => [
        // Gunluk tam uzlastirma (media:cdn-sync --apply). Anlik guncelleme kuyruk isiyle yapilir.
        'cdn_sync' => $flag('MEDIA_CDN_SYNC_WORKER_ENABLED'),
    ],
];
