<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Accounts\AccountAdmin;
use App\Panel\Approvals\Approvals;
use App\Panel\Auth\Sessions;
use App\Panel\Http\Paging;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use App\Panel\Members\MemberAdmin;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use App\Panel\Support\Secret;
use App\Panel\Support\SupportAccess;
use App\Support\Tx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/accounts: platform hesaplari. Kisisel kullanim verisi (garaj, talepler, bildirimler, favoriler)
 * yalniz etkin destek erisimiyle ve her okuma denetime yazilarak gorulur.
 */
final class AccountController extends PanelController
{
    public function index(Request $request): JsonResponse
    {
        $f = $this->query($request, [
            'q' => ['nullable', 'string', 'max:254'], 'status' => ['nullable', 'in:active,disabled,erasing'],
            'verified' => ['nullable', 'in:true,false'], 'admin' => ['nullable', 'in:true,false'], 'panelMember' => ['nullable', 'in:true,false'],
            'createdFrom' => ['nullable', 'date'], 'createdTo' => ['nullable', 'date'],
        ]);
        // Panel uyeligi ayri veritabaninda: uye kimlikleri (az sayida) once okunur, filtre ve isaret buradan gelir.
        $memberIds = MemberAdmin::accountIds();
        $q = DB::table('auth_accounts as a')
            ->select(['a.id', 'a.email', 'a.email_verified_at', 'a.status', 'a.is_admin', 'a.is_brand_finance', 'a.is_account_erasure_operator', 'a.created_at', 'a.version'])
            ->orderByDesc('a.created_at');
        if (isset($f['q'])) {
            $term = trim($f['q']);
            preg_match('/^[A-Za-z0-9_-]{20,128}$/D', $term) === 1 && ! str_contains($term, '@')
                ? $q->where('a.id', $term)
                : $q->where('a.email', 'like', addcslashes(Secret::normalizeEmail($term), '%_\\').'%');
        }
        if (isset($f['status'])) {
            $q->where('a.status', $f['status']);
        }
        if (isset($f['verified'])) {
            $f['verified'] === 'true' ? $q->whereNotNull('a.email_verified_at') : $q->whereNull('a.email_verified_at');
        }
        if (isset($f['admin'])) {
            $q->where('a.is_admin', $f['admin'] === 'true');
        }
        if (isset($f['panelMember'])) {
            $f['panelMember'] === 'true' ? $q->whereIn('a.id', $memberIds) : $q->whereNotIn('a.id', $memberIds);
        }
        if (isset($f['createdFrom'])) {
            $q->where('a.created_at', '>=', Clock::db(Clock::parse($f['createdFrom'])));
        }
        if (isset($f['createdTo'])) {
            $q->where('a.created_at', '<', Clock::db(Clock::parse($f['createdTo'])));
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);
        $members = array_flip($memberIds);
        foreach ($rows as $row) {
            $row->member_id = isset($members[$row->id]) ? $row->id : null;
        }

        return Respond::page(array_map(AccountAdmin::summary(...), $rows), $paging, $total);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $a = DB::table('auth_accounts')->where('id', $id)->first() ?? $this->notFound('Hesap');
        $member = PanelDb::table('panel_members')->where('account_id', $id)->first(['account_id', 'status']);
        $a->member_id = $member?->account_id;
        $a->member_status = $member?->status;
        $memberships = DB::table('business_memberships as bm')->leftJoin('businesses as b', 'b.id', '=', 'bm.business_id')->where('bm.user_id', $id)
            ->get(['bm.business_id', 'b.name as business_name', 'bm.role', 'bm.active', 'bm.version', 'bm.created_at']);
        $fence = DB::table('account_fences')->where('owner_uid', $id)->first(['state', 'created_at_ms']);
        $privacy = DB::table('privacy_requests')->where('owner_uid', $id)->orderByDesc('created_at_ms')->limit(5)->get(['id', 'scope', 'status', 'version', 'created_at_ms', 'updated_at_ms']);
        $this->audit($request, 'account.viewed', 'account', $id);

        return Respond::data(AccountAdmin::summary($a) + [
            'passwordChangedAt' => Clock::iso($a->password_changed_at),
            'tokensValidAfter' => Clock::iso($a->tokens_valid_after),
            'panelMemberStatus' => $a->member_status,
            'platformMfaEnabled' => DB::table('auth_mfa_factors')->where('account_id', $id)->whereNotNull('verified_at')->whereNull('disabled_at')->exists(),
            'activeSessions' => DB::table('auth_sessions')->where('account_id', $id)->whereNull('revoked_at')->where('expires_at', '>', Clock::db())->count(),
            'fence' => $fence === null ? null : ['state' => $fence->state, 'createdAt' => Clock::msIso($fence->created_at_ms)],
            'counts' => [
                'reviews' => DB::table('reviews')->where('user_id', $id)->count(),
                'claims' => DB::table('claims')->where('user_id', $id)->count(),
                'requests' => DB::table('requests')->where('user_id', $id)->count(),
                'vehicles' => DB::table('user_vehicles')->where('user_id', $id)->count(),
                'favorites' => DB::table('user_favorites')->where('user_id', $id)->count(),
                'pushDevices' => DB::table('push_devices')->where('user_id', $id)->where('enabled', true)->count(),
            ],
            'memberships' => Shape::rows($memberships, [], ['active']),
            'privacyRequests' => Shape::rows($privacy),
        ]);
    }

