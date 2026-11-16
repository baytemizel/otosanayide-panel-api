<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Http\Respond;
use App\Panel\Http\Shape;
use App\Panel\Support\Clock;
use App\Panel\Taxonomy\Taxonomy;
use App\Support\Tx;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/taxonomy: kategori, hizmet, es anlamli ve kapsam listeleri. Ana API bu listeleri dosyadan okur;
 * degisiklik canliya `GET /taxonomy/export` veya `panel:taxonomy-export` ciktisinin ana API deposuna
 * alinmasiyla gecer. Kategori kimligi kayitlarda kullanildigi icin silinmez, pasiflestirilir.
 */
final class TaxonomyController extends PanelController
{
    public function categories(Request $request): JsonResponse
    {
        $f = $this->query($request, ['withUsage' => ['nullable', 'in:true,false']]);
        $services = DB::table('panel_taxonomy_services')->orderBy('position')->get()->groupBy('category_id');
        $usage = ($f['withUsage'] ?? 'false') === 'true'
            ? DB::table('business_values')->where('kind', 'category')->select('value', DB::raw('count(*) as c'))->groupBy('value')->pluck('c', 'value') : null;
        $rows = DB::table('panel_taxonomy_categories')->orderBy('position')->orderBy('id')->get();

        return Respond::data($rows->map(static fn (\stdClass $c): array => Taxonomy::categoryView($c, ($services->get($c->id) ?? collect())->pluck('name')->values()->all(), $usage === null ? null : (int) ($usage[$c->id] ?? 0)))->values()->all());
    }

    public function createCategory(Request $request): JsonResponse
    {
        $in = $this->input($request, [
            'id' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{1,63}$/'], 'name' => ['required', 'string', 'min:2', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'], 'services' => ['sometimes', 'array', 'max:50'], 'services.*' => ['string', 'min:2', 'max:120', 'distinct'],
        ]);
        $actor = $this->member($request)->accountId;
        Tx::run(function () use ($in, $actor): void {
            if (DB::table('panel_taxonomy_categories')->where('id', $in['id'])->exists()) {
                throw new HttpError(409, 'Bu kimlikle kategori var.');
            }
            $position = $in['position'] ?? ((int) DB::table('panel_taxonomy_categories')->max('position') + 1);
            DB::table('panel_taxonomy_categories')->insert(['id' => $in['id'], 'name' => $in['name'], 'position' => $position, 'updated_by' => $actor, 'created_at' => Clock::db(), 'updated_at' => Clock::db()]);
            $this->writeServices($in['id'], $in['services'] ?? []);
        });
        $this->audit($request, 'taxonomy.category_created', 'taxonomy_category', $in['id'], null, ['name' => $in['name']]);

