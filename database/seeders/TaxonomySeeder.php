<?php

namespace Database\Seeders;

use App\Panel\Support\PanelDb;
use App\Panel\Taxonomy\Taxonomy;
use Illuminate\Database\Seeder;

/**
 * Ana API'nin kullandigi referans listeler (resources/data: 20 kategori ve hizmetleri, es anlamlilar,
 * 81 il ve kapsam/ilce koordinatlari). Gercek isletme verisi icermez. Eksik kaydi ekler, var olana dokunmaz.
 */
final class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $counts = PanelDb::tx(fn () => Taxonomy::import(Taxonomy::readSource(resource_path('data')), true));
        $this->command?->info('Sınıflandırma: '.json_encode($counts));
    }
}
