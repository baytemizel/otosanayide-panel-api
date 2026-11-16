<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Approvals\Approvals;
use App\Panel\Audit\Audit;
use App\Panel\Http\Paging;
use App\Panel\Http\Respond;
use App\Panel\Settings\Settings;
use App\Panel\Support\Clock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ikinci kontrol talepleri, denetim kaydi ve panel ayarlari. */
final class GovernanceController extends PanelController
{
    public function approvals(Request $request): JsonResponse
    {
        $f = $this->query($request, ['status' => ['nullable', 'in:pending,approved,rejected,cancelled,expired,failed'], 'action' => ['nullable', 'in:'.implode(',', array_keys(Approvals::ACTIONS))], 'mine' => ['nullable', 'in:true,false']]);
        $q = DB::table('panel_approvals')->orderByDesc('created_at');
        if (isset($f['status'])) {
            $q->where('status', $f['status']);
        }
        if (isset($f['action'])) {
            $q->where('action', $f['action']);
        }
        if (($f['mine'] ?? null) === 'true') {
            $q->where('requested_by', $this->member($request)->accountId);
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(array_map(Approvals::view(...), $rows), $paging, $total);
    }

    public function approval(Request $request, string $id): JsonResponse
    {
        $a = DB::table('panel_approvals')->where('id', $id)->first() ?? $this->notFound('Talep');

        return Respond::data(Approvals::view($a));
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, true);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        return $this->decide($request, $id, false);
    }

    private function decide(Request $request, string $id, bool $approve): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        try {
            $view = Approvals::decide($this->member($request), $id, $in['version'], $approve, $in['reason']);
        } catch (HttpError $e) {
            if (str_starts_with($e->getMessage(), 'Onaylanan işlem uygulanamadı')) {
                $this->audit($request, 'approval.failed', 'panel_approval', $id, $in['reason'], ['error' => $e->getMessage()]);
            }
            throw $e;
        }
        $this->audit($request, $approve ? 'approval.approved' : 'approval.rejected', 'panel_approval', $id, $in['reason'], ['action' => $view['action'], 'subjectId' => $view['subjectId'], 'requestedBy' => $view['requestedBy']]);

        return Respond::data($view);
    }

    public function cancelApproval(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $view = Approvals::cancel($this->member($request), $id, $in['version'], $in['reason']);
        $this->audit($request, 'approval.cancelled', 'panel_approval', $id, $in['reason']);

        return Respond::data($view);
    }

    public function auditLogs(Request $request): JsonResponse
    {
        $f = $this->query($request, [
            'actorId' => ['nullable', 'string', 'max:128'], 'action' => ['nullable', 'string', 'max:96'],
            'subjectType' => ['nullable', 'string', 'max:48'], 'subjectId' => ['nullable', 'string', 'max:191'],
            'outcome' => ['nullable', 'in:success,denied,failed'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
        $q = DB::table('panel_audit_logs')->orderByDesc('id');
        foreach (['actorId' => 'actor_id', 'subjectType' => 'subject_type', 'subjectId' => 'subject_id', 'outcome' => 'outcome'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (isset($f['action'])) {
            str_ends_with($f['action'], '.*') ? $q->where('action', 'like', addcslashes(substr($f['action'], 0, -1), '%_\\').'%') : $q->where('action', $f['action']);
        }
        if (isset($f['from'])) {
            $q->where('occurred_at', '>=', Clock::db(Clock::parse($f['from'])));
        }
        if (isset($f['to'])) {
            $q->where('occurred_at', '<', Clock::db(Clock::parse($f['to'])));
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);
        $this->audit($request, 'audit.viewed', null, null, null, array_filter($f, static fn ($v) => $v !== null) ?: null);

        return Respond::page(array_map(Audit::view(...), $rows), $paging, $total);
    }

    public function auditLog(Request $request, string $id): JsonResponse
    {
        $row = DB::table('panel_audit_logs')->where('id', (int) $id)->first() ?? $this->notFound('Denetim kaydı');

        return Respond::data(Audit::view($row));
    }

    public function settings(Request $request): JsonResponse
    {
        return Respond::data(Settings::all());
    }

    public function updateSetting(Request $request, string $key): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:0'], 'value' => ['present'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $value = $request->json('value');
        if (str_starts_with($key, 'approvals.') && array_key_exists($key, (array) config('panel.defaults'))) {
            // Ikinci kontrol kurallarinin kendisi tek kisiyle gevsetilemez.
            Settings::validate($key, $value);
            if (Settings::version($key) !== $in['version']) {
                throw new HttpError(409, 'Ayar değişti. Yenileyip tekrar deneyin.');
            }
            $approval = Approvals::request($this->member($request), 'setting.update', $key, ['key' => $key, 'value' => $value, 'version' => $in['version']], 'Ayar: '.$key.' = '.json_encode($value, JSON_UNESCAPED_UNICODE), $in['reason']);
            $this->audit($request, 'approval.requested', 'panel_approval', $approval['id'], $in['reason'], ['action' => 'setting.update', 'key' => $key]);

            return Respond::pendingApproval($approval);
        }
        $change = Settings::put($key, $value, $in['version'], $this->member($request)->accountId);
        $this->audit($request, 'setting.updated', 'panel_setting', $key, $in['reason'], $change);
        $all = collect(Settings::all())->firstWhere('key', $key);

        return Respond::data($all);
    }
}
