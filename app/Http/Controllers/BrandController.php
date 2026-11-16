<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdRules;
use App\Domain\Brand\BrandAcceptance;
use App\Domain\Brand\BrandAdmin;
use App\Domain\Brand\BrandCancellation;
use App\Domain\Brand\BrandInventory;
use App\Domain\Brand\BrandMedia;
use App\Domain\Brand\BrandOrders;
use App\Domain\Brand\BrandRefundReview;
use App\Domain\Brand\BrandRules;
use App\Domain\Brand\BrandServing;
use App\Domain\Payments\F7;
use App\Domain\Payments\PaymentGateway;
use App\Exceptions\HttpError;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Respond;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * /api/v1/brand/*: marka reklami satis ve finans akisi (ana API /api/admin/brand-* cekirdegi).
 * Bayraklar (BRAND_*_ENABLED) panel ortaminda ana API ile ayni olmalidir; kapaliyken yazim 503 doner.
 */
final class BrandController extends PanelController
{
    public function tariff(Request $request): JsonResponse
    {
        return Respond::data(BrandAdmin::tariff(BrandRules::market()->parse(DomainInput::query($request))));
    }

    public function saveTariff(Request $request): JsonResponse
    {
        $input = BrandRules::tariffUpdate()->parse(DomainInput::body($request));
        $result = BrandAdmin::saveTariff($this->member($request)->accountId, $input);
        $this->audit($request, 'brand_tariff.updated', 'brand_tariff', BrandRules::marketKey($input['value']['market']), $input['reason'], ['version' => $input['version'], 'value' => $input['value']]);

        return Respond::data($result);
    }

    public function saveCapacity(Request $request): JsonResponse
    {
        $input = BrandRules::capacityUpdate()->parse(DomainInput::body($request));
        $result = BrandAdmin::saveCapacity($this->member($request)->accountId, $input);
        $this->audit($request, 'brand_capacity.updated', 'brand_capacity', $input['placement'], $input['reason'], ['version' => $input['version'], 'enabled' => $input['enabled'], 'capacity' => $input['capacity']]);

        return Respond::data($result);
    }

    public function quote(Request $request): JsonResponse
    {
        return Respond::data(['quote' => BrandAdmin::quote(BrandRules::quoteInput()->parse(DomainInput::body($request)))]);
    }

    public function campaigns(Request $request): JsonResponse
    {
        $input = Z::strictObject(['after' => F7::uuid()->optional()])->parse(DomainInput::query($request));

        return Respond::data(BrandAdmin::campaigns($this->member($request)->accountId, $input['after'] ?? null, BrandMedia::enabled()));
    }

    /** save (yeni kampanya icin yeni uuid), submit, pause, remove_image, approve, reject. */
    public function campaignAction(Request $request, string $id): JsonResponse
    {
        $input = BrandRules::action()->parse(DomainInput::body($request, ['id' => $id]));
        $item = BrandAdmin::act($this->member($request)->accountId, $input);
        $reason = $input['note'] ?? null;
        if (in_array($input['action'], ['approve', 'reject'], true)) {
            $this->decided($request, 'brand_campaigns', 'brand_campaign', $id, 'brand_campaign.'.$input['action'], $reason, ['version' => $input['version'], 'rightsChecked' => $input['rightsChecked']]);
        } else {
            $this->audit($request, 'brand_campaign.'.$input['action'], 'brand_campaign', $id, $reason, ['version' => $input['version']]);
        }

        return Respond::data(['item' => $item]);
    }

    public function metrics(Request $request, string $id): JsonResponse
    {
        $campaignId = AdRules::metricQuery()->parse((object) ['campaignId' => $id])['campaignId'];
        try {
            $report = BrandServing::report($campaignId);
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'missing') {
                $this->notFound();
            }
            throw $e;
        }

