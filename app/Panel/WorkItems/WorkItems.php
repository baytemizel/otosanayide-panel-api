<?php

namespace App\Panel\WorkItems;

use App\Exceptions\HttpError;
use App\Panel\Auth\Member;
use App\Panel\Settings\Settings;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Support\Facades\DB;
use stdClass;

/** Panel is kuyrugu: kaynak kuyruklarin uzerinde sorumlu, oncelik, durum, son tarih ve not. */
final class WorkItems
{
    public const ACTIVE = ['open', 'in_progress', 'waiting'];

    /**
     * Kaynak tabloyu tarar: acik kayda is acar (kapali isi yeniden acar), kapanan kaydin isini kapatir.
     *
     * @return array{queue:string,source:int,created:int,reopened:int,closed:int,updated:int}
     */
    public static function sync(string $queue, bool $apply): array
    {
        $q = Queues::get($queue);
        $source = self::sourceRows($q);
        $existing = PanelDb::table('panel_work_items')->where('queue', $queue)
            ->where(static fn ($w) => $w->whereIn('status', self::ACTIVE)->orWhereIn('subject_id', array_keys($source)))
            ->get(['id', 'subject_id', 'status', 'source_state', 'version'])->keyBy('subject_id');
        $now = Clock::now();
        $sla = (array) (Settings::get('work_items.sla_hours') ?? []);
        $due = isset($sla[$queue]) ? Clock::db($now->addHours((int) $sla[$queue])) : null;
        $counts = ['queue' => $queue, 'source' => count($source), 'created' => 0, 'reopened' => 0, 'closed' => 0, 'updated' => 0];
        $insert = [];
        foreach ($source as $subject => $row) {
            /** @var stdClass|null $item */
            $item = $existing->get($subject);
            if ($item === null) {
                $counts['created']++;
                $insert[] = ['id' => Clock::uuid(), 'queue' => $queue, 'subject_id' => $subject, 'title' => $row['title'], 'source_state' => $row['state'], 'opened_at' => Clock::db($now), 'due_at' => $due, 'last_action' => 'opened', 'created_at' => Clock::db($now), 'updated_at' => Clock::db($now)];
            } elseif (! in_array($item->status, self::ACTIVE, true)) {
                $counts['reopened']++;
                if ($apply) {
                    self::transition($item, ['status' => 'open', 'assignee_id' => null, 'closed_at' => null, 'opened_at' => Clock::db($now), 'due_at' => $due, 'source_state' => $row['state'], 'title' => $row['title']], 'reopened', null);
                }
            } elseif ($item->source_state !== $row['state']) {
                $counts['updated']++;
                if ($apply) {
                    PanelDb::table('panel_work_items')->where('id', $item->id)->update(['source_state' => $row['state'], 'updated_at' => Clock::db($now)]);
                }
            }
        }
        foreach ($existing as $subject => $item) {
            if (in_array($item->status, self::ACTIVE, true) && ! isset($source[$subject])) {
                $counts['closed']++;
                if ($apply) {
                    self::transition($item, ['status' => 'done', 'closed_at' => Clock::db($now), 'source_state' => self::currentState($q, (string) $subject) ?? 'missing'], 'source_resolved', null);
                }
            }
        }
        if ($apply && $insert !== []) {
            foreach (array_chunk($insert, 500) as $chunk) {
                PanelDb::table('panel_work_items')->insertOrIgnore($chunk);
            }
        }

        return $counts;
    }

    /** @return array<string,array{state:string,title:string}> */
    private static function sourceRows(array $q): array
    {
        $query = DB::table($q['table'].' as s')->whereIn('s.'.$q['state'], $q['open']);
        $columns = ['s.'.$q['key'].' as subject', 's.'.$q['state'].' as state'];
        if ($q['business'] !== null) {
            $query->leftJoin('businesses as b', 'b.id', '=', 's.'.$q['business']);
            $columns[] = 'b.name as business_name';
        }
        $out = [];
        foreach ($query->limit(20000)->get($columns) as $r) {
            $name = isset($r->business_name) && is_string($r->business_name) ? ' · '.mb_substr($r->business_name, 0, 200) : '';
            $out[(string) $r->subject] = ['state' => (string) $r->state, 'title' => mb_substr($q['label'].$name, 0, 300)];
        }

        return $out;
    }