        return Respond::created($this->category($in['id']));
    }

    public function updateCategory(Request $request, string $id): JsonResponse
    {
        $in = $this->input($request, [
            'version' => ['required', 'integer', 'min:1'], 'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:999'], 'active' => ['sometimes', 'boolean'],
            'services' => ['sometimes', 'array', 'max:50'], 'services.*' => ['string', 'min:2', 'max:120', 'distinct'],
        ]);
        $actor = $this->member($request)->accountId;
        $before = Tx::run(function () use ($id, $in, $actor): array {
            $c = DB::table('panel_taxonomy_categories')->where('id', $id)->lockForUpdate()->first() ?? $this->notFound('Kategori');
            if ((int) $c->version !== $in['version']) {
                throw new HttpError(409, 'Kategori değişti. Yenileyip tekrar deneyin.');
            }
            $before = $this->category($id);
            $patch = array_filter(['name' => $in['name'] ?? null, 'position' => $in['position'] ?? null, 'active' => $in['active'] ?? null], static fn ($v) => $v !== null);
            DB::table('panel_taxonomy_categories')->where('id', $id)->update($patch + ['version' => $in['version'] + 1, 'updated_by' => $actor, 'updated_at' => Clock::db()]);
            if (array_key_exists('services', $in)) {
                $this->writeServices($id, $in['services']);
            }

            return $before;
        });
        $after = $this->category($id);
        $this->audit($request, 'taxonomy.category_updated', 'taxonomy_category', $id, null, ['before' => $before, 'after' => $after]);

        return Respond::data($after);
    }

    public function aliases(Request $request): JsonResponse
    {
        $f = $this->query($request, ['kind' => ['nullable', 'in:alias,english']]);
        $q = DB::table('panel_taxonomy_aliases')->orderBy('kind')->orderBy('term');
        if (isset($f['kind'])) {
            $q->where('kind', $f['kind']);
        }

        return Respond::data(Shape::rows($q->get()));
    }

    public function putAlias(Request $request): JsonResponse
    {
        $in = $this->input($request, ['kind' => ['required', 'in:alias,english'], 'term' => ['required', 'string', 'min:2', 'max:120'], 'target' => ['required', 'string', 'min:2', 'max:120']]);
        $term = mb_strtolower(trim($in['term']), 'UTF-8');
        $existing = DB::table('panel_taxonomy_aliases')->where('kind', $in['kind'])->where('term', $term)->first();
        DB::table('panel_taxonomy_aliases')->updateOrInsert(['kind' => $in['kind'], 'term' => $term], ['target' => mb_strtolower(trim($in['target']), 'UTF-8'), 'updated_by' => $this->member($request)->accountId, 'updated_at' => Clock::db()]);
        $this->audit($request, 'taxonomy.alias_saved', 'taxonomy_alias', $in['kind'].':'.$term, null, ['before' => $existing?->target, 'after' => $in['target']]);

        return Respond::data(Shape::row(DB::table('panel_taxonomy_aliases')->where('kind', $in['kind'])->where('term', $term)->first()));
    }

    public function deleteAlias(Request $request, string $kind, string $term): JsonResponse
    {
        if (! in_array($kind, ['alias', 'english'], true)) {
            $this->notFound('Eş anlamlı');
        }
        $deleted = DB::table('panel_taxonomy_aliases')->where('kind', $kind)->where('term', $term)->delete();
        if ($deleted === 0) {
            $this->notFound('Eş anlamlı');
        }
        $this->audit($request, 'taxonomy.alias_deleted', 'taxonomy_alias', $kind.':'.$term);

        return Respond::data(['deleted' => true]);
    }

    public function cities(Request $request): JsonResponse
    {
        $f = $this->query($request, ['inCoverage' => ['nullable', 'in:true,false']]);
        $q = DB::table('panel_taxonomy_cities')->orderBy('plate_order');
        if (isset($f['inCoverage'])) {
            $q->where('in_coverage', $f['inCoverage'] === 'true');
        }
        $districts = DB::table('panel_taxonomy_districts')->select('city', DB::raw('count(*) as c'))->groupBy('city')->pluck('c', 'city');

        return Respond::data($q->get()->map(static fn (\stdClass $c): array => (array) Shape::row($c, [], ['in_coverage', 'metropolitan']) + ['districtCount' => (int) ($districts[$c->name] ?? 0)])->values()->all());
    }

    public function updateCity(Request $request, string $name): JsonResponse
    {
        $in = $this->input($request, ['version' => ['required', 'integer', 'min:1'], 'inCoverage' => ['sometimes', 'boolean'], 'metropolitan' => ['sometimes', 'boolean'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $actor = $this->member($request)->accountId;
        Tx::run(function () use ($name, $in, $actor): void {
            $c = DB::table('panel_taxonomy_cities')->where('name', $name)->lockForUpdate()->first() ?? $this->notFound('İl');
            if ((int) $c->version !== $in['version']) {
                throw new HttpError(409, 'İl kaydı değişti. Yenileyip tekrar deneyin.');
            }
            if (($in['inCoverage'] ?? false) === true && $c->latitude === null) {
                throw new HttpError(422, 'Merkez ve ilçe koordinatı olmayan il kapsama alınamaz.');
            }
            DB::table('panel_taxonomy_cities')->where('name', $name)->update(array_filter(['in_coverage' => $in['inCoverage'] ?? null, 'metropolitan' => $in['metropolitan'] ?? null], static fn ($v) => $v !== null) + ['version' => $in['version'] + 1, 'updated_by' => $actor, 'updated_at' => Clock::db()]);
        });
        $this->audit($request, 'taxonomy.city_updated', 'taxonomy_city', $name, $in['reason'], array_intersect_key($in, ['inCoverage' => 1, 'metropolitan' => 1]));

        return Respond::data(Shape::row(DB::table('panel_taxonomy_cities')->where('name', $name)->first(), [], ['in_coverage', 'metropolitan']));
    }

    /** Ana API dosya bicimi ve depodaki surumle fark (eklenen/cikan kimlikler). */
    public function export(Request $request): JsonResponse
    {
        $bundled = Taxonomy::readSource(resource_path('data'));
        $export = Taxonomy::export((array) ($bundled['coverage']['source'] ?? []));
        $was = array_column($bundled['catalogue']['categories'], 'id');
        $now = array_column($export['catalogue']['categories'], 'id');
        $this->audit($request, 'taxonomy.exported', null, null);

        return Respond::data($export + ['diff' => [
            'categoriesAdded' => array_values(array_diff($now, $was)),
            'categoriesRemoved' => array_values(array_diff($was, $now)),
            'coverageAdded' => array_values(array_diff($export['coverage']['cities'], $bundled['coverage']['cities'])),
            'coverageRemoved' => array_values(array_diff($bundled['coverage']['cities'], $export['coverage']['cities'])),
        ]]);
    }

    /** @param list<string> $services */
    private function writeServices(string $categoryId, array $services): void
    {
        DB::table('panel_taxonomy_services')->where('category_id', $categoryId)->delete();
        if ($services !== []) {
            DB::table('panel_taxonomy_services')->insert(array_map(static fn (string $s, int $i): array => ['category_id' => $categoryId, 'name' => trim($s), 'position' => $i], $services, array_keys($services)));
        }
    }

    /** @return array<string,mixed> */
    private function category(string $id): array
    {
        $c = DB::table('panel_taxonomy_categories')->where('id', $id)->first() ?? $this->notFound('Kategori');

        return Taxonomy::categoryView($c, DB::table('panel_taxonomy_services')->where('category_id', $id)->orderBy('position')->pluck('name')->all());
    }
}
