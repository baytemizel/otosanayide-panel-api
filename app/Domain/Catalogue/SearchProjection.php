<?php

namespace App\Domain\Catalogue;

use App\Domain\Content\MediaCdn;
use Illuminate\Support\Facades\DB;

/** Isletme yazimiyla AYNI transaction'da cagrilir; ayri esitleme isi yoktur. */
final class SearchProjection
{
    public static function sync(string $businessId): void
    {
        // K205: kamu DTO'su degisti; fotograf CDN yansisi islem tamamlaninca kuyrukta guncellenir.
        MediaCdn::queue($businessId);
        $record = BusinessReader::records([$businessId])[$businessId] ?? null;
        $doc = $record === null ? null : BusinessReader::indexDocument($businessId, $record);
        if ($doc === null) {
            DB::table('business_search')->where('business_id', $businessId)->delete();

            return;
        }
        // Boylam/enlem karismasin diye WKT eksen sirasi acik verilir (SRID 4326 = enlem-boylam).
        DB::statement(
            "INSERT INTO business_search (business_id, name, city, district, district_norm, card, search_text, latitude, longitude, location, fingerprint)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), ?)
             ON DUPLICATE KEY UPDATE name=VALUES(name), city=VALUES(city), district=VALUES(district), district_norm=VALUES(district_norm), card=VALUES(card), search_text=VALUES(search_text), latitude=VALUES(latitude), longitude=VALUES(longitude), location=VALUES(location), fingerprint=VALUES(fingerprint)",
            [$businessId, $doc['name'], $doc['city'], $doc['districtDisplay'], $doc['district'], $doc['card'] ? 1 : 0, $doc['text'], $doc['lat'], $doc['lng'], sprintf('POINT(%.17g %.17g)', $doc['lat'], $doc['lng']), $doc['fingerprint']]
        );
        DB::table('business_search_facets')->where('business_id', $businessId)->delete();
        $rows = [];
        foreach (['category' => $doc['categories'], 'service' => $doc['services'], 'brand' => $doc['brands']] as $facet => $values) {
            foreach (array_unique($values) as $value) {
                $rows[] = ['business_id' => $businessId, 'facet' => $facet, 'value' => $value];
            }
        }
        if ($rows !== []) {
            DB::table('business_search_facets')->insert($rows);
        }
        ReviewSummary::refresh($businessId); // web-08: yeniden olusan satir sifirdan baslamasin.
    }

    /** MySQL hazir ifade siniri 65.535 yer tutucudur; satir basina 14 kolon yazilir. */
    private const MANY_ROWS = 500;

    /**
     * sync() ile ayni son durumu grup halinde yazar (K201). Uzak veritabaninda kayit basina ~8 istek yerine
     * grup basina ~8 istek gider. Upsert yerine sil + ekle kullanir; yorum ozeti ayni ifadede yazilir.
     * Cagiran transaction acmis olmalidir.
     *
     * @param  list<string>  $ids
     */
    public static function syncMany(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        MediaCdn::queueMany($ids);
        $records = BusinessReader::records($ids);
        $docs = [];
        foreach ($ids as $id) {
            $doc = isset($records[$id]) ? BusinessReader::indexDocument($id, $records[$id]) : null;
            if ($doc !== null) {
                $docs[$id] = $doc;
            }
        }
        DB::table('business_search_facets')->whereIn('business_id', $ids)->delete();
        DB::table('business_search')->whereIn('business_id', $ids)->delete();
        if ($docs === []) {
            return;
        }
        $reviews = DB::table('public_reviews')->whereIn('business_id', array_keys($docs))->groupBy('business_id')->selectRaw('business_id, COUNT(*) as n, COALESCE(SUM(rating), 0) as s')->get()->keyBy('business_id');
        // Boylam/enlem karismasin diye WKT eksen sirasi acik verilir (SRID 4326 = enlem-boylam).
        $row = "(?, ?, ?, ?, ?, ?, ?, ?, ?, ST_GeomFromText(?, 4326, 'axis-order=lat-long'), ?, ?, ?, ?)";
        $facets = [];
        foreach (array_chunk($docs, self::MANY_ROWS, true) as $part) {
            $bindings = [];
            foreach ($part as $id => $doc) {
                $count = (int) ($reviews->get($id)->n ?? 0);
                $sum = (int) ($reviews->get($id)->s ?? 0);
                array_push($bindings, (string) $id, $doc['name'], $doc['city'], $doc['districtDisplay'], $doc['district'], $doc['card'] ? 1 : 0, $doc['text'], $doc['lat'], $doc['lng'], sprintf('POINT(%.17g %.17g)', $doc['lat'], $doc['lng']), $doc['fingerprint'], $count, $sum, ReviewSummary::score($count, $sum));
                foreach (['category' => $doc['categories'], 'service' => $doc['services'], 'brand' => $doc['brands']] as $facet => $values) {
                    foreach (array_unique($values) as $value) {
                        $facets[] = ['business_id' => (string) $id, 'facet' => $facet, 'value' => $value];
                    }
                }
            }
            DB::insert('INSERT INTO business_search (business_id, name, city, district, district_norm, card, search_text, latitude, longitude, location, fingerprint, review_count, review_sum, review_score) VALUES '.implode(', ', array_fill(0, count($part), $row)), $bindings);
        }
        foreach (array_chunk($facets, 1000) as $part) {
            DB::table('business_search_facets')->insert($part);
        }
    }
}
