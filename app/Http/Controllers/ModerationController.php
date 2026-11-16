<?php

namespace App\Http\Controllers;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\AiAnalysis;
use App\Domain\Admin\BusinessChangeReview;
use App\Domain\Admin\CaseModeration;
use App\Domain\Admin\Moderator;
use App\Domain\Admin\PhotoModeration;
use App\Domain\Admin\ReplyModeration;
use App\Domain\Admin\ReviewModeration;
use App\Domain\Content\Activations;
use App\Domain\Content\BusinessReports;
use App\Domain\Content\Schemas;
use App\Exceptions\HttpError;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use App\Support\Z;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/moderation/*: ana API'nin /api/admin moderasyon cekirdegi (ayni kurallar, surumler, bariyerler,
 * kendi icerigini/yetkilisi oldugu isletmeyi inceleyememe). Listeler 20'lik imlecli sayfadir (`after`).
 */
final class ModerationController extends PanelController
{
    private const AUTOMATIC_BOOL = ['moderation_hold', 'local_flagged', 'local_obfuscation', 'ai_flagged'];

    /** @return array{0:string,1:?string} */
    private function statusAfter(Request $request, array $statuses, string $default, bool $uuid = false): array
    {
        $q = DomainInput::query($request, ['status', 'after']);
        $status = $q->status ?? $default;
        if (! in_array($status, $statuses, true)) {
            throw new HttpError(400, 'Geçersiz inceleme durumu.');
        }
        $after = ($q->after ?? '') !== '' ? ($uuid ? Schemas::uuid()->parse($q->after) : Schemas::id()->parse($q->after)) : null;

        return [$status, $after];
    }

    // Yorumlar -----------------------------------------------------------------------------------

    public function reviews(Request $request): JsonResponse
    {
        [$status, $after] = $this->statusAfter($request, ['pending', 'approved'], 'pending');

        return Respond::data(ReviewModeration::page($status, $after));
    }

    public function review(Request $request, string $id): JsonResponse
    {
        $r = DB::table('reviews')->where('id', $id)->first() ?? $this->notFound('Yorum');

        return Respond::data(Shape::row($r, ['local_signals', 'ai_categories'], self::AUTOMATIC_BOOL) + [
            'automatic' => Moderator::automatic((string) $r->text, $r),
            'published' => DB::table('public_reviews')->where('review_id', $id)->exists(),
            'reply' => Shape::row(DB::table('review_replies')->where('review_id', $id)->first(['review_id', 'user_id', 'text', 'status', 'version', 'reason', 'reviewed_by', 'reviewed_at', 'created_at'])),
            'photos' => Shape::rows(DB::table('review_photos')->where('review_id', $id)->orderBy('created_at_ms')->get(['id', 'status', 'version', 'submission_version', 'width', 'height', 'bytes', 'created_at_ms']), [], []),
            'cases' => Shape::rows(DB::table('review_cases')->where('review_id', $id)->orderByDesc('created_at')->get(['id', 'kind', 'status', 'decision', 'user_id', 'created_at', 'closed_at'])),
            'history' => Shape::rows(DB::table('review_history')->where('review_id', $id)->orderBy('created_at')->get(), ['details'], [], ['review_id']),
        ]);
    }

    public function decideReview(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::reviewDecision()->parse(DomainInput::body($request, ['id' => $id]));
        ReviewModeration::decide($this->member($request)->actor(), $input);
        $this->decided($request, 'reviews', 'review', $id, 'review.'.$input['decision'], $input['reason'], ['version' => $input['version']]);

        return Respond::data(['ok' => true]);
    }

    public function analyze(Request $request, string $id): JsonResponse
    {
        if (! Moderator::aiEnabled()) {
            throw new HttpError(503, 'AI kontrolü henüz yapılandırılmadı. Manuel inceleme gerekli.');
        }
        $input = AdminSchemas::analyze()->parse(DomainInput::body($request, ['id' => $id]));
        $result = AiAnalysis::run($this->member($request)->actor(), $input);
        $this->audit($request, 'review.ai_analyzed', $input['kind'] === 'reply' ? 'reply' : 'review', $id);

        return Respond::data($result);
    }

    // Isletme yanitlari --------------------------------------------------------------------------

    public function replies(Request $request): JsonResponse
    {
        [$status, $after] = $this->statusAfter($request, ['pending', 'approved'], 'pending');

        return Respond::data(ReplyModeration::page($status, $after));
    }

    public function decideReply(Request $request, string $reviewId): JsonResponse
    {
        $input = AdminSchemas::reviewDecision()->parse(DomainInput::body($request, ['id' => $reviewId]));
        ReplyModeration::decide($this->member($request)->actor(), $input);
        $this->decided($request, 'replies', 'reply', $reviewId, 'reply.'.$input['decision'], $input['reason'], ['version' => $input['version']]);

        return Respond::data(['ok' => true]);
    }

