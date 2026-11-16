<?php

namespace App\Panel\Taxonomy;

use App\Panel\Support\Clock;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Siniflandirma listeleri: ana API'nin resources/data/{catalogue,coverage,provinces}.json bicimiyle
 * birebir ice/disa aktarim. Ice aktarma yalniz panel_taxonomy_* tablolarina yazar.
 */
final class Taxonomy
{
    /** @return array{catalogue:array<string,mixed>,coverage:array<string,mixed>,provinces:array<string,mixed>} */
    public static function readSource(string $dir): array
    {
        $out = [];
        foreach (['catalogue', 'coverage', 'provinces'] as $name) {
            $path = rtrim($dir, '/').'/'.$name.'.json';
            if (! is_file($path)) {
                throw new RuntimeException('Taxonomy source missing: '.$name.'.json');
            }
            $out[$name] = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        }
        if (! isset($out['catalogue']['categories'], $out['coverage']['cities'], $out['provinces']['provinces'])) {
            throw new RuntimeException('Taxonomy source shape changed');
        }

        return $out;
    }

    /**
     * Var olan kayitlari degistirmeden eksikleri ekler (idempotent). --replace yalniz bos tabloda anlamlidir.
     *
     * @return array{categories:int,services:int,aliases:int,cities:int,districts:int}
     */
    public static function import(array $src, bool $apply): array
    {
        $counts = ['categories' => 0, 'services' => 0, 'aliases' => 0, 'cities' => 0, 'districts' => 0];
        $now = Clock::db();
        $existingCategories = DB::table('panel_taxonomy_categories')->pluck('id')->flip();
        foreach (array_values($src['catalogue']['categories']) as $i => $c) {
            if ($existingCategories->has($c['id'])) {
                continue;
            }
            $counts['categories']++;
            $counts['services'] += count($c['services'] ?? []);
            if ($apply) {
                DB::table('panel_taxonomy_categories')->insert(['id' => $c['id'], 'name' => $c['name'], 'position' => $i, 'created_at' => $now, 'updated_at' => $now]);
                $rows = [];
                foreach (array_values($c['services'] ?? []) as $p => $s) {
                    $rows[] = ['category_id' => $c['id'], 'name' => $s, 'position' => $p];
                }
                if ($rows !== []) {
                    DB::table('panel_taxonomy_services')->insert($rows);
                }
            }
        }
        foreach (['alias' => 'aliases', 'english' => 'englishExtra'] as $kind => $key) {
            $existing = DB::table('panel_taxonomy_aliases')->where('kind', $kind)->pluck('term')->flip();
            $rows = [];
            foreach ((array) ($src['catalogue'][$key] ?? []) as $term => $target) {
                if (! $existing->has((string) $term)) {
                    $rows[] = ['term' => (string) $term, 'kind' => $kind, 'target' => (string) $target, 'updated_at' => $now];
                }
            }
            $counts['aliases'] += count($rows);
            if ($apply) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('panel_taxonomy_aliases')->insert($chunk);
                }
            }
        }
        $existingCities = DB::table('panel_taxonomy_cities')->pluck('name')->flip();
        $coverage = array_flip($src['coverage']['cities']);
        $metro = array_flip($src['coverage']['metropolitanCities'] ?? []);
        $areas = (array) ($src['coverage']['areas'] ?? []);
        foreach (array_values($src['provinces']['provinces']) as $i => $name) {
            if ($existingCities->has($name)) {
                continue;
            }
            $counts['cities']++;
            $area = $areas[$name] ?? null;
            $counts['districts'] += count($area['districts'] ?? []);
            if ($apply) {
                DB::table('panel_taxonomy_cities')->insert(['name' => $name, 'plate_order' => $i + 1, 'in_coverage' => isset($coverage[$name]), 'metropolitan' => isset($metro[$name]), 'latitude' => $area['center']['latitude'] ?? null, 'longitude' => $area['center']['longitude'] ?? null, 'updated_at' => $now]);
                $rows = [];
                foreach (array_values($area['districts'] ?? []) as $p => $d) {
                    $rows[] = ['city' => $name, 'name' => $d['name'], 'position' => $p, 'latitude' => $d['latitude'], 'longitude' => $d['longitude']];
                }
                if ($rows !== []) {
                    DB::table('panel_taxonomy_districts')->insert($rows);
                }
            }
        }

        return $counts;
    }

    /**
     * Ana API dosya bicimi. Pasif kategoriler ve kapsam disi iller ciktiya girmez.
     *
     * @param  array<string,mixed>  $coverageSource  kaynak dosyadaki `source` alani korunur
     * @return array{catalogue:array<string,mixed>,coverage:array<string,mixed>}
     */
    public static function export(array $coverageSource): array
    {
        $services = DB::table('panel_taxonomy_services')->orderBy('category_id')->orderBy('position')->get()->groupBy('category_id');
        $categories = [];
        foreach (DB::table('panel_taxonomy_categories')->where('active', true)->orderBy('position')->orderBy('id')->get() as $c) {
            $categories[] = ['id' => $c->id, 'name' => $c->name, 'services' => ($services->get($c->id) ?? collect())->pluck('name')->values()->all()];
        }
        $aliases = [];
        $english = [];
        foreach (DB::table('panel_taxonomy_aliases')->orderBy('kind')->orderBy('term')->get() as $a) {
            if ($a->kind === 'alias') {
                $aliases[$a->term] = $a->target;
            } else {
                $english[$a->term] = $a->target;
            }
        }
        $collator = new \Collator('tr_TR');
        $cities = DB::table('panel_taxonomy_cities')->where('in_coverage', true)->pluck('name')->all();
        $collator->sort($cities);
        $metro = DB::table('panel_taxonomy_cities')->where('in_coverage', true)->where('metropolitan', true)->pluck('name')->all();
        $collator->sort($metro);
        $districts = DB::table('panel_taxonomy_districts')->orderBy('city')->orderBy('position')->get()->groupBy('city');
        $areas = [];
        foreach (DB::table('panel_taxonomy_cities')->whereNotNull('latitude')->orderBy('plate_order')->get() as $city) {
            $areas[$city->name] = [
                'center' => ['latitude' => (float) $city->latitude, 'longitude' => (float) $city->longitude],
                'districts' => ($districts->get($city->name) ?? collect())->map(static fn (\stdClass $d): array => ['name' => $d->name, 'latitude' => (float) $d->latitude, 'longitude' => (float) $d->longitude])->values()->all(),
            ];
        }

        return [
            'catalogue' => ['categories' => $categories, 'aliases' => (object) $aliases, 'englishExtra' => (object) $english],
            'coverage' => ['schemaVersion' => 1, 'cities' => $cities, 'metropolitanCities' => $metro, 'source' => $coverageSource, 'areas' => (object) $areas],
        ];
    }

    /** @return array<string,mixed> */
    public static function categoryView(\stdClass $c, array $services, ?int $usage = null): array
    {
        $view = [
            'id' => $c->id,
            'name' => $c->name,
            'position' => (int) $c->position,
            'active' => (bool) $c->active,
            'services' => $services,
            'version' => (int) $c->version,
            'updatedBy' => $c->updated_by,
            'updatedAt' => Clock::iso($c->updated_at),
        ];
        if ($usage !== null) {
            $view['usage'] = $usage;
        }

        return $view;
    }
}
