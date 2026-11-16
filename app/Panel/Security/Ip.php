<?php

namespace App\Panel\Security;

use Symfony\Component\HttpFoundation\IpUtils;

/** IP kurallari. Ham IP saklanmaz; yalniz panel anahtariyla HMAC ozeti yazilir. */
final class Ip
{
    public static function hash(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        $key = config('panel.ip_hash_key') ?: config('app.key');

        return hash_hmac('sha256', $ip, (string) $key);
    }

    /** @return list<string> */
    public static function parseList(mixed $value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $v): string => trim((string) $v), $value), static fn (string $v): bool => $v !== ''));
    }

    public static function validCidr(string $value): bool
    {
        $parts = explode('/', $value, 2);
        if (filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (! isset($parts[1])) {
            return true;
        }
        $max = str_contains($parts[0], ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $max;
    }

    /** Bos liste kisit yok demektir. */
    public static function allowed(?string $ip, array $list): bool
    {
        if ($list === []) {
            return true;
        }

        return $ip !== null && $ip !== '' && IpUtils::checkIp($ip, $list);
    }

    public static function globallyAllowed(?string $ip): bool
    {
        return self::allowed($ip, self::parseList(config('panel.ip_allowlist')));
    }
}
