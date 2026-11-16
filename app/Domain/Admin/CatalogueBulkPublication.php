<?php

namespace App\Domain\Admin;

use App\Domain\Catalogue\BusinessReader;
use App\Domain\Catalogue\SearchProjection;
use App\Domain\Catalogue\Taxonomy;
use App\Domain\Content\Ids;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * K200/K201: iceri aktarilmis, dokunulmamis taslaklari toplu yayimlar. Kaynakta yoktur; kullanici karariyla eklendi.
 *
 * Yazdigi son durum, yoneticinin taslak kaydi + yayin islemiyle aynidir (CatalogueAdmin::saveDraft + publication):
 * profil ve yayin surumu 1, aktivasyon surumu +2, koordinat verified, sahip ozellikleri kapali, iki gecmis kaydi,
 * arama projeksiyonu. Kategori ve uzmanlik adaylari kategori, koordinat kaniti isletme noktasi kabul edilir.
 *
 * Gorunurluk kapisi BusinessReader::indexDocument'tir: aramada gorunmeyecek kayit yayimlanmaz, taslak kalir.
 * Kaynakta eksik olan alan uydurulmaz. Yonetici akisindan farki: telefonu bos kayit da yayimlanir
 * (halka acik okuyucu bos telefonu kabul eder; yonetici formu etmez).
 */
final class CatalogueBulkPublication
{
    public const ACTOR = 'system-bulk-publication';

    /** Sabit kimlikler: ayni kayit ikinci kez yazilamaz; siralamada yayin kaydi taslak kaydindan sonra gelir. */
    public const DRAFT_HISTORY_ID = 'bulk-draft';

    public const PUBLICATION_HISTORY_ID = 'bulk-publication';

    public const DEFAULT_REASON = 'K200: kullanıcı onaylı katalog verisinin toplu yayını';

    public const CHECK_REFERENCE = 'K200 kullanıcı kararı: içe aktarılan katalog verisi onaylıdır (docs/decision-log.md).';

    private const INSERT_ROWS = 1000;