    private static function currentState(array $q, string $subject): ?string
    {
        $v = DB::table($q['table'])->where($q['key'], $subject)->value($q['state']);

        return $v === null ? null : (string) $v;
    }

    /** Panelden verilen karar sonrasi: kaynak hala aciksa durum guncellenir, kapandiysa is kapanir. */
    public static function refresh(string $queue, string $subjectId, string $actorId, string $action): void
    {
        $q = Queues::get($queue);
        $item = PanelDb::table('panel_work_items')->where('queue', $queue)->where('subject_id', $subjectId)->first();
        if ($item === null || ! in_array($item->status, self::ACTIVE, true)) {
            return;
        }
        $state = self::currentState($q, $subjectId);
        if ($state !== null && in_array($state, $q['open'], true)) {
            PanelDb::table('panel_work_items')->where('id', $item->id)->update(['source_state' => $state, 'last_action' => $action, 'last_actor_id' => $actorId, 'version' => (int) $item->version + 1, 'updated_at' => Clock::db()]);
            self::event($item->id, $action, $actorId, $item->status, $item->status, ['sourceState' => $state]);

            return;
        }
        self::transition($item, ['status' => 'done', 'closed_at' => Clock::db(), 'source_state' => $state ?? 'missing'], $action, $actorId);
    }

    /** @param array<string,mixed> $patch */
    private static function transition(stdClass $item, array $patch, string $action, ?string $actorId): void
    {
        PanelDb::table('panel_work_items')->where('id', $item->id)->update($patch + ['last_action' => $action, 'last_actor_id' => $actorId, 'version' => (int) $item->version + 1, 'updated_at' => Clock::db()]);
        self::event($item->id, $action, $actorId, $item->status, (string) ($patch['status'] ?? $item->status), null);
    }

