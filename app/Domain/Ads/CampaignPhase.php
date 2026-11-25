<?php

namespace App\Domain\Ads;

use App\Domain\Payments\F7;
use Illuminate\Support\Facades\DB;

/**
 * v2-05 (Figma R04 "Zamanlandı / Tamamlandı / Durduruldu"): sahip gorunumunde turetilmis kampanya evresi.
 * Kaynak durum (status) degismez; evre durum + odenmis rezervasyon + Istanbul takvimiyle hesaplanir. Salt okunur.
 */
final class CampaignPhase
{
    public const PHASES = ['draft', 'in_review', 'rejected', 'awaiting_payment', 'scheduled', 'running', 'completed', 'cancelled'];

    /** campaignDates son gunu (startDate + days - 1). */
    public static function endDate(string $startDate, int $days): string
    {
        $dates = CampaignRules::dates($startDate, $days);

        return $dates[count($dates) - 1];
    }

    /** @param array<string,mixed> $view CampaignRules::view ciktisi */
    public static function of(array $view, bool $paid, int $now): string
    {
        $status = $view['status'];
        if ($status === 'draft' || $status === 'rejected' || $status === 'cancelled') {
            return $status;
        }
        if ($status === 'pending') {
            return 'in_review';
        }
        // approved: odeme sonuclanana kadar stok tutulmaz; settle ayni islemde status=scheduled yazar.
        if ($status === 'approved' && ! $paid) {
            return 'awaiting_payment';
        }
        $start = F7::trStart($view['startDate']) ?? 0;
        $end = (F7::trStart(self::endDate($view['startDate'], $view['days'])) ?? 0) + F7::DAY;

        return $now < $start ? 'scheduled' : ($now < $end ? 'running' : 'completed');
    }

    /**
     * Odenmis rezervasyonu olan kampanya kimlikleri (kilitsiz okuma; liste gorunumu icindir).
     *
     * @param  list<string>  $ids
     * @return array<string,true>
     */
    public static function paid(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = DB::table('ad_campaign_reservations as a')->join('ad_reservations as r', 'r.id', '=', 'a.reservation_id')
            ->whereIn('a.campaign_id', $ids)->whereColumn('r.campaign_id', 'a.campaign_id')->where('r.status', 'paid')->pluck('a.campaign_id')->all();

        return array_fill_keys(array_map('strval', $rows), true);
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public static function decorate(array $items, ?int $now = null): array
    {
        $now ??= F7::nowMs();
        $paid = self::paid(array_map(static fn (array $c): string => $c['id'], $items));

        return array_map(static fn (array $c): array => $c + ['endDate' => self::endDate($c['startDate'], $c['days']), 'phase' => self::of($c, isset($paid[$c['id']]), $now)], $items);
    }
}