    /**
     * Tek kaydin yayin plani ya da atlama nedeni.
     *
     * @param  array<string,mixed>  $record  BusinessReader::records() kaydi (mevcut taslak)
     * @param  list<mixed>  $candidates  kategori, ardindan uzmanlik adaylari (kaynak sirasi)
     * @return array{categories:list<string>,latitude:float,longitude:float}|string
     */
    public static function plan(string $id, array $record, array $candidates, mixed $evidence, mixed $evidenceValid): array|string
    {
        $categories = [];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && ! in_array($candidate, $categories, true) && Taxonomy::category($candidate) !== null) {
                $categories[] = $candidate;
            }
        }
        $categories = array_slice($categories, 0, 12);
        if ($categories === []) {
            return 'no_category';
        }
        $lat = is_array($evidence) ? ($evidence['latitude'] ?? null) : null;
        $lng = is_array($evidence) ? ($evidence['longitude'] ?? null) : null;
        if ($evidenceValid !== true || ! (is_int($lat) || is_float($lat)) || ! (is_int($lng) || is_float($lng)) || ! is_finite((float) $lat) || ! is_finite((float) $lng)) {
            return 'no_coordinate';
        }
        $next = ['categories' => $categories, 'latitude' => (float) $lat, 'longitude' => (float) $lng, 'coordinateStatus' => 'verified', 'published' => true] + $record;
        if (BusinessReader::indexDocument($id, $next) !== null) {
            return ['categories' => $categories, 'latitude' => (float) $lat, 'longitude' => (float) $lng];
        }

        return BusinessReader::publicBusiness($id, $next) === null ? 'invalid_profile' : 'coordinate_out_of_bounds';
    }

    /**
     * Bir grubu planlar; $apply ise yazar. Yazarken cagiran tek transaction acmis olmalidir.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $excludeFlags
     * @return array{published:int,skipped:array<string,int>}
     */
    public static function chunk(array $ids, array $excludeFlags, string $reason, bool $apply): array
    {
        $skipped = [];
        $skip = static function (string $why) use (&$skipped): void {
            $skipped[$why] = ($skipped[$why] ?? 0) + 1;
        };
        $query = DB::table('businesses')->whereIn('id', $ids);
        $rows = ($apply ? $query->lockForUpdate() : $query)->get(['id', 'published', 'sample', 'ownership', 'owner_uid', 'import_source_id', 'profile_version', 'publication_version'])->keyBy('id');
        $eligible = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row === null || (bool) $row->published) {
                continue; // Silinmis ya da bu arada yayimlanmis: aday degil.
            }
            if ((bool) $row->sample || $row->ownership !== 'unclaimed' || $row->owner_uid !== null || $row->import_source_id === null || (int) $row->profile_version !== 0 || (int) $row->publication_version !== 0) {
                $skip('not_untouched_draft');

                continue;
            }
            $eligible[$id] = (string) $row->import_source_id;
        }
        if ($eligible === []) {
            return ['published' => 0, 'skipped' => $skipped];
        }
        $records = BusinessReader::records(array_keys($eligible));
        $sources = DB::table('catalogue_sources')->whereIn('id', array_values($eligible))->get(['id', 'business_id', 'extra'])->keyBy('id');
        $values = DB::table('catalogue_source_values')->whereIn('source_id', array_values($eligible))->orderBy('position')->get(['source_id', 'kind', 'value'])->groupBy('source_id');
        $plans = [];
        foreach ($eligible as $id => $sourceId) {
            $source = $sources->get($sourceId);
            $record = $records[$id] ?? null;
            if ($source === null || $source->business_id !== $id || $record === null || $record['categories'] !== [] || $record['services'] !== [] || $record['brands'] !== []) {
                $skip('not_untouched_draft');

                continue;
            }
            $own = collect($values->get($sourceId) ?? []);
            $list = static fn (string $kind): array => $own->where('kind', $kind)->pluck('value')->values()->all();
            if ($excludeFlags !== [] && array_intersect($list('flag'), $excludeFlags) !== []) {
                $skip('excluded_flag');

                continue;
            }
            $extra = $source->extra === null ? null : json_decode((string) $source->extra, true);
            $extra = is_array($extra) ? $extra : [];
            $plan = self::plan($id, $record, [...$list('category'), ...$list('specialty')], $extra['coordinateEvidence'] ?? null, $extra['coordinateEvidenceValid'] ?? null);
            if (is_string($plan)) {
                $skip($plan);

                continue;
            }
            $plans[$id] = $plan;
        }
        if ($apply && $plans !== []) {
            self::write($plans, $reason);
        }

        return ['published' => count($plans), 'skipped' => $skipped];
    }

    /** @param array<string,array{categories:list<string>,latitude:float,longitude:float}> $plans */
    private static function write(array $plans, string $reason): void
    {
        $ids = array_map('strval', array_keys($plans));
        $values = [];
        foreach ($plans as $id => $plan) {
            foreach ($plan['categories'] as $position => $category) {
                $values[] = ['business_id' => (string) $id, 'kind' => 'category', 'position' => $position, 'value' => $category];
            }
        }
        foreach (array_chunk($values, self::INSERT_ROWS) as $part) {
            DB::table('business_values')->insert($part);
        }

        // Kayit basina farkli koordinat tek ifadede yazilir. updated_at kolonu ON UPDATE ile kendiliginden yenilenir.
        $case = implode(' ', array_fill(0, count($ids), 'WHEN ? THEN ?'));
        $in = implode(', ', array_fill(0, count($ids), '?'));
        $bindings = [];
        foreach (['latitude', 'longitude'] as $column) {
            foreach ($plans as $id => $plan) {
                $bindings[] = (string) $id;
                $bindings[] = $plan[$column];
            }
        }
        $affected = DB::update(
            "UPDATE businesses SET latitude = CASE id {$case} END, longitude = CASE id {$case} END, coordinate_status = 'verified', published = 1,
             profile_version = 1, publication_version = 1, activation_version = activation_version + 2, owner_ready = 0, feature_quotes = 0, feature_appointments = 0
             WHERE published = 0 AND profile_version = 0 AND publication_version = 0 AND id IN ({$in})",
            [...$bindings, ...$ids]
        );
        if ($affected !== count($ids)) {
            throw new RuntimeException('Bulk publication conflict: a record changed during the batch');
        }

        $checks = array_fill_keys(['rights', 'identity', 'location', 'duplicates'], ['checked' => true, 'reference' => self::CHECK_REFERENCE]);
        $history = [];
        foreach ($ids as $id) {
            $history[] = ['business_id' => $id, 'id' => self::DRAFT_HISTORY_ID, 'kind' => 'catalogue_draft', 'details' => Ids::stringify(['reason' => $reason, 'actorId' => self::ACTOR, 'profileVersion' => 1])];
            $history[] = ['business_id' => $id, 'id' => self::PUBLICATION_HISTORY_ID, 'kind' => 'catalogue_publication', 'details' => Ids::stringify(['action' => 'publish', 'reason' => $reason, 'checks' => $checks, 'actorId' => self::ACTOR, 'version' => 1, 'profileVersion' => 1])];
        }
        foreach (array_chunk($history, self::INSERT_ROWS) as $part) {
            DB::table('business_history')->insert($part);
        }

        SearchProjection::syncMany($ids);
        if (DB::table('business_search')->whereIn('business_id', $ids)->count() !== count($ids)) {
            throw new RuntimeException('Bulk publication conflict: search projection is incomplete');
        }
    }
}
