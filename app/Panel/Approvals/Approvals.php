<?php

namespace App\Panel\Approvals;

use App\Exceptions\HttpError;
use App\Panel\Accounts\AccountAdmin;
use App\Panel\Auth\Member;
use App\Panel\Members\MemberAdmin;
use App\Panel\Members\RoleAdmin;
use App\Panel\Settings\Settings;
use App\Panel\Support\Clock;
use App\Support\Tx;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Ikinci kontrol (dort goz). Urun plani: "Sahiplik devri, ayricalikli rol verme ve yuksek tutarli iade icin
 * ikinci kontrol kurali yapilandirilabilir." Bu surumde: ayricalikli panel rolu verme (uye olusturma ve rol
 * degisikligi), role hassas yetki ekleme, approvals.* ayarlari ve platform yetkisi degisikligi. Talep eden ve
 * talebin konusu olan kisi karar veremez; onayda islem ayni transaction'da kurallar yeniden uygulanarak yurur.
 */
final class Approvals
{
    public const ACTIONS = [
        'member.create' => ['permission' => 'members.manage', 'subject' => 'account'],
        'member.roles' => ['permission' => 'members.manage', 'subject' => 'panel_member'],
        'account.claims' => ['permission' => 'accounts.claims', 'subject' => 'account'],
        'role.permissions' => ['permission' => 'roles.manage', 'subject' => 'panel_role'],
        'setting.update' => ['permission' => 'settings.manage', 'subject' => 'panel_setting'],
    ];

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function request(Member $m, string $action, string $subjectId, array $payload, string $summary, string $reason): array
    {
        $def = self::ACTIONS[$action];

        return Tx::run(static function () use ($m, $action, $def, $subjectId, $payload, $summary, $reason): array {
            $open = DB::table('panel_approvals')->where('action', $action)->where('subject_id', $subjectId)->where('status', 'pending')
                ->where('expires_at', '>', Clock::db())->lockForUpdate()->exists();
            if ($open) {
                throw new HttpError(409, 'Bu kayıt için bekleyen bir ikinci kontrol talebi var.');
            }
            $id = Clock::uuid();
            $now = Clock::now();
            DB::table('panel_approvals')->insert([
                'id' => $id, 'action' => $action, 'subject_type' => $def['subject'], 'subject_id' => $subjectId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'summary' => mb_substr($summary, 0, 500),
                'requested_by' => $m->accountId, 'request_reason' => $reason,
                'expires_at' => Clock::db($now->addHours((int) Settings::get('approvals.ttl_hours'))),
                'created_at' => Clock::db($now), 'updated_at' => Clock::db($now),
            ]);

            return self::view(DB::table('panel_approvals')->where('id', $id)->first());
        });
    }

