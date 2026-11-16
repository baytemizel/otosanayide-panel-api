<?php

namespace App\Panel\Auth;

use App\Panel\Security\Ip;
use App\Panel\Support\Clock;
use App\Panel\Support\Secret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Panel oturumu: kisa omurlu erisim jetonu + donen yenileme jetonu (aile), hareketsizlik ve mutlak sure. */
final class Sessions
{
    /**
     * @return array{accessToken:string,accessTokenExpiresAt:string,refreshToken:string,refreshTokenExpiresAt:string,sessionExpiresAt:string,sessionId:string}
     */
    public static function issue(string $accountId, ?string $ip, string $userAgent, CarbonImmutable $mfaAt, ?string $familyId = null, ?CarbonImmutable $absolute = null): array
    {
        $now = Clock::now();
        $id = Clock::uuid();
        $refresh = Secret::token('opr_', 48);
        $absolute ??= $now->addHours((int) config('panel.session.absolute_hours'));
        $idle = $now->addMinutes((int) config('panel.session.idle_minutes'));
        if ($idle->greaterThan($absolute)) {
            $idle = $absolute;
        }
        DB::table('panel_sessions')->insert([
            'id' => $id,
            'account_id' => $accountId,
            'family_id' => $familyId ?? Clock::uuid(),
            'refresh_token_hash' => Secret::hash($refresh),
            'ip_hash' => Ip::hash($ip),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'mfa_at' => Clock::db($mfaAt),
            'created_at' => Clock::db($now),
            'idle_expires_at' => Clock::db($idle),
            'expires_at' => Clock::db($absolute),
        ]);
        $access = self::accessToken($id, $accountId, $absolute);

        return [
            'accessToken' => $access['token'],
            'accessTokenExpiresAt' => $access['expiresAt'],
            'refreshToken' => $refresh,
            'refreshTokenExpiresAt' => (string) Clock::iso(Clock::db($idle)),
            'sessionExpiresAt' => (string) Clock::iso(Clock::db($absolute)),
            'sessionId' => $id,
        ];
    }

    /** @return array{token:string,expiresAt:string} */
    private static function accessToken(string $sessionId, string $accountId, CarbonImmutable $absolute): array
    {
        $now = Clock::now();
        $token = Secret::token('opa_', 32);
        $expires = $now->addMinutes((int) config('panel.session.access_ttl_minutes'));
        if ($expires->greaterThan($absolute)) {
            $expires = $absolute;
        }
        DB::table('panel_access_tokens')->insert([
            'session_id' => $sessionId,
            'account_id' => $accountId,
            'token_hash' => Secret::hash($token),
            'expires_at' => Clock::db($expires),
            'created_at' => Clock::db($now),
        ]);

        return ['token' => $token, 'expiresAt' => (string) Clock::iso(Clock::db($expires))];
    }

    /** Ailenin tum oturumlarini kapatir ve erisim jetonlarini siler. */
    public static function revokeFamily(string $familyId, string $reason): int
    {
        $ids = DB::table('panel_sessions')->where('family_id', $familyId)->pluck('id')->all();
        DB::table('panel_access_tokens')->whereIn('session_id', $ids)->delete();

        return DB::table('panel_sessions')->where('family_id', $familyId)->whereNull('revoked_at')->update(['revoked_at' => Clock::db(), 'revoke_reason' => $reason]);
    }

    /** Uyenin tum panel oturumlari (rol degisimi, askiya alma, MFA sifirlama). */
    public static function revokeAccount(string $accountId, string $reason): int
    {
        DB::table('panel_access_tokens')->where('account_id', $accountId)->delete();

        return DB::table('panel_sessions')->where('account_id', $accountId)->whereNull('revoked_at')->update(['revoked_at' => Clock::db(), 'revoke_reason' => $reason]);
    }

    /** @return list<array<string,mixed>> Aile basina etkin son oturum. */
    public static function active(string $accountId): array
    {
        $rows = DB::table('panel_sessions')->where('account_id', $accountId)->whereNull('revoked_at')
            ->where('expires_at', '>', Clock::db())->where('idle_expires_at', '>', Clock::db())->orderByDesc('created_at')->get();

        return $rows->map(static fn (\stdClass $s): array => [
            'familyId' => $s->family_id,
            'userAgent' => $s->user_agent,
            'signedInAt' => Clock::iso($s->mfa_at),
            'lastRefreshAt' => Clock::iso($s->created_at),
            'idleExpiresAt' => Clock::iso($s->idle_expires_at),
            'expiresAt' => Clock::iso($s->expires_at),
        ])->values()->all();
    }
}
