<?php

namespace App\Http\Controllers;

use App\Domain\Ads\AdRules;
use App\Domain\Ads\AdTariffs;
use App\Domain\Ads\CampaignRules;
use App\Domain\Ads\Campaigns;
use App\Domain\Brand\BrandReview;
use App\Domain\Payments\PaymentCases;
use App\Exceptions\HttpError;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Respond;
use App\Support\Query;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/** /api/v1/ads ve /api/v1/finance: isletme reklam kampanyasi, tarifesi ve odeme incelemeleri (F7 cekirdegi). */
final class AdsController extends PanelController
{
    public function campaigns(Request $request): JsonResponse
    {
        $q = DomainInput::query($request, ['status', 'after']);
        $status = $q->status ?? 'pending';
        if (! in_array($status, ['draft', 'pending', 'approved', 'rejected', 'cancelled', 'scheduled'], true)) {
            throw new HttpError(400, 'Geçersiz durum.');
        }
        $params = ['queue' => 'true', 'status' => $status];
        foreach (['after'] as $k) {
            if (($q->{$k} ?? '') !== '') {
                $params[$k] = $q->{$k};
            }
        }

        return Respond::data(Campaigns::list($this->member($request)->actor(), Query::parse(http_build_query($params, '', '&', PHP_QUERY_RFC3986))));
    }

    public function decideCampaign(Request $request, string $id): JsonResponse
    {
        $input = CampaignRules::action()->parse(DomainInput::body($request, ['id' => $id]));
        if (! in_array($input['action'], ['approve', 'reject'], true)) {
            throw new HttpError(400, 'Panelden yalnız onay veya ret kararı verilir.');
        }
        $item = Campaigns::act($this->member($request)->actor(), $input);
        $this->decided($request, 'ad_campaigns', 'ad_campaign', $id, 'ad_campaign.'.$input['action'], $input['reason'], ['businessId' => $input['businessId'], 'version' => $input['version']]);

        return Respond::data(['item' => $item]);
    }

    public function tariff(Request $request): JsonResponse
    {
        return Respond::data(AdTariffs::read(AdRules::market()->parse(DomainInput::query($request))));
    }

    public function saveTariff(Request $request): JsonResponse
    {
        $input = AdRules::tariffUpdate()->parse(DomainInput::body($request));
        $result = AdTariffs::update($this->member($request)->accountId, $input);
        $this->audit($request, 'ad_tariff.updated', 'ad_tariff', AdRules::marketId($input['value']), $input['reason'], ['version' => $input['version'], 'value' => $input['value']]);

        return Respond::data($result);
    }

    public function paymentCases(Request $request): JsonResponse
    {
        $input = PaymentCases::query()->parse(DomainInput::query($request));

        return Respond::data(PaymentCases::list($input['after'] ?? null));
    }

    public function decidePaymentCase(Request $request, string $id): JsonResponse
    {
        $input = PaymentCases::action()->parse(DomainInput::body($request, ['id' => $id]));
        $item = PaymentCases::decide($this->member($request)->accountId, $input);
        $this->decided($request, 'ad_payment_cases', 'ad_payment_case', $id, 'ad_payment_case.'.$input['status'], $input['note'], ['version' => $input['version']]);

        return Respond::data(['item' => $item]);
    }

    public function brandPaymentReview(Request $request): JsonResponse
    {
        return Respond::data(BrandReview::read(BrandReview::query()->parse(DomainInput::query($request))));
    }

    public function decideBrandPaymentReview(Request $request, string $id): JsonResponse
    {
        $body = DomainInput::body($request, ['id' => $id]);
        try {
            $decision = BrandReview::decide($this->member($request)->accountId, $body);
        } catch (Throwable) {
            throw new HttpError(409, 'Kayıt değişti veya karar geçersiz. Kuyruğu yenile.');
        }
        $this->decided($request, 'brand_payment_cases', 'brand_payment_case', $id, 'brand_payment_case.'.((string) ($body->status ?? 'decided')), is_string($body->note ?? null) ? $body->note : null);

        return Respond::data(['decision' => $decision]);
    }
}