    /** @param array<string,mixed>|null $details */
    private static function event(string $id, string $action, ?string $actorId, ?string $from, ?string $to, ?array $details): void
    {
        PanelDb::table('panel_work_item_events')->insert(['work_item_id' => $id, 'action' => $action, 'actor_id' => $actorId, 'from_status' => $from, 'to_status' => $to, 'details' => $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE), 'created_at' => Clock::db()]);
    }

    /**
     * Uye guncellemesi. work_items.manage: atama, oncelik, son tarih. work_items.work: yalniz kendisine atanmis
     * (veya sahipsiz) isin durumu ve notu.
     *
     * @param  array{version:int,status?:string,priority?:string,assigneeId?:?string,dueAt?:?string,note?:?string}  $in
     * @return array{before:array<string,mixed>,after:array<string,mixed>}
     */
    public static function update(Member $m, string $id, array $in): array
    {
        return PanelDb::tx(static function () use ($m, $id, $in): array {
            $item = PanelDb::table('panel_work_items')->where('id', $id)->lockForUpdate()->first();
            if ($item === null) {
                throw new HttpError(404, 'İş bulunamadı.');
            }
            if ((int) $item->version !== $in['version']) {
                throw new HttpError(409, 'İş değişti. Yenileyip tekrar deneyin.');
            }
            self::assertQueueAccess($m, $item);
            $manage = $m->can('work_items.manage');
            $managed = array_intersect(array_keys($in), ['priority', 'assigneeId', 'dueAt']);
            if (! $manage && ($managed !== [] || ($item->assignee_id !== null && $item->assignee_id !== $m->accountId))) {
                throw new HttpError(403, 'Bu işi yalnız atanmış kişi veya iş yöneticisi değiştirebilir.');
            }
            $patch = [];
            if (array_key_exists('assigneeId', $in)) {
                if ($in['assigneeId'] !== null && ! PanelDb::table('panel_members')->where('account_id', $in['assigneeId'])->where('status', 'active')->exists()) {
                    throw new HttpError(422, 'Atanan kişi etkin bir panel üyesi değil.');
                }
                $patch['assignee_id'] = $in['assigneeId'];
            }
            foreach (['status' => 'status', 'priority' => 'priority', 'note' => 'note'] as $k => $col) {
                if (array_key_exists($k, $in)) {
                    $patch[$col] = $in[$k];
                }
            }
            if (array_key_exists('dueAt', $in)) {
                $patch['due_at'] = $in['dueAt'] === null ? null : Clock::db(Clock::parse($in['dueAt']));
            }
            if (isset($patch['status'])) {
                $patch['closed_at'] = in_array($patch['status'], self::ACTIVE, true) ? null : Clock::db();
            }
            $before = self::view($item);
            self::transition($item, $patch, 'updated', $m->accountId);

            return ['before' => $before, 'after' => self::view(PanelDb::table('panel_work_items')->where('id', $id)->first())];
        });
    }

    /** @return array<string,mixed> */
    public static function claim(Member $m, string $id, int $version, bool $release): array
    {
        return PanelDb::tx(static function () use ($m, $id, $version, $release): array {
            $item = PanelDb::table('panel_work_items')->where('id', $id)->lockForUpdate()->first();
            if ($item === null) {
                throw new HttpError(404, 'İş bulunamadı.');
            }
            if ((int) $item->version !== $version) {
                throw new HttpError(409, 'İş değişti. Yenileyip tekrar deneyin.');
            }
            if (! in_array($item->status, self::ACTIVE, true)) {
                throw new HttpError(409, 'Kapanmış iş üstlenilemez.');
            }
            self::assertQueueAccess($m, $item);
            if ($release) {
                if ($item->assignee_id !== $m->accountId && ! $m->can('work_items.manage')) {
                    throw new HttpError(403, 'Yalnız kendi üstlendiğin işi bırakabilirsin.');
                }
                self::transition($item, ['assignee_id' => null, 'status' => 'open'], 'released', $m->accountId);
            } else {
                if ($item->assignee_id !== null && $item->assignee_id !== $m->accountId) {
                    throw new HttpError(409, 'İş başka birine atanmış.');
                }
                self::transition($item, ['assignee_id' => $m->accountId, 'status' => 'in_progress'], 'claimed', $m->accountId);
            }

            return self::view(PanelDb::table('panel_work_items')->where('id', $id)->first());
        });
    }

    /** Isi yalniz kaynak kuyrugun karar yetkisine sahip uye (veya is yoneticisi) degistirebilir. */
    private static function assertQueueAccess(Member $m, stdClass $item): void
    {
        $permission = Queues::all()[$item->queue]['permission'] ?? null;
        if (! $m->can('work_items.manage') && ($permission === null || ! $m->can($permission))) {
            throw new HttpError(403, 'Bu kuyruğun işlerine karar yetkin yok.');
        }
    }

    /** @return array<string,mixed> */
    public static function view(stdClass $r): array
    {
        $q = Queues::all()[$r->queue] ?? null;

        return [
            'id' => $r->id,
            'queue' => $r->queue,
            'queueLabel' => $q['label'] ?? $r->queue,
            'subjectType' => $q['subject'] ?? null,
            'subjectId' => $r->subject_id,
            'title' => $r->title,
            'priority' => $r->priority,
            'status' => $r->status,
            'assigneeId' => $r->assignee_id,
            'sourceState' => $r->source_state,
            'openedAt' => Clock::iso($r->opened_at),
            'dueAt' => Clock::iso($r->due_at),
            'overdue' => $r->due_at !== null && in_array($r->status, self::ACTIVE, true) && Clock::parse($r->due_at)?->isPast() === true,
            'closedAt' => Clock::iso($r->closed_at),
            'lastAction' => $r->last_action,
            'lastActorId' => $r->last_actor_id,
            'note' => $r->note,
            'version' => (int) $r->version,
            'updatedAt' => Clock::iso($r->updated_at),
        ];
    }

    /** @return array<string,mixed> */
    public static function summary(string $accountId): array
    {
        $now = Clock::db();
        $byQueue = PanelDb::table('panel_work_items')->whereIn('status', self::ACTIVE)
            ->selectRaw('queue, count(*) as total, sum(case when assignee_id is null then 1 else 0 end) as unassigned, sum(case when due_at is not null and due_at < ? then 1 else 0 end) as overdue', [$now])
            ->groupBy('queue')->get()->keyBy('queue');
        $queues = [];
        foreach (Queues::all() as $key => $q) {
            $row = $byQueue->get($key);
            $queues[] = ['queue' => $key, 'label' => $q['label'], 'active' => (int) ($row->total ?? 0), 'unassigned' => (int) ($row->unassigned ?? 0), 'overdue' => (int) ($row->overdue ?? 0)];
        }

        return [
            'queues' => $queues,
            'mine' => PanelDb::table('panel_work_items')->whereIn('status', self::ACTIVE)->where('assignee_id', $accountId)->count(),
        ];
    }
}
