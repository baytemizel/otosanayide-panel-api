<?php

namespace App\Panel\Audit;

use App\Panel\Auth\Member;
use App\Panel\Security\Ip;
use App\Panel\Support\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Yalniz ekleme yapan denetim kaydi. Alan yazimi kendi transaction'inda tamamlandiktan sonra yazilir;
 * denetim yazimi basarisiz olursa islem geri alinmaz, olay uygulama gunlugune duser (ileti olmadan).
 */
final class Audit
{
    /** @param array<string,mixed>|null $details */
    public static function record(?Request $request, string $action, ?string $subjectType = null, ?string $subjectId = null, ?string $reason = null, ?array $details = null, string $outcome = 'success', ?string $actorId = null): void
    {
        $member = $request?->attributes->get(Member::ATTRIBUTE);
        $row = [
            'occurred_at' => Clock::db(),
            // Uye yoksa (giris denemesi gibi) aktor 'system', varsa denenen hesap $actorId ile yazilir.
            'actor_type' => $member instanceof Member ? 'member' : ($request === null ? 'console' : 'system'),
            'actor_id' => $member instanceof Member ? $member->accountId : $actorId,
            'action' => $action,
            'outcome' => $outcome,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : mb_substr($subjectId, 0, 191),
            'reason' => $reason,
            'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'request_id' => $request?->attributes->get('request_id'),
            'ip_hash' => $request === null ? null : Ip::hash($request->ip()),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 255),
        ];
        try {
            DB::table('panel_audit_logs')->insert($row);
        } catch (Throwable $e) {
            Log::error('Panel audit write failed', ['action' => $action, 'class' => $e::class]);
        }
    }

    /** @param array<string,mixed>|null $details */
    public static function console(string $action, ?string $subjectType, ?string $subjectId, ?string $reason = null, ?array $details = null): void
    {
        DB::table('panel_audit_logs')->insert([
            'occurred_at' => Clock::db(),
            'actor_type' => 'console',
            'actor_id' => null,
            'action' => $action,
            'outcome' => 'success',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'reason' => $reason,
            'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array<string,mixed> */
    public static function view(\stdClass $r): array
    {
        return [
            'id' => (int) $r->id,
            'occurredAt' => Clock::iso($r->occurred_at),
            'actorType' => $r->actor_type,
            'actorId' => $r->actor_id,
            'action' => $r->action,
            'outcome' => $r->outcome,
            'subjectType' => $r->subject_type,
            'subjectId' => $r->subject_id,
            'reason' => $r->reason,
            'details' => $r->details === null ? null : json_decode((string) $r->details, true),
            'requestId' => $r->request_id,
        ];
    }
}