        return Respond::data($report + ['enabled' => F7::flag('brand_metrics'), 'campaignId' => $campaignId]);
    }

    public function reservation(Request $request): JsonResponse
    {
        $a = BrandRules::campaignLookup()->parse(DomainInput::query($request));

        return Respond::data(['enabled' => F7::flag('brand_reservations')] + BrandInventory::read($a['campaignId']));
    }

    public function reserve(Request $request): JsonResponse
    {
        $a = BrandRules::reservationInput()->parse(DomainInput::body($request));
        if (! F7::flag('brand_reservations')) {
            throw new HttpError(503, 'Marka rezervasyonları henüz açılmadı.');
        }
        try {
            $reservation = BrandInventory::reserve($this->member($request)->accountId, (object) $a);
        } catch (Throwable) {
            throw new HttpError(409, 'Kapasite, onay veya tarife değişti. Bilgileri yenile.');
        }
        $this->audit($request, 'brand_reservation.held', 'brand_campaign', $a['campaignId'], null, ['requestId' => $a['requestId']]);

        return Respond::data(['reservation' => $reservation]);
    }

    public function release(Request $request, string $campaignId): JsonResponse
    {
        $a = BrandRules::campaignLookup()->parse((object) ['campaignId' => $campaignId]);
        BrandInventory::releaseTx($a['campaignId'], $this->member($request)->accountId);
        $this->audit($request, 'brand_reservation.released', 'brand_campaign', $campaignId);

        return Respond::data(['released' => true]);
    }

    public function order(Request $request): JsonResponse
    {
        $a = BrandRules::orderInput()->parse(DomainInput::query($request));
        $enabled = F7::flag('brand_orders');
        $ready = $enabled && PaymentGateway::configured() !== null;
        $state = $ready ? F7::row('brand_payment_states', 'reservation_id', $a['reservationId']) : null;

        return Respond::data(['enabled' => $enabled, 'checkoutEnabled' => $ready && ! in_array($state?->status, ['verified', 'needs_review'], true), 'order' => BrandOrders::read((object) $a)]);
    }

    public function prepareOrder(Request $request): JsonResponse
    {
        $a = BrandRules::orderInput()->parse(DomainInput::body($request));
        if (! F7::flag('brand_orders')) {
            throw new HttpError(503, 'Marka reklamı ödeme hazırlığı henüz açılmadı.');
        }
        try {
            $order = BrandOrders::create($this->member($request)->accountId, (object) $a);
        } catch (Throwable) {
            throw new HttpError(409, 'Rezervasyon veya fiyat değişti. Bilgileri yenile.');
        }
        $this->audit($request, 'brand_order.prepared', 'brand_reservation', $a['reservationId']);

        return Respond::data(['order' => $order]);
    }

    public function acceptance(Request $request): JsonResponse
    {
        $input = BrandRules::orderInput()->parse(DomainInput::query($request));

        return Respond::data(BrandAcceptance::read((object) $input) + ['enabled' => F7::flag('brand_acceptance')]);
    }

    public function accept(Request $request): JsonResponse
    {
        $input = BrandRules::orderInput()->parse(DomainInput::body($request));
        if (! F7::flag('brand_acceptance')) {
            throw new HttpError(503, 'Marka reklamı kabul işlemleri henüz açılmadı.');
        }
        try {
            BrandAcceptance::accept($this->member($request)->accountId, (object) $input);
        } catch (Throwable) {
            throw new HttpError(409, 'Ödeme veya rezervasyon koşulları değişti. Kabul durumunu yenile.');
        }
        $this->audit($request, 'brand_acceptance.accepted', 'brand_reservation', $input['reservationId']);

        return Respond::data(BrandAcceptance::read((object) $input) + ['enabled' => true]);
    }

    public function cancellation(Request $request): JsonResponse
    {
        $id = BrandCancellation::lookup()->parse(DomainInput::query($request))['reservationId'];

        return Respond::data(BrandCancellation::read($this->member($request)->accountId, (object) ['reservationId' => $id]) + ['enabled' => F7::flag('brand_cancellations')]);
    }

    public function requestCancellation(Request $request): JsonResponse
    {
        return $this->cancellationWrite($request, true);
    }

    public function decideCancellation(Request $request): JsonResponse
    {
        return $this->cancellationWrite($request, false);
    }

    private function cancellationWrite(Request $request, bool $create): JsonResponse
    {
        $uid = $this->member($request)->accountId;
        $input = ($create ? BrandCancellation::request() : BrandCancellation::decision())->parse(DomainInput::body($request));
        if (! F7::flag('brand_cancellations')) {
            throw new HttpError(503, 'Yeni iptal işlemleri kapalı. Geçmiş kayıtlar okunabilir.');
        }
        try {
            $create ? BrandCancellation::requestCancellation($uid, (object) $input) : BrandCancellation::decide($uid, (object) $input);
        } catch (Throwable) {
            throw new HttpError(409, 'İptal koşulları değişti. Durumu yenile ve tekrar kontrol et.');
        }
        if ($create) {
            $this->audit($request, 'brand_cancellation.requested', 'brand_cancellation', $input['reservationId'], $input['reason']);
        } else {
            $this->decided($request, 'brand_cancellations', 'brand_cancellation', $input['reservationId'], 'brand_cancellation.'.$input['decision'], $input['note'], ['version' => $input['version']]);
        }

        return Respond::data(BrandCancellation::read($uid, (object) ['reservationId' => $input['reservationId']]) + ['enabled' => F7::flag('brand_cancellations')]);
    }

    public function cancellationQueue(Request $request): JsonResponse
    {
        $q = BrandCancellation::queueQuery()->parse(DomainInput::query($request));

        return Respond::data(BrandCancellation::queue($this->member($request)->accountId, (object) array_filter($q, static fn (mixed $v): bool => $v !== null)) + ['enabled' => F7::flag('brand_cancellations')]);
    }

    public function cancellationHistory(Request $request): JsonResponse
    {
        $q = BrandCancellation::historyQuery()->parse(DomainInput::query($request));
        try {
            return Respond::data(BrandCancellation::history($this->member($request)->accountId, (object) $q));
        } catch (Throwable) {
            throw new HttpError(409, 'Geçmiş kaydı doğrulanamadı. Durumu yenile.');
        }
    }

    public function refundReview(Request $request): JsonResponse
    {
        $query = BrandRefundReview::query()->parse(DomainInput::query($request));
        try {
            return Respond::data(BrandRefundReview::read($query) + ['enabled' => F7::flag('brand_refund_review')]);
        } catch (Throwable) {
            throw new HttpError(409, 'Uzlaştırma kaydı doğrulanamadı. Geçmişi yenile.');
        }
    }

    public function decideRefundReview(Request $request): JsonResponse
    {
        $input = BrandRefundReview::action()->parse(DomainInput::body($request));
        if (! F7::flag('brand_refund_review')) {
            throw new HttpError(503, 'Yeni uzlaştırma kararları kapalı. Geçmiş okunabilir.');
        }
        try {
            BrandRefundReview::decide($this->member($request)->accountId, $input);
        } catch (Throwable) {
            throw new HttpError(409, 'Uzlaştırma kaydı değişti. Güncel durumu yükle.');
        }
        $this->audit($request, 'brand_refund_review.'.$input['status'], 'brand_reservation', $input['reservationId'], $input['note'], ['eventId' => $input['eventId'], 'acknowledged' => $input['acknowledged']]);
        try {
            return Respond::data(BrandRefundReview::read(['reservationId' => $input['reservationId'], 'eventId' => $input['eventId']]) + ['enabled' => true]);
        } catch (Throwable) {
            throw new HttpError(409, 'Uzlaştırma kaydı doğrulanamadı. Geçmişi yenile.');
        }
    }
}