    public function events(Request $request, string $id): JsonResponse
    {
        $q = DB::table('auth_events')->where('account_id', $id)->orderByDesc('id')->select(['id', 'event', 'occurred_at']);
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(Shape::rows($rows), $paging, $total);
    }

    public function sessions(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('auth_sessions')->where('account_id', $id)->orderByDesc('created_at')->limit(100)
            ->get(['id', 'family_id', 'second_factor', 'auth_time', 'created_at', 'last_used_at', 'expires_at', 'revoked_at']);

        return Respond::data(Shape::rows($rows));
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $q = DB::table('reviews as r')->leftJoin('businesses as b', 'b.id', '=', 'r.business_id')->where('r.user_id', $id)->orderByDesc('r.created_at')
            ->select(['r.id', 'r.business_id', 'b.name as business_name', 'r.rating', 'r.status', 'r.version', 'r.created_at', 'r.reviewed_at']);
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(Shape::rows($rows), $paging, $total);
    }

    public function claims(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('claims')->where('user_id', $id)->orderByDesc('created_at')->limit(100)->get(['id', 'business_id', 'name', 'city', 'status', 'created_at', 'reviewed_at']);

        return Respond::data(Shape::rows($rows));
    }

    public function disable(Request $request, string $id): JsonResponse
    {
        return $this->setStatus($request, $id, 'disabled');
    }

    public function enable(Request $request, string $id): JsonResponse
    {
        return $this->setStatus($request, $id, 'active');
    }

    private function setStatus(Request $request, string $id, string $status): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $m = $this->member($request);
        if ($id === $m->accountId) {
            throw new HttpError(422, 'Kendi hesabını değiştiremezsin.');
        }
        $isMember = PanelDb::table('panel_members')->where('account_id', $id)->exists();
        if ($isMember && ! $m->can('members.manage')) {
            // Panel uyesinin platform hesabini kapatmak panel erisimini de kapatir: uye yonetimi yetkisi gerekir.
            throw new HttpError(403, 'Panel üyesinin hesabı için üye yönetimi yetkisi gerekir.');
        }
        // Panel yazimi once (son super yonetici denetimi, panel oturumlarinin kapanmasi), ana veritabani yazimi son adim:
        // hesap degisikligi reddedilirse panel oturumlari da acik kalir.
        PanelDb::tx(function () use ($id, $status, $in, $isMember): void {
            if ($status === 'disabled') {
                if ($isMember) {
                    MemberAdmin::assertNotLastSuperAdmin($id);
                }
                Sessions::revokeAccount($id, 'account_disabled');
            }
            Tx::run(fn () => AccountAdmin::setStatus($id, $status, $in['version']));
        });
        $this->audit($request, $status === 'disabled' ? 'account.disabled' : 'account.enabled', 'account', $id, $in['reason']);

