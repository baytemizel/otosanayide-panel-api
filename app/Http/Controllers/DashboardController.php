<?php

namespace App\Http\Controllers;

use App\Domain\Admin\AdminOverview;
use App\Panel\Http\Respond;
use App\Panel\Stats\Stats;
use App\Panel\Support\Clock;
use App\Panel\WorkItems\WorkItems;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Genel bakis, istatistikler ve operasyon gorunumu. */
final class DashboardController extends PanelController
{
    public function dashboard(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return Respond::data([
            'generatedAt' => Clock::iso(Clock::db()),
            'sourceQueues' => Stats::queueCounts(),
            'workItems' => WorkItems::summary($m->accountId),
            'approvalsPending' => DB::table('panel_approvals')->where('status', 'pending')->where('expires_at', '>', Clock::db())->count(),
            'supportGrantsActive' => DB::table('panel_support_grants')->whereNull('revoked_at')->where('expires_at', '>', Clock::db())->count(),
            'catalogue' => DB::table('businesses')->selectRaw('count(*) as total, sum(published = 1) as published')->first(),
        ]);
    }

    /** Ana API web yonetiminin ozetiyle ayni sayimlar (AdminOverview::queues). */
    public function overview(Request $request): JsonResponse
    {
        return Respond::data(AdminOverview::queues());
    }

    public function catalogue(Request $request): JsonResponse
    {
        return Respond::data(Stats::catalogue());
    }

    public function accounts(Request $request): JsonResponse
    {
        return Respond::data(Stats::accounts());
    }

    public function snapshots(Request $request): JsonResponse
    {
        $f = $this->query($request, ['metric' => ['required', 'string', 'max:64'], 'dimension' => ['nullable', 'string', 'max:120'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $q = DB::table('panel_stat_snapshots')->where('metric', $f['metric'])->orderBy('snapshot_date')->orderBy('dimension');
        if (isset($f['dimension'])) {
            $q->where('dimension', $f['dimension']);
        }
        $q->where('snapshot_date', '>=', $f['from'] ?? Clock::now()->subDays(90)->toDateString());
        if (isset($f['to'])) {
            $q->where('snapshot_date', '<=', $f['to']);
        }

        return Respond::data($q->limit(5000)->get(['snapshot_date', 'dimension', 'value'])->map(static fn (\stdClass $r): array => ['date' => (string) $r->snapshot_date, 'dimension' => $r->dimension, 'value' => (int) $r->value])->all());
    }

    // Operasyon --------------------------------------------------------------------------------

    public function scheduledRuns(Request $request): JsonResponse
    {
        $rows = DB::table('scheduled_runs')->orderBy('job')->get(['job', 'state', 'version', 'updated_at_ms', 'lease_until_ms', 'completed_pages', 'completed_sweeps', 'last_counts', 'recovered_by']);

        return Respond::data($rows->map(static fn (\stdClass $r): array => [
            'job' => $r->job, 'state' => $r->state, 'version' => (int) $r->version, 'updatedAt' => Clock::msIso($r->updated_at_ms), 'leaseUntil' => Clock::msIso($r->lease_until_ms),
            'completedPages' => (int) $r->completed_pages, 'completedSweeps' => (int) $r->completed_sweeps, 'lastCounts' => $r->last_counts === null ? null : json_decode((string) $r->last_counts, true), 'recoveredBy' => $r->recovered_by,
        ])->all());
    }

    public function queues(Request $request): JsonResponse
    {
        return Respond::data([
            'jobs' => DB::table('jobs')->select('queue', DB::raw('count(*) as total'), DB::raw('sum(reserved_at is not null) as reserved'))->groupBy('queue')->get()->map(static fn (\stdClass $r): array => ['queue' => $r->queue, 'total' => (int) $r->total, 'reserved' => (int) $r->reserved])->all(),
            'failedJobs' => DB::table('failed_jobs')->count(),
        ]);
    }

    /** Yuk (payload) dondurulmez: kisisel veri tasiyabilir. Yalniz is adi ve istisna ilk satiri. */
    public function failedJobs(Request $request): JsonResponse
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(100)->get(['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        return Respond::data($rows->map(static fn (\stdClass $r): array => [
            'uuid' => $r->uuid, 'connection' => $r->connection, 'queue' => $r->queue,
            'job' => (string) (json_decode((string) $r->payload, true)['displayName'] ?? 'unknown'),
            'exception' => mb_substr(strtok((string) $r->exception, "\n") ?: '', 0, 300),
            'failedAt' => Clock::iso($r->failed_at),
        ])->all());
    }

    public function retryFailedJob(Request $request, string $uuid): JsonResponse
    {
        $in = $this->input($request, ['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if (! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            $this->notFound('Başarısız iş');
        }
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        $this->audit($request, 'ops.failed_job_retried', 'failed_job', $uuid, $in['reason']);

        return Respond::data(['retried' => true]);
    }

    public function forgetFailedJob(Request $request, string $uuid): JsonResponse
    {
        $in = $this->input($request, ['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        if (! DB::table('failed_jobs')->where('uuid', $uuid)->exists()) {
            $this->notFound('Başarısız iş');
        }
        Artisan::call('queue:forget', ['id' => $uuid]);
        $this->audit($request, 'ops.failed_job_forgotten', 'failed_job', $uuid, $in['reason']);

        return Respond::data(['forgotten' => true]);
    }
}
