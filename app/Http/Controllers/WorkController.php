<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Http\Paging;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use App\Panel\WorkItems\Queues;
use App\Panel\WorkItems\WorkItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Is kuyrugu ve ic notlar. */
final class WorkController extends PanelController
{
    public const SUBJECT_TYPES = ['account', 'business', 'review', 'reply', 'review_case', 'review_photo', 'business_photo', 'claim', 'business_change', 'business_report', 'feature_activation', 'privacy_request', 'privacy_export', 'ad_campaign', 'brand_campaign', 'ad_payment_case', 'brand_payment_case', 'brand_cancellation', 'notification_job', 'work_item', 'panel_member'];

    public function index(Request $request): JsonResponse
    {
        $m = $this->member($request);
        $f = $this->query($request, [
            'queue' => ['nullable', 'in:'.implode(',', array_keys(Queues::all()))],
            'status' => ['nullable', 'in:active,open,in_progress,waiting,done,dismissed,all'],
            'assignee' => ['nullable', 'string', 'max:128'], 'priority' => ['nullable', 'in:low,normal,high,urgent'],
            'overdue' => ['nullable', 'in:true,false'], 'subjectId' => ['nullable', 'string', 'max:191'],
        ]);
        $q = PanelDb::table('panel_work_items');
        $status = $f['status'] ?? 'active';
        if ($status === 'active') {
            $q->whereIn('status', WorkItems::ACTIVE);
        } elseif ($status !== 'all') {
            $q->where('status', $status);
        }
        foreach (['queue' => 'queue', 'priority' => 'priority', 'subjectId' => 'subject_id'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        if (isset($f['assignee'])) {
            match ($f['assignee']) {
                'me' => $q->where('assignee_id', $m->accountId),
                'unassigned' => $q->whereNull('assignee_id'),
                default => $q->where('assignee_id', $f['assignee']),
            };
        }
        if (($f['overdue'] ?? null) === 'true') {
            $q->whereNotNull('due_at')->where('due_at', '<', Clock::db());
        }
        $q->orderByRaw("field(priority, 'urgent', 'high', 'normal', 'low')")->orderBy('due_at')->orderBy('opened_at');
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(array_map(WorkItems::view(...), $rows), $paging, $total);
    }

    public function summary(Request $request): JsonResponse
    {
        return Respond::data(WorkItems::summary($this->member($request)->accountId));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $item = PanelDb::table('panel_work_items')->where('id', $id)->first() ?? $this->notFound('İş');
        $events = PanelDb::table('panel_work_item_events')->where('work_item_id', $id)->orderBy('id')->limit(200)->get();

        return Respond::data(WorkItems::view($item) + ['events' => Shape::rows($events, ['details'], [], ['work_item_id'])]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, [
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:open,in_progress,waiting,done,dismissed'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
            'assigneeId' => ['sometimes', 'nullable', 'string', 'max:128'],
            'dueAt' => ['sometimes', 'nullable', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
        $change = WorkItems::update($this->member($request), $id, $in);
        $this->audit($request, 'work_item.updated', 'work_item', $id, null, ['changes' => array_keys(array_diff_key($in, ['version' => 1]))]);

        return Respond::data($change['after']);
    }

    public function claim(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1']]);
        $view = WorkItems::claim($this->member($request), $id, $in['version'], false);
        $this->audit($request, 'work_item.claimed', 'work_item', $id);

        return Respond::data($view);
    }

    public function release(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1']]);
        $view = WorkItems::claim($this->member($request), $id, $in['version'], true);
        $this->audit($request, 'work_item.released', 'work_item', $id);

        return Respond::data($view);
    }

    public function notes(Request $request): JsonResponse
    {
        $f = $this->query($request, ['subjectType' => ['required', 'in:'.implode(',', self::SUBJECT_TYPES)], 'subjectId' => ['required', 'string', 'max:191']]);
        $q = PanelDb::table('panel_notes')->where('subject_type', $f['subjectType'])->where('subject_id', $f['subjectId'])->whereNull('deleted_at')->orderByDesc('created_at');
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(array_map(fn (\stdClass $n): array => $this->note($n), $rows), $paging, $total);
    }

    public function addNote(Request $request): JsonResponse
    {
        $in = $this->input($request, ['subjectType' => ['required', 'in:'.implode(',', self::SUBJECT_TYPES)], 'subjectId' => ['required', 'string', 'max:191'], 'body' => ['required', 'string', 'min:2', 'max:4000']]);
        $id = Clock::uuid();
        PanelDb::table('panel_notes')->insert(['id' => $id, 'subject_type' => $in['subjectType'], 'subject_id' => $in['subjectId'], 'author_id' => $this->member($request)->accountId, 'body' => $in['body'], 'created_at' => Clock::db()]);
        $this->audit($request, 'note.created', $in['subjectType'], $in['subjectId'], null, ['noteId' => $id]);

        return Respond::created($this->note((object) PanelDb::table('panel_notes')->where('id', $id)->first()));
    }

    public function deleteNote(Request $request, string $id): JsonResponse
    {
        $m = $this->member($request);
        $n = PanelDb::table('panel_notes')->where('id', $id)->whereNull('deleted_at')->first() ?? $this->notFound('Not');
        if ($n->author_id !== $m->accountId && ! $m->isSuperAdmin()) {
            throw new HttpError(403, 'Yalnız kendi notunu silebilirsin.');
        }
        PanelDb::table('panel_notes')->where('id', $id)->update(['deleted_at' => Clock::db(), 'deleted_by' => $m->accountId]);
        $this->audit($request, 'note.deleted', $n->subject_type, $n->subject_id, null, ['noteId' => $id]);

        return Respond::data(['deleted' => true]);
    }

    /** @return array<string,mixed> */
    private function note(\stdClass $n): array
    {
        return ['id' => $n->id, 'subjectType' => $n->subject_type, 'subjectId' => $n->subject_id, 'authorId' => $n->author_id, 'body' => $n->body, 'createdAt' => Clock::iso($n->created_at)];
    }
}
