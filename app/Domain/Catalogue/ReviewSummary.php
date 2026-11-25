<?php

namespace App\Domain\Catalogue;

use Illuminate\Support\Facades\DB;

/**
 * web-05/web-08: yayimlanmis yorum ozeti (public_reviews) arama projeksiyonunda tutulur.
 * Yorum yayimlama/geri cekme ile AYNI transaction'da cagrilir; projeksiyon satiri yoksa (arama disi) bir sey yazilmaz.
 * Siralama puani Bayes agirlikli ortalamadir: (C*m + toplam) / (C + n). C ve m ONERIDIR, kullanici kabulu bekler.
 */
final class ReviewSummary
{
    /** Onsel agirlik (sanal yorum sayisi). */
    public const PRIOR_WEIGHT = 5;

    /** Onsel ortalama (1-5). */
    public const PRIOR_MEAN = 3.5;

    /** @return array{count:int,sum:int} */
    public static function compute(string $businessId): array
    {
        $row = DB::table('public_reviews')->where('business_id', $businessId)->selectRaw('COUNT(*) as n, COALESCE(SUM(rating), 0) as s')->first();

        return ['count' => (int) ($row->n ?? 0), 'sum' => (int) ($row->s ?? 0)];
    }

    public static function score(int $count, int $sum): float
    {
        return round((self::PRIOR_WEIGHT * self::PRIOR_MEAN + $sum) / (self::PRIOR_WEIGHT + $count), 6);
    }

    /** @return array{count:int,average:?float} istemciye giden bicim; average bir ondalik. */
    public static function view(int $count, int $sum): array
    {
        return ['count' => $count, 'average' => $count === 0 ? null : round($sum / $count, 1)];
    }

    /** v2-03 (W04/A04): yon puanlari => public_reviews kolonu. */
    public const ASPECTS = ['quality' => 'quality', 'payment' => 'payment', 'cleanliness' => 'cleanliness', 'communication' => 'communication', 'priceTransparency' => 'price_transparency'];

    /**
     * v2-03: yayimlanmis yorumlarda her yonun ortalamasi (bir ondalik) ve puanlayan yorum sayisi. Yalniz tekil isletme ucu.
     *
     * @return array<string,array{average:?float,count:int}>
     */
    public static function aspects(string $businessId): array
    {
        $select = [];
        foreach (self::ASPECTS as $key => $column) {
            $select[] = "COUNT({$column}) as {$key}_n, COALESCE(SUM({$column}), 0) as {$key}_s";
        }
        $row = DB::table('public_reviews')->where('business_id', $businessId)->selectRaw(implode(', ', $select))->first();
        $out = [];
        foreach (self::ASPECTS as $key => $_) {
            $n = (int) ($row->{$key.'_n'} ?? 0);
            $out[$key] = ['average' => $n === 0 ? null : round((int) ($row->{$key.'_s'} ?? 0) / $n, 1), 'count' => $n];
        }

        return $out;
    }

    public static function refresh(string $businessId): void
    {
        $s = self::compute($businessId);
        DB::table('business_search')->where('business_id', $businessId)->update(['review_count' => $s['count'], 'review_sum' => $s['sum'], 'review_score' => self::score($s['count'], $s['sum'])]);
    }
}
