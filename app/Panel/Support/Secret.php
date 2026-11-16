<?php

namespace App\Panel\Support;

/** Opak jetonlar: istemciye duz metin verilir, veritabanina yalniz SHA-256 ozeti yazilir. */
final class Secret
{
    public static function token(string $prefix, int $bytes = 32): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function emailKey(string $email): string
    {
        return hash_hmac('sha256', $email, (string) config('app.key'));
    }

    /** Ana API ile ayni e-posta bicimi (AuthController::email). */
    public static function normalizeEmail(string $email): string
    {
        return (string) normalizer_normalize(mb_strtolower(trim($email), 'UTF-8'), \Normalizer::FORM_C);
    }
}
