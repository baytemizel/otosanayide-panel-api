<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Audit\Audit;
use App\Panel\Auth\Member;
use App\Panel\Http\DomainInput;
use App\Panel\WorkItems\WorkItems;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class PanelController
{
    protected function member(Request $request): Member
    {
        return Member::from($request);
    }

    /**
     * Panelin kendi uclari: govde JSON olmali; Laravel dogrulayicisi 422 + alanlar dondurur.
     *
     * @param  array<string,mixed>  $rules
     * @return array<string,mixed>
     */
    protected function input(Request $request, array $rules): array
    {
        DomainInput::requireJson($request);

        $data = Validator::make($request->json()->all(), $rules)->validate();
        // 'integer' kurali "3" metnini de kabul eder; surum karsilastirmalari tam sayi ister.
        foreach (['version', 'minutes'] as $key) {
            if (isset($data[$key]) && is_numeric($data[$key])) {
                $data[$key] = (int) $data[$key];
            }
        }

        return $data;
    }

    /** @param array<string,mixed> $rules @return array<string,mixed> */
    protected function query(Request $request, array $rules): array
    {
        return Validator::make($request->query(), $rules)->validate();
    }

    /** @param array<string,mixed>|null $details */
    protected function audit(Request $request, string $action, ?string $subjectType, ?string $subjectId, ?string $reason = null, ?array $details = null): void
    {
        Audit::record($request, $action, $subjectType, $subjectId, $reason, $details);
    }

    /**
     * Cekirdek karari sonrasi: denetim + ilgili isin guncellenmesi.
     *
     * @param  array<string,mixed>  $details
     */
    protected function decided(Request $request, string $queue, string $subjectType, string $subjectId, string $action, ?string $reason, array $details = []): void
    {
        $member = $this->member($request);
        Audit::record($request, $action, $subjectType, $subjectId, $reason, $details === [] ? null : $details);
        try {
            WorkItems::refresh($queue, $subjectId, $member->accountId, $action);
        } catch (\Throwable) {
            // Is kuyrugu yardimcidir; karar zaten yazildi. Bir sonraki panel:work-items-sync uzlastirir.
        }
    }

    protected function notFound(string $what = 'Kayıt'): never
    {
        throw new HttpError(404, $what.' bulunamadı.');
    }
}