    // Yorum bildirimleri ve itirazlar ------------------------------------------------------------

    public function cases(Request $request): JsonResponse
    {
        $q = DomainInput::query($request, ['after']);

        return Respond::data(CaseModeration::page(($q->after ?? '') !== '' ? Schemas::id()->parse($q->after) : null));
    }

    public function decideCase(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::caseDecision()->parse(DomainInput::body($request, ['id' => $id]));
        CaseModeration::decide($this->member($request)->actor(), $input);
        $this->decided($request, 'review_cases', 'review_case', $id, 'review_case.'.$input['decision'], $input['reason']);

        return Respond::data(['ok' => true]);
    }

    // Fotograflar --------------------------------------------------------------------------------

    public function reviewPhotos(Request $request): JsonResponse
    {
        [$status, $after] = $this->statusAfter($request, ['pending', 'approved'], 'pending');

        return Respond::data(PhotoModeration::reviewPage($status, $after));
    }

    public function decideReviewPhoto(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::reviewPhotoDecision()->parse(DomainInput::body($request, ['id' => $id]));
        PhotoModeration::decideReview($this->member($request)->actor(), $input);
        $this->decided($request, 'review_photos', 'review_photo', $id, 'review_photo.'.$input['decision'], $input['reason'], ['version' => $input['version']]);

        return Respond::data(['ok' => true]);
    }

    public function businessPhotos(Request $request): JsonResponse
    {
        [$status, $after] = $this->statusAfter($request, ['pending', 'approved'], 'pending', true);

        return Respond::data(PhotoModeration::businessPage($status, $after));
    }

    public function decideBusinessPhoto(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::businessPhotoDecision()->parse(DomainInput::body($request, ['id' => $id]));
        PhotoModeration::decideBusiness($this->member($request)->actor(), $input);
        $this->decided($request, 'business_photos', 'business_photo', $id, 'business_photo.'.$input['decision'], $input['reason'], ['version' => $input['version']]);

        return Respond::data(['ok' => true]);
    }

    // Profil degisiklikleri ----------------------------------------------------------------------

    public function businessChanges(Request $request): JsonResponse
    {
        DomainInput::query($request, []);

        // Panel yeni istemcidir: ana API'nin v=2 bicimi (calisma saatleri dahil) kullanilir.
        return Respond::data(BusinessChangeReview::pending(true));
    }

    public function decideBusinessChange(Request $request, string $businessId): JsonResponse
    {
        $input = AdminSchemas::profileDecision()->parse(DomainInput::body($request, ['businessId' => $businessId]));
        BusinessChangeReview::decide($this->member($request)->actor(), $input);
        $this->decided($request, 'business_changes', 'business_change', $businessId, 'business_change.'.$input['decision'], $input['reason'], ['revisionId' => $input['revisionId']]);

        return Respond::data(['ok' => true]);
    }

    // Yanlis bilgi bildirimleri ------------------------------------------------------------------

    public function reports(Request $request): JsonResponse
    {
        [$status, $after] = $this->statusAfter($request, ['submitted', 'resolved', 'dismissed'], 'submitted', true);

        return Respond::data(BusinessReports::queue($status, $after));
    }

    public function decideReport(Request $request, string $id): JsonResponse
    {
        $input = BusinessReports::decisionSchema()->parse(DomainInput::body($request, ['id' => $id]));
        BusinessReports::decide($this->member($request)->accountId, $input);
        $this->decided($request, 'business_reports', 'business_report', $id, 'business_report.'.$input['decision'], $input['resolution']);

        return Respond::data(['ok' => true]);
    }

    // Teklif/randevu ozellik basvurulari ---------------------------------------------------------

    public function activations(Request $request): JsonResponse
    {
        $q = DomainInput::query($request, ['status', 'after']);
        $status = Z::enum(['pending', 'approved', 'rejected', 'paused'])->parse($q->status ?? 'pending');
        $after = ($q->after ?? '') !== '' ? Schemas::id()->parse($q->after) : null;

        return Respond::data(Activations::queue($status, $after));
    }

    public function activation(Request $request, string $businessId): JsonResponse
    {
        return Respond::data(Activations::view($this->member($request)->actor(), Schemas::id()->parse($businessId)));
    }

    public function decideActivation(Request $request, string $businessId): JsonResponse
    {
        $input = Schemas::activationAction()->parse(DomainInput::body($request, ['businessId' => $businessId]));
        if (! in_array($input['action'], ['approve', 'reject', 'pause'], true)) {
            throw new HttpError(400, 'Panelden yalnız onay, ret veya duraklatma kararı verilir.');
        }
        Activations::act($this->member($request)->actor(), $input);
        $this->decided($request, 'feature_activations', 'feature_activation', $businessId, 'activation.'.$input['action'], $input['reason'], ['version' => $input['version']]);

        return Respond::data(['ok' => true]);
    }
}
