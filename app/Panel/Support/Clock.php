<?php

namespace App\Panel\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Zaman ve kimlik yardimcilari. Veritabani UTC (+00:00) ve milisaniyelidir; yanitlar ISO-8601 Z. */
final class Clock
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function db(?CarbonImmutable $at = null): string
    {
        return ($at ?? self::now())->utc()->format('Y-m-d H:i:s.v');
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function msIso(mixed $ms): ?string
    {
        if ($ms === null || $ms === '') {
            return null;
        }

        return CarbonImmutable::createFromTimestampMs((int) $ms, 'UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    public static function parse(mixed $value): ?CarbonImmutable
    {
        return $value === null || $value === '' ? null : CarbonImmutable::parse((string) $value, 'UTC')->utc();
    }

    public static function uuid(): string
    {
        return (string) Str::uuid7();
    }
}
