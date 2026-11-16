<?php

namespace App\Panel\Accounts;

use App\Exceptions\HttpError;
use App\Panel\Auth\Sessions;
use App\Panel\Support\Clock;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Platform hesabi (auth_accounts) uzerindeki panel islemleri. Ana API ActorResolver kurallariyla uyumludur:
 * `status=disabled` girisi ve jetonlari reddeder; `tokens_valid_after` oncesi jetonlar gecersizdir;
 * yenileme oturumlari `revoked_at` ile kapanir. `erasing` durumundaki hesaba dokunulmaz (silme koordinatoru).
 */
final class AccountAdmin
{
    /** @return array<string,mixed> */
    public static function summary(stdClass $a): array
    {
        return [
            'id' => $a->id,
            'email' => $a->email,
            'emailVerified' => $a->email_verified_at !== null,
            'status' => $a->status,
            'admin' => (bool) $a->is_admin,
            'brandFinance' => (bool) $a->is_brand_finance,
            'accountErasureOperator' => (bool) $a->is_account_erasure_operator,
            'panelMember' => ($a->member_id ?? null) !== null,
            'createdAt' => Clock::iso($a->created_at),
            'version' => (int) $a->version,
        ];
    }

    public static function lock(string $id): stdClass
    {
        $a = DB::table('auth_accounts')->where('id', $id)->lockForUpdate()->first();
        if ($a === null) {
            throw new HttpError(404, 'Hesap bulunamadı.');
        }

        return $a;
    }

    private static function assertVersion(stdClass $a, int $version): void
    {
        if ((int) $a->version !== $version) {
            throw new HttpError(409, 'Hesap değişti. Yenileyip tekrar deneyin.');
        }
        if ($a->status === 'erasing' || DB::table('account_fences')->where('owner_uid', $a->id)->exists()) {
            throw new HttpError(409, 'Hesap silme sürecinde; panelden değiştirilemez.');
        }
    }

    /** Tum yenileme oturumlarini kapatir ve o ana kadar verilen erisim jetonlarini gecersiz kilar. */
    public static function revokeSessions(string $id, string $event): int
    {
        $now = Clock::db();
        $count = DB::table('auth_sessions')->where('account_id', $id)->whereNull('revoked_at')->update(['revoked_at' => $now]);
        DB::table('auth_accounts')->where('id', $id)->update(['tokens_valid_after' => $now]);
        self::event($id, $event);

        return $count;
    }

    public static function setStatus(string $id, string $status, int $version): void
    {
        $a = self::lock($id);
        self::assertVersion($a, $version);
        if ($a->status === $status) {
            throw new HttpError(409, $status === 'disabled' ? 'Hesap zaten devre dışı.' : 'Hesap zaten etkin.');
        }
        DB::table('auth_accounts')->where('id', $id)->update(['status' => $status, 'version' => $version + 1]);
        if ($status === 'disabled') {
            self::revokeSessions($id, 'panel_disabled');
            // Panel uyesiyse panel oturumlari da kapanir; giris zaten hesap durumunu denetler.
            Sessions::revokeAccount($id, 'account_disabled');
        } else {
            self::event($id, 'panel_enabled');
        }
    }

    /**
     * Platform yetkileri (ana API'nin uc boolean kolonu). Yalniz ikinci kontrolden sonra cagrilir.
     *
     * @param  array{admin:bool,brandFinance:bool,accountErasureOperator:bool}  $claims
     * @return array{before:array<string,bool>,after:array<string,bool>}
     */
    public static function applyClaims(string $id, array $claims, int $version): array
    {
        $a = self::lock($id);
        self::assertVersion($a, $version);
        $before = ['admin' => (bool) $a->is_admin, 'brandFinance' => (bool) $a->is_brand_finance, 'accountErasureOperator' => (bool) $a->is_account_erasure_operator];
        DB::table('auth_accounts')->where('id', $id)->update([
            'is_admin' => $claims['admin'],
            'is_brand_finance' => $claims['brandFinance'],
            'is_account_erasure_operator' => $claims['accountErasureOperator'],
            'version' => $version + 1,
        ]);
        // Urun karari (K169): rol degisiminde oturum iptali.
        self::revokeSessions($id, 'panel_claims_changed');

        return ['before' => $before, 'after' => $claims];
    }

    private static function event(string $id, string $event): void
    {
        // Ana API auth_events: ham IP yok; panel kaynakli olayda IP ozeti yazilmaz (anahtarlar farkli).
        DB::table('auth_events')->insert(['account_id' => $id, 'event' => $event, 'ip_hash' => null, 'occurred_at' => Clock::db()]);
    }
}
