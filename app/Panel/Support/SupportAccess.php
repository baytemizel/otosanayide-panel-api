<?php

namespace App\Panel\Support;

use App\Exceptions\HttpError;
use App\Panel\Auth\Member;
use App\Panel\Settings\Settings;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Urun plani: "Admin hesabi gizlice isletme sahibinin hesabina girmez; destek erisimi varsa acik, sureli ve
 * kayitlidir." Erisim kullanicinin oturumunu acmaz; yalniz panelde salt okunur kisisel veri gorunumu verir.
 */
final class SupportAccess
{
    public const SCOPES = ['garage', 'requests', 'notifications', 'favorites'];

    /** @param list<string> $scopes @return array<string,mixed> */
    public static function grant(Member $m, string $accountId, array $scopes, int $minutes, string $reason): array
    {
        $max = (int) Settings::get('support_access.max_minutes');
        if ($minutes < 5 || $minutes > $max) {
            throw new HttpError(422, 'Süre 5-'.$max.' dakika olmalı.');
        }
        if ($scopes === [] || array_diff($scopes, self::SCOPES) !== [] || count($scopes) !== count(array_unique($scopes))) {
            throw new HttpError(422, 'Kapsam geçersiz.');
        }
        if ($accountId === $m->accountId) {
            throw new HttpError(422, 'Kendi hesabın için destek erişimi açılmaz.');
        }

        return PanelDb::tx(static function () use ($m, $accountId, $scopes, $minutes, $reason): array {
            if (! DB::table('auth_accounts')->where('id', $accountId)->exists()) {
                throw new HttpError(404, 'Hesap bulunamadı.');
            }
            $active = PanelDb::table('panel_support_grants')->where('member_id', $m->accountId)->where('account_id', $accountId)
                ->whereNull('revoked_at')->where('expires_at', '>', Clock::db())->lockForUpdate()->exists();
            if ($active) {
                throw new HttpError(409, 'Bu hesap için etkin bir destek erişimin var.');
            }
            $id = Clock::uuid();
            $now = Clock::now();
            PanelDb::table('panel_support_grants')->insert(['id' => $id, 'member_id' => $m->accountId, 'account_id' => $accountId, 'scopes' => json_encode($scopes), 'reason' => $reason, 'starts_at' => Clock::db($now), 'expires_at' => Clock::db($now->addMinutes($minutes)), 'created_at' => Clock::db($now)]);

            return self::view(PanelDb::table('panel_support_grants')->where('id', $id)->first());
        });
    }

    /** @return array<string,mixed> */
    public static function revoke(Member $m, string $id): array
    {
        return PanelDb::tx(static function () use ($m, $id): array {
            $g = PanelDb::table('panel_support_grants')->where('id', $id)->lockForUpdate()->first();
            if ($g === null) {
                throw new HttpError(404, 'Destek erişimi bulunamadı.');
            }
            if ($g->member_id !== $m->accountId && ! $m->can('support.manage')) {
                throw new HttpError(403, 'Yalnız kendi destek erişimini kapatabilirsin.');
            }
            if ($g->revoked_at !== null || Clock::parse($g->expires_at)?->isPast() === true) {
                throw new HttpError(409, 'Destek erişimi zaten kapalı.');
            }
            PanelDb::table('panel_support_grants')->where('id', $id)->update(['revoked_at' => Clock::db(), 'revoked_by' => $m->accountId]);

            return self::view(PanelDb::table('panel_support_grants')->where('id', $id)->first());
        });
    }

    /** Veri gorunumu oncesi: etkin ve kapsami iceren erisim yoksa 403. */
    public static function assert(Member $m, string $accountId, string $scope): string
    {
        $grants = PanelDb::table('panel_support_grants')->where('member_id', $m->accountId)->where('account_id', $accountId)
            ->whereNull('revoked_at')->where('starts_at', '<=', Clock::db())->where('expires_at', '>', Clock::db())->get(['id', 'scopes']);
        foreach ($grants as $g) {
            if (in_array($scope, (array) json_decode((string) $g->scopes, true), true)) {
                return (string) $g->id;
            }
        }
        throw new HttpError(403, 'Bu hesap ve kapsam için etkin destek erişimin yok.');
    }

    /** @return array<string,mixed> */
    public static function view(stdClass $g): array
    {
        $active = $g->revoked_at === null && Clock::parse($g->expires_at)?->isFuture() === true;

        return [
            'id' => $g->id,
            'memberId' => $g->member_id,
            'accountId' => $g->account_id,
            'scopes' => json_decode((string) $g->scopes, true),
            'reason' => $g->reason,
            'startsAt' => Clock::iso($g->starts_at),
            'expiresAt' => Clock::iso($g->expires_at),
            'revokedAt' => Clock::iso($g->revoked_at),
            'revokedBy' => $g->revoked_by,
            'active' => $active,
        ];
    }
}
