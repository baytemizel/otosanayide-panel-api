<?php

namespace App\Panel\Stats;

use App\Panel\Support\Clock;
use App\Panel\WorkItems\Queues;
use Illuminate\Support\Facades\DB;

/** Yalniz sayim: kisisel veri dondurmez. */
final class Stats
{
    /** @return array<string,int> Kaynak kuyruklarda acik kayit sayisi. */
    public static function queueCounts(): array
    {
        $out = [];
        foreach (Queues::all() as $key => $q) {
            $out[$key] = DB::table($q['table'])->whereIn($q['state'], $q['open'])->count();
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public static function catalogue(): array
    {
        $totals = DB::table('businesses')->selectRaw('count(*) as total, sum(published = 1) as published, sum(published = 0) as drafts, sum(sample = 1) as samples, sum(coordinate_status = ?) as verified_coordinates, sum(owner_uid is not null) as owned', ['verified'])->first();
        $ownership = DB::table('businesses')->select('ownership', DB::raw('count(*) as c'))->groupBy('ownership')->pluck('c', 'ownership');
        $cities = DB::table('businesses')->selectRaw('city, count(*) as total, sum(published = 1) as published')->groupBy('city')->orderBy('city')->get();
        $categories = DB::table('business_search_facets')->where('facet', 'category')->select('value', DB::raw('count(*) as c'))->groupBy('value')->orderByDesc('c')->pluck('c', 'value');

        return [
            'total' => (int) ($totals->total ?? 0),
            'published' => (int) ($totals->published ?? 0),
            'drafts' => (int) ($totals->drafts ?? 0),
            'samples' => (int) ($totals->samples ?? 0),
            'verifiedCoordinates' => (int) ($totals->verified_coordinates ?? 0),
            'owned' => (int) ($totals->owned ?? 0),
            'searchable' => DB::table('business_search')->count(),
            'ownership' => $ownership->map(static fn ($v): int => (int) $v)->all(),
            'cities' => $cities->map(static fn (\stdClass $c): array => ['city' => $c->city, 'total' => (int) $c->total, 'published' => (int) $c->published])->values()->all(),
            'searchableByCategory' => $categories->map(static fn ($v): int => (int) $v)->all(),
        ];
    }

    /** @return array<string,mixed> */
    public static function accounts(): array
    {
        $since = Clock::db(Clock::now()->subDays(7));
        $t = DB::table('auth_accounts')->selectRaw('count(*) as total, sum(email_verified_at is not null) as verified, sum(status = ?) as disabled, sum(status = ?) as erasing, sum(created_at >= ?) as last7, sum(is_admin = 1) as admins', ['disabled', 'erasing', $since])->first();

        return [
            'total' => (int) ($t->total ?? 0),
            'verified' => (int) ($t->verified ?? 0),
            'disabled' => (int) ($t->disabled ?? 0),
            'erasing' => (int) ($t->erasing ?? 0),
            'createdLast7Days' => (int) ($t->last7 ?? 0),
            'platformAdmins' => (int) ($t->admins ?? 0),
            'businessMembers' => DB::table('business_memberships')->where('active', true)->distinct()->count('user_id'),
            'panelMembers' => DB::table('panel_members')->where('status', 'active')->count(),
        ];
    }

    /** @return list<array{metric:string,dimension:string,value:int}> */
    public static function snapshotRows(): array
    {
        $rows = [];
        $c = self::catalogue();
        foreach (['total', 'published', 'drafts', 'verifiedCoordinates', 'owned', 'searchable'] as $k) {
            $rows[] = ['metric' => 'businesses.'.$k, 'dimension' => '', 'value' => (int) $c[$k]];
        }
        foreach ($c['cities'] as $city) {
            $rows[] = ['metric' => 'businesses.published_by_city', 'dimension' => (string) $city['city'], 'value' => $city['published']];
        }
        foreach (self::accounts() as $k => $v) {
            $rows[] = ['metric' => 'accounts.'.$k, 'dimension' => '', 'value' => (int) $v];
        }
        foreach (self::queueCounts() as $k => $v) {
            $rows[] = ['metric' => 'queue.open', 'dimension' => $k, 'value' => $v];
        }
        $rows[] = ['metric' => 'reviews.published', 'dimension' => '', 'value' => DB::table('public_reviews')->count()];

        return $rows;
    }
}
