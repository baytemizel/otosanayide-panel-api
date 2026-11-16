<?php

namespace App\Panel\Auth;

use App\Domain\Auth\Totp;
use App\Exceptions\HttpError;
use App\Panel\Security\Ip;
use App\Panel\Support\Clock;
use App\Panel\Support\Secret;
use App\Support\Tx;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use stdClass;

/**
 * Panel girisi: ana API hesabinin e-posta + sifresi, ardindan panelin kendi TOTP adimi.
 * Panel uyeligi olmayan, devre disi, dogrulanmamis veya silme surecindeki hesap giremez.
 * Basarisiz giris yaniti nedeni aciga cikarmaz.
 */
final class PanelAuth
{
    public const FAILED = 'E-posta, şifre veya panel yetkisi hatalı.';

    public const INVALID_SESSION = 'Oturum geçersiz veya süresi dolmuş.';

    /** Hesap basina hatali TOTP kodu siniri: her giriste yeni adim acilsa da toplam deneme sinirlidir. */
    public const MFA_FAILURES = 10;

    public const MFA_LOCK_SECONDS = 900;

    /** Ayni yenileme jetonunun bu sure icinde tekrar gelmesi (yaris, kaybolan yanit) yeniden kullanim sayilmaz. */
    public const REFRESH_GRACE_SECONDS = 10;

    public static function mfaLocked(string $accountId): bool
    {
        return RateLimiter::tooManyAttempts('panel-mfa-fail:'.$accountId, self::MFA_FAILURES);
    }

    public static function recordMfaFailure(string $accountId): void
    {
        RateLimiter::hit('panel-mfa-fail:'.$accountId, self::MFA_LOCK_SECONDS);
    }

    // Ana API AuthService::DUMMY_HASH ile ayni: hesap yokken de ayni maliyette dogrulama.
    private const DUMMY_HASH = '$argon2id$v=19$m=65536,t=3,p=1$VTZGYkpqd0hISEN1MWJQNw$0EdfUhE7acbqW9b3KRnA7R7mHjRG/ZZxWdNMZvtM/qY';

    /** Hesap + uyelik + silme cercevesi tek sorguda. */
    public static function account(string $column, string $value): ?stdClass
    {
        return DB::table('auth_accounts as a')
            ->leftJoin('panel_members as m', 'm.account_id', '=', 'a.id')
            ->leftJoin('account_fences as f', 'f.owner_uid', '=', 'a.id')
            ->where('a.'.$column, $value)
            ->first(['a.id', 'a.email', 'a.email_verified_at', 'a.password_hash', 'a.status', 'a.tokens_valid_after',
                'm.account_id as member_id', 'm.status as member_status', 'm.display_name', 'm.ip_allowlist', 'm.mfa_enroll_until', 'f.state as fence']);
    }

    public static function eligible(?stdClass $a, ?string $ip): bool
    {
        return $a !== null && $a->status === 'active' && $a->email_verified_at !== null && $a->member_id !== null
            && $a->member_status === 'active' && $a->fence === null
            && Ip::allowed($ip, Ip::parseList(json_decode((string) ($a->ip_allowlist ?? 'null'), true)))
            && DB::table('panel_member_roles')->where('account_id', $a->id)->exists();
    }

