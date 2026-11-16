<?php

namespace App\Panel\Http;

use App\Panel\Support\Clock;
use Illuminate\Support\Str;
use stdClass;

/** Ham tablo satirini yanit bicimine cevirir: camelCase anahtar, *_at ISO-8601, JSON ve boolean kolonlar acik. */
final class Shape
{
    /**
     * @param  list<string>  $json
     * @param  list<string>  $bool
     * @param  list<string>  $hide
     * @return array<string,mixed>
     */
    public static function row(?stdClass $r, array $json = [], array $bool = [], array $hide = []): ?array
    {
        if ($r === null) {
            return null;
        }
        $out = [];
        foreach ((array) $r as $key => $value) {
            if (in_array($key, $hide, true)) {
                continue;
            }
            if (in_array($key, $json, true)) {
                $value = $value === null ? null : json_decode((string) $value, true);
            } elseif (in_array($key, $bool, true)) {
                $value = $value === null ? null : (bool) $value;
            } elseif (is_string($value) && str_ends_with($key, '_at') && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $value) === 1) {
                $value = Clock::iso($value);
            }
            $out[Str::camel($key)] = $value;
        }

        return $out;
    }

    /**
     * @param  iterable<stdClass>  $rows
     * @return list<array<string,mixed>>
     */
    public static function rows(iterable $rows, array $json = [], array $bool = [], array $hide = []): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[] = (array) self::row($r, $json, $bool, $hide);
        }

        return $out;
    }
}
