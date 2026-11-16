<?php

namespace App\Domain\Content;

use App\Jobs\SyncBusinessMediaCdn;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * K205-K207: Cloudflare R2 CDN yansisi. Tek kova; asil nesneler ozel dizinde (MediaStore, R2_PRIVATE_ROOT) kalir,
 * yalniz kamuya acik isletme fotograflari (BusinessPhotos::publicList) CDN dizinine kopyalanir:
 * `{R2_PUBLIC_ROOT}/business-photos/{businessId}/{photoId}/{full|thumbnail}.webp`.
 *
 * Fotograf kimligi degismez nesnedir (MediaStore uzerine yazmaz), bu yuzden yol kalicidir. Geri cekme,
 * gizleme, yayindan kaldirma veya sahiplik kaybi yansidan siler; onbellek temizleme ayarliysa URL'ler de temizlenir.
 * Yorum fotografi, sahiplik belgesi ve marka gorseli yansitilmaz (API akisi ve `private, no-store`, K175/K204).
 */
final class MediaCdn
{
    public static function enabled(): bool
    {
        $disk = config('media.cdn.disk');
        $url = config('media.cdn.url');

        return is_string($disk) && $disk !== '' && is_string($url) && self::validBaseUrl($url);
    }

    /** Yalniz https kok adresi: yol, port, sorgu ve sonda / yok. */
    public static function validBaseUrl(string $url): bool
    {
        return preg_match('~^https://(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$~iD', $url) === 1;
    }

    /** CDN dizini: ozel medya veya marka diskiyle ayni disk olamaz; ayni kovadaysa dizinleri ic ice olamaz. */
    public static function disk(): Filesystem
    {
        if (! self::enabled()) {
            throw new RuntimeException('media_cdn_disabled');
        }
        $name = (string) config('media.cdn.disk');
        $config = config('filesystems.disks.'.$name);
        if (! is_array($config)) {
            throw new RuntimeException('media_cdn_disk_missing');
        }
        $root = self::root($config);
        if ($root === '') {
            throw new RuntimeException('media_cdn_root_missing');
        }
        // Adres ve WAF kuralinda kullanilir: tek duzey, URL'de kacis gerektirmeyen ad.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $root) !== 1) {
            throw new RuntimeException('media_cdn_root_invalid');
        }
        foreach (array_filter([config('filesystems.default'), config('ads.brand_media_disk')], 'is_string') as $private) {
            $other = config('filesystems.disks.'.$private);
            if ($private === $name) {
                throw new RuntimeException('media_cdn_disk_shared');
            }
            if (! is_array($other) || ($other['bucket'] ?? null) !== ($config['bucket'] ?? null)) {
                continue;
            }
            $o = self::root($other);
            if ($o === '' || str_starts_with($root.'/', $o.'/') || str_starts_with($o.'/', $root.'/')) {
                throw new RuntimeException('media_cdn_disk_shared');
            }
        }

