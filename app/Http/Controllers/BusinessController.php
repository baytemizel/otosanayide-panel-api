<?php

namespace App\Http\Controllers;

use App\Domain\Admin\AdminSchemas;
use App\Domain\Admin\CatalogueAdmin;
use App\Domain\Content\Schemas;
use App\Panel\Http\DomainInput;
use App\Panel\Http\Paging;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/businesses ve /api/v1/catalogue. Okumalar panelin kendi sorgularidir; taslak, yayin ve gecmis
 * ana API'nin CatalogueAdmin cekirdegiyle (dort teyit, surum, arama projeksiyonu, CDN yansisi) yurur.
 */
final class BusinessController extends PanelController
{
    private const BOOL = ['published', 'sample', 'owner_ready', 'feature_quotes', 'feature_appointments', 'card_payment', 'wc'];

    public function index(Request $request): JsonResponse
    {
        $f = $this->query($request, [
            'q' => ['nullable', 'string', 'max:160'], 'city' => ['nullable', 'string', 'max:100'], 'district' => ['nullable', 'string', 'max:100'],
            'published' => ['nullable', 'in:true,false'], 'ownership' => ['nullable', 'in:unclaimed,pending,verified'],
            'coordinateStatus' => ['nullable', 'in:verified,unverified'], 'sample' => ['nullable', 'in:true,false'],
            'category' => ['nullable', 'string', 'max:120'], 'imported' => ['nullable', 'in:true,false'], 'hasOwner' => ['nullable', 'in:true,false'],
            'sort' => ['nullable', 'in:name,-name,createdAt,-createdAt,updatedAt,-updatedAt'],
        ]);
        $q = DB::table('businesses as b')->select(['b.id', 'b.name', 'b.city', 'b.district', 'b.phone', 'b.published', 'b.sample', 'b.ownership', 'b.owner_uid', 'b.coordinate_status', 'b.review_count', 'b.review_rating_sum', 'b.profile_version', 'b.publication_version', 'b.import_source_id', 'b.created_at', 'b.updated_at']);
        if (isset($f['q'])) {
            $term = trim($f['q']);
            $q->where(fn ($w) => $w->where('b.id', $term)->orWhere('b.name', 'like', '%'.addcslashes($term, '%_\\').'%'));
        }
        foreach (['city' => 'b.city', 'district' => 'b.district', 'ownership' => 'b.ownership', 'coordinateStatus' => 'b.coordinate_status'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k]);
            }
        }
        foreach (['published' => 'b.published', 'sample' => 'b.sample'] as $k => $col) {
            if (isset($f[$k])) {
                $q->where($col, $f[$k] === 'true');
            }
        }
        if (isset($f['imported'])) {
            $f['imported'] === 'true' ? $q->whereNotNull('b.import_source_id') : $q->whereNull('b.import_source_id');
        }
        if (isset($f['hasOwner'])) {
            $f['hasOwner'] === 'true' ? $q->whereNotNull('b.owner_uid') : $q->whereNull('b.owner_uid');
        }
        if (isset($f['category'])) {
            $q->whereExists(fn ($w) => $w->from('business_values as v')->whereColumn('v.business_id', 'b.id')->where('v.kind', 'category')->where('v.value', $f['category']));
        }
        $sort = $f['sort'] ?? '-updatedAt';
        $col = ['name' => 'b.name', 'createdAt' => 'b.created_at', 'updatedAt' => 'b.updated_at'][ltrim($sort, '-')];
        $q->orderBy($col, str_starts_with($sort, '-') ? 'desc' : 'asc')->orderBy('b.id');
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(Shape::rows($rows, [], self::BOOL), $paging, $total);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $b = DB::table('businesses')->where('id', $id)->first() ?? $this->notFound('İşletme');
        $values = DB::table('business_values')->where('business_id', $id)->orderBy('kind')->orderBy('position')->get()->groupBy('kind');
        $source = DB::table('catalogue_sources')->where('business_id', $id)->first(['id', 'candidate_id', 'source_row', 'imported_at']);
        $flags = $source === null ? [] : DB::table('catalogue_source_values')->where('source_id', $source->id)->where('kind', 'flag')->orderBy('position')->pluck('value')->all();
        $reviews = DB::table('reviews')->where('business_id', $id)->select('status', DB::raw('count(*) as c'))->groupBy('status')->pluck('c', 'status');

        return Respond::data(Shape::row($b, ['opening_hours'], self::BOOL) + [
            'categories' => ($values->get('category') ?? collect())->pluck('value')->values()->all(),
            'services' => ($values->get('service') ?? collect())->pluck('value')->values()->all(),
            'brands' => ($values->get('brand') ?? collect())->pluck('value')->values()->all(),
            'publicPhotoIds' => DB::table('business_public_photos')->where('business_id', $id)->orderBy('position')->pluck('photo_id')->all(),
            'searchable' => DB::table('business_search')->where('business_id', $id)->exists(),
            'source' => $source === null ? null : Shape::row($source) + ['flags' => $flags],
            'pendingChange' => Shape::row(DB::table('business_changes')->where('business_id', $id)->where('status', 'pending')->first(['revision_id', 'user_id', 'status', 'created_at'])),
            'featureActivation' => Shape::row(DB::table('feature_activations')->where('business_id', $id)->first(), [], ['feature_quotes', 'feature_appointments', 'monitor_inbox', 'capacity_ready']),
            'reviewCounts' => $reviews->map(static fn ($v): int => (int) $v)->all(),
            'openClaims' => DB::table('claims')->where('business_id', $id)->whereIn('status', ['submitted', 'needs_information'])->count(),
            'openReports' => DB::table('business_reports')->where('business_id', $id)->where('status', 'submitted')->count(),
        ]);
    }

    /** Ham kaynak satiri ayri yetkidir (businesses.source): kaynak lisans/inceleme durumu belirsiz veri tasir. */
    public function source(Request $request, string $id): JsonResponse
    {
        $s = DB::table('catalogue_sources')->where('business_id', $id)->first() ?? $this->notFound('Kaynak kaydı');
        $values = DB::table('catalogue_source_values')->where('source_id', $s->id)->orderBy('kind')->orderBy('position')->get()->groupBy('kind');
        $this->audit($request, 'business.source_viewed', 'business', $id);

        return Respond::data(Shape::row($s, ['raw_source', 'extra']) + array_map(static fn ($g) => $g->pluck('value')->values()->all(), $values->all()));
    }

    public function members(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('business_memberships as m')->leftJoin('auth_accounts as a', 'a.id', '=', 'm.user_id')->where('m.business_id', $id)
            ->orderBy('m.role')->get(['m.user_id', 'a.email', 'm.role', 'm.active', 'm.claim_id', 'm.granted_by', 'm.version', 'm.created_at', 'm.updated_at']);
        $team = DB::table('business_team_audit')->where('business_id', $id)->orderByDesc('created_at_ms')->limit(50)->get(['id', 'action', 'actor_uid', 'subject', 'created_at_ms']);

        return Respond::data(['members' => Shape::rows($rows, [], ['active']), 'teamAudit' => Shape::rows($team)]);
    }

    public function reviews(Request $request, string $id): JsonResponse
    {
        $f = $this->query($request, ['status' => ['nullable', 'in:pending,approved,rejected,hidden,withdrawn']]);
        $q = DB::table('reviews')->where('business_id', $id)->orderByDesc('created_at')
            ->select(['id', 'user_id', 'rating', 'text', 'status', 'version', 'moderation_hold', 'ai_status', 'ai_flagged', 'reviewed_by', 'reviewed_at', 'created_at']);
        if (isset($f['status'])) {
            $q->where('status', $f['status']);
        }
        $paging = Paging::from($request);
        [$rows, $total] = $paging->run($q);

        return Respond::page(Shape::rows($rows, [], ['moderation_hold', 'ai_flagged']), $paging, $total);
    }

    public function reports(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('business_reports')->where('business_id', $id)->orderByDesc('created_at_ms')->limit(100)->get(['id', 'reason', 'note', 'status', 'version', 'resolution', 'reviewed_by', 'created_at_ms', 'updated_at_ms']);

        return Respond::data(Shape::rows($rows));
    }

    public function claims(Request $request, string $id): JsonResponse
    {
        $rows = DB::table('claims')->where('business_id', $id)->orderByDesc('created_at')->limit(100)->get(['id', 'user_id', 'name', 'status', 'evidence_count', 'reviewed_by', 'reviewed_at', 'created_at']);

        return Respond::data(Shape::rows($rows));
    }

    // --- Cekirdek: katalog taslagi ve yayin ------------------------------------------------------

    public function queue(Request $request): JsonResponse
    {
        $q = DomainInput::query($request, ['published', 'after']);
        $after = ($q->after ?? '') !== '' ? Schemas::id()->parse($q->after) : null;

        return Respond::data(CatalogueAdmin::page(($q->published ?? null) === 'true', $after));
    }

    public function history(Request $request, string $id): JsonResponse
    {
        $q = DomainInput::query($request, ['after']);
        $input = AdminSchemas::catalogueHistoryQuery()->parse((object) (['businessId' => $id] + (isset($q->after) ? ['after' => $q->after] : [])));

        return Respond::data(CatalogueAdmin::history($input['businessId'], $input['after'] ?? null));
    }

    public function draft(Request $request, string $id): JsonResponse
    {
        return Respond::data(CatalogueAdmin::draft($this->member($request)->actor(), Schemas::id()->parse($id)));
    }

    public function saveDraft(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::catalogueDraft()->parse(DomainInput::body($request, ['businessId' => $id]));
        CatalogueAdmin::saveDraft($this->member($request)->actor(), $input);
        $this->audit($request, 'catalogue.draft_saved', 'business', $id, $input['reason'], ['profileVersion' => $input['profileVersion'], 'publicationVersion' => $input['publicationVersion']]);

        return Respond::data(CatalogueAdmin::draft($this->member($request)->actor(), $id));
    }

    public function publication(Request $request, string $id): JsonResponse
    {
        $input = AdminSchemas::publicationAction()->parse(DomainInput::body($request, ['businessId' => $id]));
        CatalogueAdmin::publication($this->member($request)->actor(), $input);
        $this->audit($request, 'catalogue.'.($input['action'] === 'publish' ? 'published' : 'withdrawn'), 'business', $id, $input['reason'], ['version' => $input['version'], 'profileVersion' => $input['profileVersion']] + (isset($input['checks']) ? ['checks' => array_map(static fn (array $c): string => $c['reference'], $input['checks'])] : []));

        return Respond::data(['ok' => true]);
    }
}
