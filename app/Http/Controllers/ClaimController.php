<?php

namespace App\Http\Controllers;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\ClaimAdmin;
use App\Domain\Content\Schemas;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** /api/v1/claims: sahiplik basvurulari (ana API ClaimAdmin cekirdegi). */
final class ClaimController extends PanelController
{
    public function index(Request $request): JsonResponse
    {
        return Respond::data(ClaimAdmin::open());
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $c = DB::table('claims')->where('id', $id)->first() ?? $this->notFound('Başvuru');
        $this->audit($request, 'claim.viewed', 'claim', $id);

        return Respond::data(Shape::row($c, [], [], ['lock_key', 'open_lock_key']) + [
            'evidence' => Shape::rows(DB::table('claim_evidence')->where('claim_id', $id)->orderBy('created_at_ms')->get(['id', 'kind', 'status', 'bytes', 'width', 'height', 'sha256', 'created_at_ms', 'ready_at', 'withdrawn_at'])),
            'verifications' => Shape::rows(DB::table('claim_verifications')->where('claim_id', $id)->orderByDesc('created_at')->get(['id', 'status', 'evidence_revision', 'expires_at_ms', 'reviewed_by', 'reason', 'created_at'])),
            'history' => Shape::rows(DB::table('claim_history')->where('claim_id', $id)->orderBy('created_at')->get(), ['details'], [], ['claim_id']),
        ]);
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::claimReview()->parse(DomainInput::body($request, ['claimId' => $id]));
        $result = ClaimAdmin::decide($this->member($request)->actor(), $input);
        $this->decided($request, 'claims', 'claim', $id, 'claim.'.$input['decision'], $input['reason'], isset($input['verificationReference']) ? ['verificationReference' => $input['verificationReference']] : []);

        return Respond::data($result);
    }

    public function target(Request $request, string $businessId): JsonResponse
    {
        return Respond::data(ClaimAdmin::target(Schemas::id()->parse($businessId)));
    }

    public function mapTarget(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::claimTarget()->parse(DomainInput::body($request, ['claimId' => $id]));
        $result = ClaimAdmin::mapTarget($this->member($request)->actor(), $input);
        $this->decided($request, 'claims', 'claim', $id, 'claim.target_'.$input['mode'], $input['reason'], $input['mode'] === 'existing' ? ['businessId' => $input['businessId']] : ['newBusiness' => true]);

        return Respond::data($result);
    }

    public function verifyEvidence(Request $request, string $id): JsonResponse
    {
        ClaimAdmin::assertEvidenceStore();
        $input = AdminSchemas::evidenceVerification()->parse(DomainInput::body($request, ['claimId' => $id]));
        $reference = ClaimAdmin::verifyEvidence($this->member($request)->actor(), $input);
        $this->decided($request, 'claims', 'claim', $id, 'claim.evidence_verified', $input['reason'], ['evidenceIds' => $input['evidenceIds'], 'verificationReference' => $reference]);

        return Respond::data(['verificationReference' => $reference]);
    }
}
