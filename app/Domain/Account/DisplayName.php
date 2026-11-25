<?php

namespace App\Domain\Account;

/**
 * v2: auth_accounts.display_name (tam ad, istege bagli) baskalarina kisaltilarak gosterilir: "Deniz Kaya" -> "Deniz K.".
 * Ad yoksa null doner; e-posta asla ad yerine kullanilmaz.
 */
final class DisplayName
{
    public static function short(?string $full): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }
        $first = mb_substr($parts[0], 0, 40);
        if (count($parts) === 1) {
            return $first;
        }

        return $first.' '.mb_strtoupper(mb_substr((string) end($parts), 0, 1, 'UTF-8'), 'UTF-8').'.';
    }

    /** Avatar harfleri: ad + soyad bas harfleri ("DK"), tek kelimede ilk iki harf. */
    public static function initials(?string $full): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return null;
        }
        $s = count($parts) === 1 ? mb_substr($parts[0], 0, 2) : mb_substr($parts[0], 0, 1).mb_substr((string) end($parts), 0, 1);

        return mb_strtoupper($s, 'UTF-8');
    }
}
