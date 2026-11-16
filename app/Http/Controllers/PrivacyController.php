<?php

namespace App\Http\Controllers;

use App\Domain\Account\AccountFence;
use App\Domain\User\Privacy;
use App\Domain\User\PrivacyExports;
use App\Exceptions\HttpError;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use App\Panel\Support\Clock;
use App\Support\Js;
use App\Support\Tx;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * /api/v1/privacy (veri ve silme talepleri) ve /api/v1/notification-jobs. Ana API bayragi DATA_REQUESTS_ENABLED
 * panel ortaminda da ayni deger olmalidir; kapaliyken uclar 503 doner (ana API ile ayni).
 */
final class PrivacyController extends PanelController
{
    private function enabled(): void
    {
        if (! config('otosanayide.flags.data_requests')) {
            throw new HttpError(503, 'Veri talepleri henüz kullanıma açılmadı.');
        }
    }

    /** @param callable():JsonResponse $run */
    private function guard(callable $run): JsonResponse
    {
        try {
            return $run();
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'privacy_')) {
                throw new HttpError(409, 'Talep durumu değişti. Yeniden kontrol et.');
            }
            throw $e;
        }
    }

    public function requests(Request $request): JsonResponse
    {
        $this->enabled();
        $q = DomainInput::query($request, ['status', 'after']);
        $status = Z::enum(['submitted', 'reviewing', 'needs_confirmation'])->parse($q->status ?? 'submitted');
        $cursor = null;
        if (($q->after ?? '') !== '') {
            try {
                if (Js::length($q->after) > 600) {
                    throw new RuntimeException('long');
                }
                $cursor = Z::strictObject(['createdAtMs' => Z::number()->int()->min(0)->max(8640000000000000), 'ownerUid' => Privacy::uid(), 'requestId' => Z::string()->uuid()])
                    ->parse(json_decode($q->after, false, 16, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
        }

        return $this->guard(fn () => Respond::data(Privacy::queue($status, $cursor)));
    }

    public function requestAction(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        $m = $this->member($request);
        // Ana API kurali: yonetici karari yakin zamanli giris ister (panel icin TOTP adimi zamani).
        if (! Privacy::recentLogin(intdiv($m->mfaAt, 1000), Js::nowMs())) {
            throw new HttpError(428, 'Güvenlik için çıkış yapıp tekrar giriş yap. Sonra talebi yeniden kontrol et.');
        }
        $input = Privacy::adminAction()->parse(DomainInput::body($request, ['requestId' => $id]));

        return $this->guard(function () use ($request, $m, $input, $id): JsonResponse {
            $result = Privacy::changeAdmin($m->accountId, $input, Js::nowMs());
            $this->decided($request, 'privacy_requests', 'privacy_request', $id, 'privacy.'.$input['action'], $input['reason'] ?? null, ['ownerUid' => $input['ownerUid'], 'expectedVersion' => $input['expectedVersion']]);

            return Respond::data(['request' => $result, 'execution' => 'not_enabled']);
        });
    }

    public function exports(Request $request): JsonResponse
    {
        $this->enabled();
        $q = DomainInput::query($request, ['status', 'after']);
        $status = Z::enum(['submitted', 'in_progress'])->parse($q->status ?? 'submitted');
        $after = $q->after ?? null;
        if ($after !== null && preg_match('/^[0-9a-f-]{36}$/D', $after) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }

        return Respond::data(PrivacyExports::queue($status, $after));
    }

    public function exportDecision(Request $request, string $id): JsonResponse
    {
        $this->enabled();
        $m = $this->member($request);
        if (! Privacy::recentLogin(intdiv($m->mfaAt, 1000), Js::nowMs())) {
            throw new HttpError(428, 'Güvenlik için çıkış yapıp tekrar giriş yap. Sonra talebi yeniden kontrol et.');
        }
        $body = DomainInput::body($request, ['requestId' => $id]);
        $body->kind = 'export';
        $input = PrivacyExports::adminSchema()->parse($body);

        return $this->guard(function () use ($request, $m, $input, $id): JsonResponse {
            $result = PrivacyExports::decide($m->accountId, $input);
            $this->decided($request, 'privacy_exports', 'privacy_export', $id, 'privacy_export.'.$input['action'], $input['reason'] ?? null);

            return Respond::data(['request' => $result, 'preparation' => 'manual']);
        });
    }

    // Bildirim isleri ----------------------------------------------------------------------------

    public function notificationJobs(Request $request): JsonResponse
    {
        $q = DomainInput::query($request, ['state', 'after']);
        $state = Z::enum(['pending', 'processing', 'retry', 'failed', 'delivered', 'superseded'])->parse($q->state ?? 'failed');
        $after = $q->after ?? '';
        if ($after !== '' && preg_match('/^[a-zA-Z0-9_-]{1,180}$/D', $after) !== 1) {
            throw new HttpError(400, 'Geçersiz istek.');
        }
        $query = DB::table('notification_jobs')->where('state', $state)->orderBy('id')->limit(21);
        if ($after !== '') {
            $query->where('id', '>', $after);
        }
        $rows = $query->get();
        $page = $rows->take(20)->values();

        return Respond::data(['items' => Shape::rows($page), 'nextCursor' => $rows->count() > 20 ? $page->last()?->id : null]);
    }

    public function notificationJob(Request $request, string $id): JsonResponse
    {
        $job = DB::table('notification_jobs')->where('id', $id)->first() ?? $this->notFound('Bildirim işi');

        return Respond::data(Shape::row($job) + ['history' => Shape::rows(DB::table('notification_job_history')->where('job_id', $id)->orderBy('created_at_ms')->get(), [], [], ['job_id'])]);
    }

    /** Ana API AdminNotificationsController::update ile ayni kural. */
    public function retryNotification(Request $request, string $id): JsonResponse
    {
        $m = $this->member($request);
        $input = Z::strictObject(['id' => Z::string()->regex('/^[a-zA-Z0-9_-]{1,180}$/'), 'reason' => Z::string()->trim()->min(10)->max(1000)])->parse(DomainInput::body($request, ['id' => $id]));
        Tx::run(function () use ($m, $input): void {
            $j = DB::table('notification_jobs')->where('id', $input['id'])->lockForUpdate()->first();
            if ($j === null || $j->state !== 'failed') {
                throw new HttpError(409, 'Yalnızca başarısız iş yeniden kuyruğa alınabilir.');
            }
            AccountFence::assertWritable($m->accountId);
            if ($j->customer_id !== $m->accountId) {
                AccountFence::assertWritable($j->customer_id);
            }
            $now = Js::nowMs();
            DB::table('notification_jobs')->where('id', $input['id'])->update(['state' => 'pending', 'attempts' => 0, 'next_attempt_at_ms' => $now, 'lease_id' => null, 'lease_until_ms' => null, 'updated_at_ms' => $now]);
            DB::table('notification_job_history')->insert(['job_id' => $input['id'], 'id' => (string) Str::uuid(), 'action' => 'manual_retry', 'reason' => $input['reason'], 'actor_id' => $m->accountId, 'created_at_ms' => $now]);
        });
        $this->decided($request, 'notification_jobs', 'notification_job', $id, 'notification.retried', $input['reason']);

        return Respond::data(['ok' => true, 'retriedAt' => Clock::iso(Clock::db())]);
    }
}