    /** @return array<string,mixed> */
    public static function decide(Member $m, string $id, int $version, bool $approve, string $reason): array
    {
        try {
            return Tx::run(static function () use ($m, $id, $version, $approve, $reason): array {
                $a = self::lockPending($id, $version);
                if ($a->requested_by === $m->accountId) {
                    throw new HttpError(403, 'Kendi talebini onaylayamaz veya reddedemezsin.');
                }
                // Talebin konusu olan kisi (yetki verilen hesap ya da degisen rolun sahibi) karar veremez.
                if ($a->subject_type !== 'panel_role' && $a->subject_type !== 'panel_setting' && $a->subject_id === $m->accountId) {
                    throw new HttpError(403, 'Seni ilgilendiren talebe karar veremezsin.');
                }
                if ($a->subject_type === 'panel_role' && in_array($a->subject_id, $m->roles, true)) {
                    throw new HttpError(403, 'Sahip olduğun rolün yetki değişikliğine karar veremezsin.');
                }
                $m->require(self::ACTIONS[$a->action]['permission']);
                $now = Clock::db();
                if ($approve) {
                    self::execute($a, $m);
                }
                DB::table('panel_approvals')->where('id', $id)->update(['status' => $approve ? 'approved' : 'rejected', 'decided_by' => $m->accountId, 'decision_reason' => $reason, 'decided_at' => $now, 'version' => $version + 1, 'updated_at' => $now]);

                return self::view(DB::table('panel_approvals')->where('id', $id)->first());
            });
        } catch (HttpError $e) {
            if ($approve && $e->status === 409 && str_starts_with($e->getMessage(), '[execute]')) {
                $message = substr($e->getMessage(), 9);
                DB::table('panel_approvals')->where('id', $id)->where('status', 'pending')->update(['status' => 'failed', 'decided_by' => $m->accountId, 'decision_reason' => $reason, 'decided_at' => Clock::db(), 'failure' => mb_substr($message, 0, 500), 'version' => $version + 1, 'updated_at' => Clock::db()]);
                throw new HttpError(409, 'Onaylanan işlem uygulanamadı: '.$message);
            }
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function cancel(Member $m, string $id, int $version, string $reason): array
    {
        return Tx::run(static function () use ($m, $id, $version, $reason): array {
            $a = self::lockPending($id, $version);
            if ($a->requested_by !== $m->accountId && ! $m->can('approvals.decide')) {
                throw new HttpError(403, 'Yalnız talep eden iptal edebilir.');
            }
            DB::table('panel_approvals')->where('id', $id)->update(['status' => 'cancelled', 'decided_by' => $m->accountId, 'decision_reason' => $reason, 'decided_at' => Clock::db(), 'version' => $version + 1, 'updated_at' => Clock::db()]);

            return self::view(DB::table('panel_approvals')->where('id', $id)->first());
        });
    }

    private static function lockPending(string $id, int $version): stdClass
    {
        $a = DB::table('panel_approvals')->where('id', $id)->lockForUpdate()->first();
        if ($a === null) {
            throw new HttpError(404, 'Talep bulunamadı.');
        }
        if ((int) $a->version !== $version) {
            throw new HttpError(409, 'Talep değişti. Yenileyip tekrar deneyin.');
        }
        if ($a->status !== 'pending') {
            throw new HttpError(409, 'Talep artık beklemede değil.');
        }
        if (Clock::parse($a->expires_at)?->isPast() === true) {
            throw new HttpError(409, 'Talebin süresi doldu.');
        }

        return $a;
    }

    /** Onay aninda kurallar yeniden uygulanir; hata '[execute]' onekiyle 409 olur ve talep 'failed' isaretlenir. */
    private static function execute(stdClass $a, Member $approver): void
    {
        $p = json_decode((string) $a->payload, true);
        try {
            match ($a->action) {
                'member.create' => MemberAdmin::create((string) $p['accountId'], (string) $p['displayName'], (array) $p['roles'], (array) ($p['ipAllowlist'] ?? []), $approver->accountId, (string) $a->requested_by),
                'member.roles' => MemberAdmin::setRoles((string) $p['accountId'], (array) $p['roles'], (int) $p['version'], $approver->accountId),
                'account.claims' => AccountAdmin::applyClaims((string) $p['accountId'], ['admin' => (bool) $p['admin'], 'brandFinance' => (bool) $p['brandFinance'], 'accountErasureOperator' => (bool) $p['accountErasureOperator']], (int) $p['version']),
                'role.permissions' => RoleAdmin::update((string) $p['key'], array_intersect_key($p, ['version' => 1, 'name' => 1, 'description' => 1, 'permissions' => 1])),
                'setting.update' => Settings::put((string) $p['key'], $p['value'], (int) $p['version'], $approver->accountId),
                default => throw new HttpError(409, 'Bilinmeyen işlem.'),
            };
        } catch (HttpError $e) {
            throw new HttpError(409, '[execute]'.$e->getMessage());
        } catch (QueryException $e) {
            // Kilitlenme/baglanti hatasi kalici "failed" yapilmaz: transaction yeniden dener, talep beklemede kalir.
            throw $e;
        } catch (Throwable $e) {
            throw new HttpError(409, '[execute]İşlem uygulanamadı.');
        }
    }

    /** Suresi gecen bekleyen talepler (panel:housekeeping). */
    public static function expire(bool $apply): int
    {
        $q = DB::table('panel_approvals')->where('status', 'pending')->where('expires_at', '<=', Clock::db());
        if (! $apply) {
            return $q->count();
        }

        return $q->update(['status' => 'expired', 'updated_at' => Clock::db()]);
    }

    /** @return array<string,mixed> */
    public static function view(stdClass $a): array
    {
        return [
            'id' => $a->id,
            'action' => $a->action,
            'subjectType' => $a->subject_type,
            'subjectId' => $a->subject_id,
            'payload' => json_decode((string) $a->payload, true),
            'summary' => $a->summary,
            'requestedBy' => $a->requested_by,
            'requestReason' => $a->request_reason,
            'status' => $a->status,
            'decidedBy' => $a->decided_by,
            'decisionReason' => $a->decision_reason,
            'decidedAt' => Clock::iso($a->decided_at),
            'failure' => $a->failure,
            'expiresAt' => Clock::iso($a->expires_at),
            'createdAt' => Clock::iso($a->created_at),
            'version' => (int) $a->version,
        ];
    }
}