        return Storage::disk($name);
    }

    /** @param array<string,mixed> $config */
    private static function root(array $config): string
    {
        return trim((string) ($config['root'] ?? ''), '/');
    }

    public static function path(string $businessId, string $photoId, bool $thumb = false): string
    {
        return 'business-photos/'.$businessId.'/'.$photoId.'/'.($thumb ? 'thumbnail' : 'full').'.webp';
    }

    /** Kova kokunden adres: `{MEDIA_CDN_URL}/{R2_PUBLIC_ROOT}/business-photos/...` */
    public static function url(string $businessId, string $photoId, bool $thumb = false): string
    {
        $config = config('filesystems.disks.'.config('media.cdn.disk'));

        return config('media.cdn.url').'/'.self::root(is_array($config) ? $config : []).'/'.self::path($businessId, $photoId, $thumb);
    }

    /** Islem sonrasinda (afterCommit) isletmenin yansisini kuyruga alir; CDN kapaliysa veya fotograf yoksa no-op. */
    public static function queue(string $businessId): void
    {
        if (self::enabled() && DB::table('business_photos')->where('business_id', $businessId)->exists()) {
            SyncBusinessMediaCdn::dispatch($businessId);
        }
    }

    /** @param list<string> $businessIds */
    public static function queueMany(array $businessIds): void
    {
        if (! self::enabled() || $businessIds === []) {
            return;
        }
        foreach (array_chunk($businessIds, 1000) as $chunk) {
            foreach (DB::table('business_photos')->whereIn('business_id', $chunk)->distinct()->pluck('business_id') as $id) {
                SyncBusinessMediaCdn::dispatch((string) $id);
            }
        }
    }

    /**
     * Yansinin olmasi gereken durumla farki. Yalniz HEAD (B sinifi) istegi yapar, yazmaz.
     *
     * @return array{put:list<string>,delete:list<string>}
     */
    public static function plan(string $businessId): array
    {
        $disk = self::disk();
        $public = BusinessPhotos::publicList($businessId)['ids'];
        $plan = ['put' => [], 'delete' => []];
        foreach (DB::table('business_photos')->where('business_id', $businessId)->orderBy('id')->pluck('id') as $id) {
            $id = (string) $id;
            $full = $disk->exists(self::path($businessId, $id));
            $thumb = $disk->exists(self::path($businessId, $id, true));
            if (in_array($id, $public, true)) {
                if (! $full || ! $thumb) {
                    $plan['put'][] = $id;
                }
            } elseif ($full || $thumb) {
                $plan['delete'][] = $id;
            }
        }

        return $plan;
    }

    /**
     * Plani uygular. Kopya ozel diskten okunur ve kayittaki SHA-256 ile eslesmezse yazilmaz.
     *
     * @return array{put:list<string>,delete:list<string>}
     */
    public static function sync(string $businessId): array
    {
        $plan = self::plan($businessId);
        $disk = self::disk();
        $options = ['ContentType' => 'image/webp', 'CacheControl' => 'public, max-age='.(int) config('media.cdn.max_age')];
        if ($plan['put'] !== []) {
            $source = MediaStore::disk(MediaStore::BUSINESS);
            $rows = DB::table('business_photos')->whereIn('id', $plan['put'])->get()->keyBy('id');
            foreach ($plan['put'] as $id) {
                $row = $rows[$id] ?? null;
                foreach ([true, false] as $thumb) {
                    $bytes = MediaStore::read($source, MediaStore::businessPath($id, $thumb));
                    $expected = $thumb ? ($row->thumbnail_sha256 ?? null) : ($row->sha256 ?? null);
                    if (! is_string($expected) || ! hash_equals($expected, hash('sha256', $bytes))) {
                        throw new RuntimeException('media_cdn_integrity');
                    }
                    if ($disk->put(self::path($businessId, $id, $thumb), $bytes, $options) !== true) {
                        throw new RuntimeException('media_cdn_put_failed');
                    }
                }
            }
        }
        $urls = [];
        foreach ($plan['delete'] as $id) {
            foreach ([false, true] as $thumb) {
                $path = self::path($businessId, $id, $thumb);
                if ($disk->exists($path) && $disk->delete($path) !== true) {
                    throw new RuntimeException('media_cdn_delete_failed');
                }
                $urls[] = self::url($businessId, $id, $thumb);
            }
        }
        self::purge($urls);

        return $plan;
    }

    /**
     * Tek kovada ozel dizinler CDN alan adinin arkasindadir; yalniz Cloudflare kurali (WAF) onlari kapatir
     * (dizin adinin tahmin edilememesi ek katmandir, koruma degildir).
     * Ozel ve marka dizininde olmayan bir yolu yoklar: 403 kural var, baska durum (404 dahil) kural yok demektir.
     * Ag hatasinda null.
     */
    public static function privateBlocked(): ?bool
    {
        $roots = [];
        foreach (array_filter([config('filesystems.default'), config('ads.brand_media_disk')], 'is_string') as $private) {
            $other = config('filesystems.disks.'.$private);
            if (is_array($other) && ($other['driver'] ?? null) === 's3' && self::root($other) !== '') {
                $roots[] = self::root($other);
            }
        }
        try {
            foreach (array_unique($roots) as $root) {
                $status = Http::timeout(5)->withoutRedirecting()->head(config('media.cdn.url').'/'.$root.'/probe-'.bin2hex(random_bytes(8)).'.webp')->status();
                if ($status !== 403) {
                    return false;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return true;
    }

    /** Cloudflare onbellek temizleme (istege bagli). Basarisizlik isi durdurmaz; nesne max_age sonunda duser. */
    public static function purge(array $urls): void
    {
        $zone = config('media.cdn.purge_zone');
        $token = config('media.cdn.purge_token');
        if ($urls === [] || ! is_string($zone) || preg_match('/^[0-9a-f]{32}$/D', $zone) !== 1 || ! is_string($token) || $token === '') {
            return;
        }
        foreach (array_chunk($urls, 30) as $files) {
            try {
                $ok = Http::withToken($token)->acceptJson()->timeout(10)
                    ->post('https://api.cloudflare.com/client/v4/zones/'.$zone.'/purge_cache', ['files' => $files])
                    ->successful();
            } catch (\Throwable) {
                $ok = false;
            }
            if (! $ok) {
                Log::warning('Media CDN cache purge failed', ['count' => count($files)]);
            }
        }
    }
}
