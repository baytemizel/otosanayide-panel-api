<?php

namespace App\Panel\Settings;

use App\Exceptions\HttpError;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use App\Panel\WorkItems\Queues;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use stdClass;

/** Panel ayarlari: varsayilan config('panel.defaults'), kalici deger panel_settings. Bilinmeyen anahtar yazilamaz. */
final class Settings
{
    private const DESCRIPTIONS = [
        'approvals.privileged_roles' => 'Verilmesi ikinci kontrol isteyen roller',
        'approvals.ttl_hours' => 'İkinci kontrol talebinin geçerlilik süresi (saat)',
        'support_access.max_minutes' => 'Destek erişiminin en uzun süresi (dakika)',
        'work_items.sla_hours' => 'Kuyruk başına hedef sonuçlanma süresi (saat)',
        'audit.retention_days' => 'Denetim kaydı saklama süresi (gün); boş: süresiz',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    public static function get(string $key): mixed
    {
        $defaults = (array) config('panel.defaults');
        if (! array_key_exists($key, $defaults)) {
            throw new \InvalidArgumentException('Unknown panel setting');
        }
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (PanelDb::table('panel_settings')->get(['key', 'value']) as $row) {
                    self::$cache[$row->key] = json_decode((string) $row->value, true);
                }
            } catch (QueryException) {
                // Tablo henuz yoksa (migration oncesi) varsayilanlar gecerlidir.
            }
        }

        return array_key_exists($key, self::$cache) ? self::$cache[$key] : $defaults[$key];
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        $rows = PanelDb::table('panel_settings')->get()->keyBy('key');
        $out = [];
        foreach ((array) config('panel.defaults') as $key => $default) {
            /** @var stdClass|null $row */
            $row = $rows->get($key);
            $out[] = [
                'key' => $key,
                'value' => $row === null ? $default : json_decode((string) $row->value, true),
                'default' => $default,
                'description' => self::DESCRIPTIONS[$key] ?? '',
                'version' => $row === null ? 0 : (int) $row->version,
                'updatedBy' => $row?->updated_by,
                'updatedAt' => $row === null ? null : Clock::iso($row->updated_at),
            ];
        }

        return $out;
    }

    public static function version(string $key): int
    {
        return (int) (PanelDb::table('panel_settings')->where('key', $key)->value('version') ?? 0);
    }

    /** @return array{before:mixed,after:mixed} */
    public static function put(string $key, mixed $value, int $version, string $actorId): array
    {
        if (! array_key_exists($key, (array) config('panel.defaults'))) {
            throw new HttpError(404, 'Ayar bulunamadı.');
        }
        self::validate($key, $value);

        return PanelDb::tx(static function () use ($key, $value, $version, $actorId): array {
            $row = PanelDb::table('panel_settings')->where('key', $key)->lockForUpdate()->first();
            $current = $row === null ? 0 : (int) $row->version;
            if ($current !== $version) {
                throw new HttpError(409, 'Ayar değişti. Yenileyip tekrar deneyin.');
            }
            $before = $row === null ? config('panel.defaults.'.$key) : json_decode((string) $row->value, true);
            $json = json_encode($value, JSON_UNESCAPED_UNICODE);
            if ($row === null) {
                PanelDb::table('panel_settings')->insert(['key' => $key, 'value' => $json, 'description' => self::DESCRIPTIONS[$key] ?? '', 'version' => 1, 'updated_by' => $actorId, 'updated_at' => Clock::db()]);
            } else {
                PanelDb::table('panel_settings')->where('key', $key)->update(['value' => $json, 'version' => $current + 1, 'updated_by' => $actorId, 'updated_at' => Clock::db()]);
            }
            self::forget();

            return ['before' => $before, 'after' => $value];
        });
    }

    public static function validate(string $key, mixed $value): void
    {
        $ok = match ($key) {
            'approvals.privileged_roles' => is_array($value) && array_is_list($value) && count($value) <= 20 && array_filter($value, static fn (mixed $v): bool => ! is_string($v) || preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $v) !== 1) === []
                && in_array('super_admin', $value, true),
            'approvals.ttl_hours' => is_int($value) && $value >= 1 && $value <= 336,
            'support_access.max_minutes' => is_int($value) && $value >= 15 && $value <= 1440,
            'work_items.sla_hours' => is_array($value) && ! array_is_list($value) && array_filter($value, static fn (mixed $v, mixed $k): bool => ! is_string($k) || ! array_key_exists($k, Queues::all()) || ! is_int($v) || $v < 1 || $v > 2160, ARRAY_FILTER_USE_BOTH) === [],
            'audit.retention_days' => $value === null || (is_int($value) && $value >= 365 && $value <= 3650),
            default => false,
        };
        if (! $ok) {
            throw ValidationException::withMessages(['value' => ['Ayar değeri geçersiz. Bkz. docs/API.md "Ayarlar".']]);
        }
    }
}
