<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Approvals\Approvals;
use App\Panel\Auth\Permissions;
use App\Panel\Auth\Sessions;
use App\Panel\Http\Paging;
use App\Panel\Http\Respond;
use App\Panel\Members\MemberAdmin;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/v1/members: panel uyeleri. Ayricalikli rol verme ikinci kontrole duser (202). */
final class MemberController extends PanelController
{
    public function index(Request $request): JsonResponse
    {
        $f = $this->query($request, ['status' => ['nullable', 'in:active,suspended'], 'role' => ['nullable', 'string', 'max:64'], 'q' => ['nullable', 'string', 'max:120']]);
        $q = PanelDb::table('panel_members as m')->select('m.*')->orderBy('m.display_name');
        if (isset($f['status'])) {
            $q->where('m.status', $f['status']);
        }
        if (isset($f['role'])) {
            $q->whereExists(fn ($w) => $w->from('panel_member_roles as mr')->join('panel_roles as r', 'r.id', '=', 'mr.role_id')->whereColumn('mr.account_id', 'm.account_id')->where('r.key', $f['role']));
        }
        if (isset($f['q'])) {
            // E-posta ana veritabaninda: yalniz panel uyeleri arasinda aranir, eslesen kimlikler panel sorgusuna verilir.
            $byEmail = DB::table('auth_accounts')->whereIn('id', MemberAdmin::accountIds())
                ->where('email', 'like', addcslashes(mb_strtolower($f['q']), '%_\\').'%')->pluck('id')->all();
            $q->where(fn ($w) => $w->where('m.display_name', 'like', '%'.addcslashes($f['q'], '%_\\').'%')->orWhereIn('m.account_id', $byEmail));
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(array_map(MemberAdmin::view(...), MemberAdmin::withEmails($rows)), $paging, $total);
    }

    public function show(Request $request, string $accountId): JsonResponse
    {
        $m = MemberAdmin::row($accountId) ?? $this->notFound('Panel üyesi');

        return Respond::data(MemberAdmin::view($m) + ['sessions' => Sessions::active($accountId)]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->member($request);
        $in = $this->input($request, [
            'email' => ['required', 'string', 'max:254'],
            'displayName' => ['required', 'string', 'min:2', 'max:120'],
            'roles' => ['required', 'array', 'min:1', 'max:10'], 'roles.*' => ['string', 'max:64'],
            'ipAllowlist' => ['sometimes', 'array', 'max:20'], 'ipAllowlist.*' => ['string', 'max:64'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        MemberAdmin::assertIpList($in['ipAllowlist'] ?? []);
        $account = MemberAdmin::eligibleAccount($in['email']);
        MemberAdmin::assertRoles($in['roles']);
        if (PanelDb::table('panel_members')->where('account_id', $account->id)->exists()) {
            throw new HttpError(409, 'Bu hesap zaten panel üyesi.');
        }
        $privileged = array_values(array_filter($in['roles'], static fn (string $r): bool => Permissions::isPrivilegedRole($r)));
        if ($privileged !== []) {
            $approval = Approvals::request($actor, 'member.create', $account->id, ['accountId' => $account->id, 'displayName' => $in['displayName'], 'roles' => $in['roles'], 'ipAllowlist' => $in['ipAllowlist'] ?? []], 'Panel üyesi: '.$in['displayName'].' ('.implode(', ', $in['roles']).')', $in['reason']);
            $this->audit($request, 'approval.requested', 'panel_approval', $approval['id'], $in['reason'], ['action' => 'member.create', 'accountId' => $account->id, 'roles' => $in['roles']]);

            return Respond::pendingApproval($approval);
        }
        PanelDb::tx(fn () => MemberAdmin::create($account->id, $in['displayName'], $in['roles'], $in['ipAllowlist'] ?? [], $actor->accountId, $actor->accountId));
        $this->audit($request, 'member.created', 'panel_member', $account->id, $in['reason'], ['roles' => $in['roles']]);

        return Respond::created(MemberAdmin::view((object) MemberAdmin::row($account->id)));
    }

    public function update(Request $request, string $accountId): JsonResponse
    {
        $in = $this->input($request, [
            'version' => ['required', 'integer', 'min:1'],
            'displayName' => ['sometimes', 'string', 'min:2', 'max:120'],
            'ipAllowlist' => ['sometimes', 'array', 'max:20'], 'ipAllowlist.*' => ['string', 'max:64'],
        ]);
        MemberAdmin::assertIpList($in['ipAllowlist'] ?? []);
        $before = PanelDb::tx(function () use ($accountId, $in): array {
            $m = MemberAdmin::row($accountId, true) ?? $this->notFound('Panel üyesi');
            if ((int) $m->version !== $in['version']) {
                throw new HttpError(409, 'Üye kaydı değişti. Yenileyip tekrar deneyin.');
            }
            $patch = ['version' => $in['version'] + 1, 'updated_at' => Clock::db()];
            if (isset($in['displayName'])) {
                $patch['display_name'] = $in['displayName'];
            }
            if (array_key_exists('ipAllowlist', $in)) {
                $patch['ip_allowlist'] = $in['ipAllowlist'] === [] ? null : json_encode(array_values($in['ipAllowlist']));
            }
            PanelDb::table('panel_members')->where('account_id', $accountId)->update($patch);

            return MemberAdmin::view($m);
        });
        $after = MemberAdmin::view((object) MemberAdmin::row($accountId));
        $this->audit($request, 'member.updated', 'panel_member', $accountId, null, ['before' => array_intersect_key($before, ['displayName' => 1, 'ipAllowlist' => 1]), 'after' => array_intersect_key($after, ['displayName' => 1, 'ipAllowlist' => 1])]);

        return Respond::data($after);
    }

    public function roles(Request $request, string $accountId): JsonResponse
    {
        $actor = $this->member($request);
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'roles' => ['required', 'array', 'min:1', 'max:10'], 'roles.*' => ['string', 'max:64'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        MemberAdmin::row($accountId) ?? $this->notFound('Panel üyesi');
        MemberAdmin::assertRoles($in['roles']);
        $before = MemberAdmin::rolesOf($accountId);
        $privileged = MemberAdmin::privilegedAdditions($accountId, $in['roles']);
        if ($privileged !== []) {
            $approval = Approvals::request($actor, 'member.roles', $accountId, ['accountId' => $accountId, 'roles' => $in['roles'], 'version' => $in['version']], 'Rol değişikliği: '.implode(', ', $before).' → '.implode(', ', $in['roles']), $in['reason']);
            $this->audit($request, 'approval.requested', 'panel_approval', $approval['id'], $in['reason'], ['action' => 'member.roles', 'accountId' => $accountId, 'roles' => $in['roles']]);

            return Respond::pendingApproval($approval);
        }
        PanelDb::tx(fn () => MemberAdmin::setRoles($accountId, $in['roles'], $in['version'], $actor->accountId));
        $this->audit($request, 'member.roles_changed', 'panel_member', $accountId, $in['reason'], ['before' => $before, 'after' => $in['roles']]);

        return Respond::data(MemberAdmin::view((object) MemberAdmin::row($accountId)));
    }

    public function suspend(Request $request, string $accountId): JsonResponse
    {
        return $this->status($request, $accountId, 'suspended');
    }

    public function reactivate(Request $request, string $accountId): JsonResponse
    {
        return $this->status($request, $accountId, 'active');
    }

    private function status(Request $request, string $accountId, string $status): JsonResponse
    {
        $actor = $this->member($request);
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if ($accountId === $actor->accountId) {
            throw new HttpError(422, 'Kendi panel üyeliğini değiştiremezsin.');
        }
        PanelDb::tx(fn () => MemberAdmin::setStatus($accountId, $status, $in['reason'], $in['version']));
        $this->audit($request, $status === 'suspended' ? 'member.suspended' : 'member.reactivated', 'panel_member', $accountId, $in['reason']);

        return Respond::data(MemberAdmin::view((object) MemberAdmin::row($accountId)));
    }

    public function resetMfa(Request $request, string $accountId): JsonResponse
    {
        $actor = $this->member($request);
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if ($accountId === $actor->accountId) {
            throw new HttpError(422, 'Kendi MFA kaydını sıfırlayamazsın; başka bir yöneticiden iste.');
        }
        PanelDb::tx(fn () => MemberAdmin::resetMfa($accountId, $in['version']));
        $this->audit($request, 'member.mfa_reset', 'panel_member', $accountId, $in['reason']);

        return Respond::data(MemberAdmin::view((object) MemberAdmin::row($accountId)));
    }

    public function revokeSessions(Request $request, string $accountId): JsonResponse
    {
        $in = $this->input($request, ['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        MemberAdmin::row($accountId) ?? $this->notFound('Panel üyesi');
        $count = Sessions::revokeAccount($accountId, 'admin_revoked');
        $this->audit($request, 'member.sessions_revoked', 'panel_member', $accountId, $in['reason'], ['sessions' => $count]);

        return Respond::data(['revoked' => $count]);
    }
}