        return $this->show($request, $id);
    }

    public function revokeSessions(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, ['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if (! DB::table('auth_accounts')->where('id', $id)->exists()) {
            $this->notFound('Hesap');
        }
        $count = Tx::run(fn () => AccountAdmin::revokeSessions($id, 'panel_sessions_revoked'));
        $this->audit($request, 'account.sessions_revoked', 'account', $id, $in['reason'], ['sessions' => $count]);

        return Respond::data(['revoked' => $count]);
    }

    /** Platform yetkileri her zaman ikinci kontrolle degisir (202). */
    public function claimsChange(Request $request, string $id): JsonResponse
    {
        $m = $this->member($request);
        $in = $this->input($request, [
            'version' => ['required', 'integer', 'min:0'], 'admin' => ['required', 'boolean'], 'brandFinance' => ['required', 'boolean'],
            'accountErasureOperator' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $a = DB::table('auth_accounts')->where('id', $id)->first() ?? $this->notFound('Hesap');
        if ((int) $a->version !== $in['version']) {
            throw new HttpError(409, 'Hesap değişti. Yenileyip tekrar deneyin.');
        }
        $payload = ['accountId' => $id, 'admin' => $in['admin'], 'brandFinance' => $in['brandFinance'], 'accountErasureOperator' => $in['accountErasureOperator'], 'version' => $in['version']];
        $approval = Approvals::request($m, 'account.claims', $id, $payload, 'Platform yetkisi: '.$a->email.' → admin='.json_encode($in['admin']).', finans='.json_encode($in['brandFinance']).', silme='.json_encode($in['accountErasureOperator']), $in['reason']);
        $this->audit($request, 'approval.requested', 'panel_approval', $approval['id'], $in['reason'], ['action' => 'account.claims', 'accountId' => $id]);

        return Respond::pendingApproval($approval);
    }

    // --- Destek erisimi ------------------------------------------------------------------------

    public function grants(Request $request): JsonResponse
    {
        $m = $this->member($request);
        $f = $this->query($request, ['scope' => ['nullable', 'in:mine,all'], 'active' => ['nullable', 'in:true,false'], 'accountId' => ['nullable', 'string', 'max:128']]);
        $q = PanelDb::table('panel_support_grants')->orderByDesc('created_at');
        if (($f['scope'] ?? 'mine') === 'mine' || ! $m->can('support.manage')) {
            $q->where('member_id', $m->accountId);
        }
        if (isset($f['accountId'])) {
            $q->where('account_id', $f['accountId']);
        }
        if (($f['active'] ?? null) === 'true') {
            $q->whereNull('revoked_at')->where('expires_at', '>', Clock::db());
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(array_map(SupportAccess::view(...), $rows), $paging, $total);
    }

    public function grant(Request $request): JsonResponse
    {
        $in = $this->input($request, [
            'accountId' => ['required', 'string', 'max:128'], 'scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['string'],
            'minutes' => ['required', 'integer'], 'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        $grant = SupportAccess::grant($this->member($request), $in['accountId'], $in['scopes'], $in['minutes'], $in['reason']);
        $this->audit($request, 'support.granted', 'account', $in['accountId'], $in['reason'], ['grantId' => $grant['id'], 'scopes' => $in['scopes'], 'expiresAt' => $grant['expiresAt']]);

        return Respond::created($grant);
    }

    public function revokeGrant(Request $request, string $id): JsonResponse
    {
        $grant = SupportAccess::revoke($this->member($request), $id);
        $this->audit($request, 'support.revoked', 'account', $grant['accountId'], null, ['grantId' => $id]);

        return Respond::data($grant);
    }

    public function garage(Request $request, string $id): JsonResponse
    {
        $grant = SupportAccess::assert($this->member($request), $id, 'garage');
        $this->audit($request, 'support.data_viewed', 'account', $id, null, ['grantId' => $grant, 'scope' => 'garage']);

        return Respond::data([
            'vehicles' => Shape::rows(DB::table('user_vehicles')->where('user_id', $id)->orderBy('id')->get(), [], ['archived'], ['user_id']),
            'maintenance' => Shape::rows(DB::table('user_maintenance')->where('user_id', $id)->orderByDesc('date')->limit(100)->get(), ['attachment_ids'], [], ['user_id']),
            'reminders' => Shape::rows(DB::table('user_reminders')->where('user_id', $id)->orderBy('due_date')->limit(100)->get(), ['advance_days_list'], ['completed', 'channel_push', 'channel_email', 'channels_set', 'enabled'], ['user_id']),
            'backup' => Shape::row(DB::table('garage_backups')->where('user_id', $id)->first(['revision', 'updated_at_iso'])),
            'recycle' => Shape::row(DB::table('garage_recycle')->where('user_id', $id)->first(['state', 'revision', 'changed_at_iso', 'purge_after_iso'])),
        ]);
    }

    public function requests(Request $request, string $id): JsonResponse
    {
        $grant = SupportAccess::assert($this->member($request), $id, 'requests');
        $this->audit($request, 'support.data_viewed', 'account', $id, null, ['grantId' => $grant, 'scope' => 'requests']);
        $rows = DB::table('requests')->where('user_id', $id)->orderByDesc('created_at')->limit(100)->get();

        return Respond::data(Shape::rows($rows, ['offer_lines'], ['offer_may_change'], ['user_id']));
    }

    public function notifications(Request $request, string $id): JsonResponse
    {
        $grant = SupportAccess::assert($this->member($request), $id, 'notifications');
        $this->audit($request, 'support.data_viewed', 'account', $id, null, ['grantId' => $grant, 'scope' => 'notifications']);
        $rows = DB::table('notifications as n')->leftJoin('notification_reads as r', fn ($j) => $j->on('r.notification_id', '=', 'n.id')->where('r.user_id', '=', $id))
            ->where('n.audience_key', 'user:'.$id)->orderByDesc('n.created_at_ms')->limit(100)
            ->get(['n.id', 'n.kind', 'n.request_id', 'n.request_status', 'n.title', 'n.phase', 'n.due_date', 'n.created_at_ms', 'n.delivered_at_ms', 'n.email_queue_outcome', 'n.push_queue_outcome', 'r.read']);

        return Respond::data(Shape::rows($rows, [], ['read']));
    }

    public function favorites(Request $request, string $id): JsonResponse
    {
        $grant = SupportAccess::assert($this->member($request), $id, 'favorites');
        $this->audit($request, 'support.data_viewed', 'account', $id, null, ['grantId' => $grant, 'scope' => 'favorites']);

        return Respond::data([
            'favorites' => Shape::rows(DB::table('user_favorites as f')->leftJoin('businesses as b', 'b.id', '=', 'f.business_id')->where('f.user_id', $id)->orderByDesc('f.created_at')->limit(200)->get(['f.business_id', 'b.name as business_name', 'f.created_at'])),
            'lists' => Shape::rows(DB::table('favorite_lists')->where('user_id', $id)->orderByDesc('updated_at_ms')->get(['id', 'name', 'kind', 'version', 'created_at_ms', 'updated_at_ms'])),
        ]);
    }
}