    /** @return array{step:string,challengeToken:string,challengeExpiresAt:string,secret?:string,otpauthUri?:string,accountId:string} */
    public static function login(string $email, string $password, ?string $ip): array
    {
        $a = self::account('email', Secret::normalizeEmail($email));
        $valid = Hash::check($password, $a->password_hash ?? self::DUMMY_HASH);
        if (! $valid || ! self::eligible($a, $ip)) {
            throw new LoginFailed($a?->id);
        }
        if (self::mfaLocked($a->id)) {
            throw new HttpError(429, 'Çok fazla hatalı doğrulama kodu. 15 dakika sonra yeniden deneyin.');
        }
        $factor = DB::table('panel_mfa_factors')->where('account_id', $a->id)->whereNull('disabled_at')->whereNotNull('verified_at')->first();
        if ($factor !== null) {
            return ['step' => 'mfa'] + self::challenge($a->id, 'mfa', $factor->id, $ip) + ['accountId' => $a->id];
        }
        $until = Clock::parse($a->mfa_enroll_until);
        if ($until === null || $until->isPast()) {
            throw new HttpError(403, 'Panel iki adımlı doğrulama kurulum süresi doldu. Bir yöneticiden MFA sıfırlaması isteyin.');
        }

        return Tx::run(static function () use ($a, $ip): array {
            DB::table('panel_mfa_factors')->where('account_id', $a->id)->whereNull('verified_at')->whereNull('disabled_at')->update(['disabled_at' => Clock::db()]);
            $secret = Totp::newSecret();
            $factorId = Clock::uuid();
            DB::table('panel_mfa_factors')->insert(['id' => $factorId, 'account_id' => $a->id, 'secret_ciphertext' => Crypt::encryptString($secret), 'created_at' => Clock::db()]);
            $issuer = (string) config('panel.mfa.issuer');
            $uri = 'otpauth://totp/'.rawurlencode($issuer.':'.$a->email).'?'.http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => 6, 'period' => Totp::PERIOD], '', '&', PHP_QUERY_RFC3986);

            return ['step' => 'mfa_enrollment'] + self::challenge($a->id, 'enroll', $factorId, $ip) + ['secret' => $secret, 'otpauthUri' => $uri, 'accountId' => $a->id];
        });
    }

    /** @return array{challengeToken:string,challengeExpiresAt:string} */
    private static function challenge(string $accountId, string $purpose, string $factorId, ?string $ip): array
    {
        $token = Secret::token('opc_', 32);
        $expires = Clock::now()->addSeconds((int) config('panel.mfa.challenge_seconds'));
        DB::table('panel_login_challenges')->insert([
            'token_hash' => Secret::hash($token), 'account_id' => $accountId, 'purpose' => $purpose, 'factor_id' => $factorId,
            'ip_hash' => Ip::hash($ip), 'created_at' => Clock::db(), 'expires_at' => Clock::db($expires),
        ]);

        return ['challengeToken' => $token, 'challengeExpiresAt' => (string) Clock::iso(Clock::db($expires))];
    }

    /** @return array{tokens:array<string,string>,accountId:string,enrolled:bool} */
    public static function verifyMfa(string $challengeToken, string $code, ?string $ip, string $userAgent): array
    {
        $result = Tx::run(static function () use ($challengeToken, $code, $ip, $userAgent): array {
            $c = DB::table('panel_login_challenges')->where('token_hash', Secret::hash($challengeToken))->lockForUpdate()->first();
            if ($c === null || $c->consumed_at !== null || Clock::parse($c->expires_at)?->isPast() !== false || $c->attempts >= (int) config('panel.mfa.max_attempts')) {
                throw new HttpError(401, 'Doğrulama adımı geçersiz veya süresi dolmuş. Yeniden giriş yapın.');
            }
            if (self::mfaLocked($c->account_id)) {
                throw new HttpError(429, 'Çok fazla hatalı doğrulama kodu. 15 dakika sonra yeniden deneyin.');
            }
            $factor = DB::table('panel_mfa_factors')->where('id', $c->factor_id)->whereNull('disabled_at')->lockForUpdate()->first();
            $step = $factor === null ? null : Totp::matchStep(Crypt::decryptString($factor->secret_ciphertext), $code, time(), $factor->last_used_step === null ? null : (int) $factor->last_used_step);
            if ($step === null) {
                $attempts = (int) $c->attempts + 1;
                DB::table('panel_login_challenges')->where('token_hash', $c->token_hash)->update(['attempts' => $attempts, 'consumed_at' => $attempts >= (int) config('panel.mfa.max_attempts') ? Clock::db() : null]);

                return ['failed' => true, 'accountId' => $c->account_id];
            }
            $a = self::account('id', $c->account_id);
            if (! self::eligible($a, $ip)) {
                throw new LoginFailed($c->account_id);
            }
            $now = Clock::now();
            $enrolled = $factor->verified_at === null;
            DB::table('panel_mfa_factors')->where('id', $factor->id)->update(['last_used_step' => $step, 'verified_at' => $factor->verified_at ?? Clock::db($now)]);
            if ($enrolled) {
                DB::table('panel_mfa_factors')->where('account_id', $c->account_id)->where('id', '!=', $factor->id)->whereNull('disabled_at')->update(['disabled_at' => Clock::db($now)]);
                DB::table('panel_members')->where('account_id', $c->account_id)->update(['mfa_enroll_until' => null]);
            }
            DB::table('panel_login_challenges')->where('token_hash', $c->token_hash)->update(['consumed_at' => Clock::db($now)]);
            DB::table('panel_members')->where('account_id', $c->account_id)->update(['last_login_at' => Clock::db($now), 'last_login_ip_hash' => Ip::hash($ip)]);

            return ['tokens' => Sessions::issue($c->account_id, $ip, $userAgent, $now), 'accountId' => $c->account_id, 'enrolled' => $enrolled];
        });
        if (isset($result['failed'])) {
            // Deneme sayaci transaction disinda kalici olsun diye hata transaction'dan sonra atilir.
            self::recordMfaFailure($result['accountId']);
            throw new MfaFailed($result['accountId']);
        }

        return $result;
    }

    /** @return array{tokens:array<string,string>,accountId:string} */
    public static function refresh(string $refreshToken, ?string $ip, string $userAgent): array
    {
        $result = Tx::run(static function () use ($refreshToken, $ip, $userAgent): array {
            $s = DB::table('panel_sessions')->where('refresh_token_hash', Secret::hash($refreshToken))->lockForUpdate()->first();
            if ($s === null) {
                throw new HttpError(401, self::INVALID_SESSION);
            }
            $now = Clock::now();
            if ($s->revoked_at !== null) {
                if ($s->revoke_reason !== 'rotated') {
                    throw new HttpError(401, self::INVALID_SESSION);
                }
                $successor = $s->replaced_by === null ? null : DB::table('panel_sessions')->where('id', $s->replaced_by)->lockForUpdate()->first();
                $recent = Clock::parse($s->revoked_at)?->greaterThan($now->subSeconds(self::REFRESH_GRACE_SECONDS)) === true;
                if (! $recent || $successor === null || $successor->revoked_at !== null) {
                    // Donmus jeton tekrar kullanildi: aile kapanir.
                    Sessions::revokeFamily($s->family_id, 'reuse');

                    return ['reuse' => true, 'accountId' => $s->account_id];
                }
                // Tolerans: ayni anda iki yenileme ya da yaniti kaybolan tekrar. Ilk ardil iptal edilir, yenisi verilir.
                DB::table('panel_access_tokens')->where('session_id', $successor->id)->delete();
                DB::table('panel_sessions')->where('id', $successor->id)->update(['revoked_at' => Clock::db($now), 'revoke_reason' => 'retried']);
            }
            if (Clock::parse($s->idle_expires_at)?->lessThanOrEqualTo($now) || Clock::parse($s->expires_at)?->lessThanOrEqualTo($now)) {
                Sessions::revokeFamily($s->family_id, 'expired');

                return ['expired' => true, 'accountId' => $s->account_id];
            }
            $a = self::account('id', $s->account_id);
            if (! self::eligible($a, $ip) || Clock::parse($a->tokens_valid_after)?->greaterThan(Clock::parse($s->mfa_at)) === true) {
                Sessions::revokeFamily($s->family_id, 'ineligible');

                return ['expired' => true, 'accountId' => $s->account_id];
            }
            $tokens = Sessions::issue($s->account_id, $ip, $userAgent, Clock::parse($s->mfa_at) ?? $now, $s->family_id, Clock::parse($s->expires_at));
            DB::table('panel_sessions')->where('id', $s->id)->update(['revoked_at' => $s->revoked_at ?? Clock::db($now), 'revoke_reason' => 'rotated', 'replaced_by' => $tokens['sessionId']]);

            return ['tokens' => $tokens, 'accountId' => $s->account_id];
        });
        if (isset($result['reuse'])) {
            throw new RefreshReused($result['accountId']);
        }
        if (isset($result['expired'])) {
            throw new HttpError(401, self::INVALID_SESSION);
        }

        return $result;
    }

    /** Yenileme jetonuyla cikis (Bearer yokken); bilinmeyen jeton sessizce yok sayilir. */
    public static function logoutByRefresh(string $refreshToken): ?string
    {
        $s = DB::table('panel_sessions')->where('refresh_token_hash', Secret::hash($refreshToken))->first();
        if ($s === null) {
            return null;
        }
        Sessions::revokeFamily($s->family_id, 'logout');

        return $s->account_id;
    }

    /** Her istekte: jeton -> oturum -> hesap -> uyelik -> cerceve -> IP -> roller. */
    public static function resolve(string $bearer, ?string $ip): Member
    {
        $row = DB::table('panel_access_tokens as t')
            ->join('panel_sessions as s', 's.id', '=', 't.session_id')
            ->where('t.token_hash', Secret::hash($bearer))
            ->first(['t.id as token_id', 't.expires_at', 't.created_at as token_created_at', 't.last_used_at', 't.account_id',
                's.id as session_id', 's.family_id', 's.revoked_at', 's.revoke_reason', 's.expires_at as session_expires_at', 's.mfa_at']);
        $now = Clock::now();
        if ($row === null || Clock::parse($row->expires_at)?->lessThanOrEqualTo($now) !== false
            || ($row->revoked_at !== null && $row->revoke_reason !== 'rotated')
            || Clock::parse($row->session_expires_at)?->lessThanOrEqualTo($now) !== false) {
            throw new HttpError(401, self::INVALID_SESSION);
        }
        $a = self::account('id', $row->account_id);
        if (! self::eligible($a, $ip) || Clock::parse($a->tokens_valid_after)?->greaterThan(Clock::parse($row->mfa_at)) === true) {
            throw new HttpError(401, self::INVALID_SESSION);
        }
        if ($row->last_used_at === null || Clock::parse($row->last_used_at)?->lessThan($now->subMinute())) {
            DB::table('panel_access_tokens')->where('id', $row->token_id)->update(['last_used_at' => Clock::db($now)]);
        }
        $p = Permissions::forAccount($a->id);

        return new Member($a->id, $a->email, (string) $a->display_name, $p['roles'], $p['permissions'], $row->session_id, $row->family_id, (int) Clock::parse($row->mfa_at)?->getTimestampMs());
    }
}
