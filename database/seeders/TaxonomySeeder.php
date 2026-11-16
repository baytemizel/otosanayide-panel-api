<?php

namespace Database\Seeders;

use App\Panel\Taxonomy\Taxonomy;
use App\Support\Tx;
use Illuminate\Database\Seeder;

/**
 * Ana API'nin kullandigi referans listeler (resources/data: 20 kategori ve hizmetleri, es anlamlilar,
 * 81 il ve kapsam/ilce koordinatlari). Gercek isletme verisi icermez. Eksik kaydi ekler, var olana dokunmaz.
 */
final class TaxonomySeeder extends Seeder
{
    public function run(): void
    {
        $counts = Tx::run(fn () => Taxonomy::import(Taxonomy::readSource(resource_path('data')), true));
        $this->command?->info('Sınıflandırma: '.json_encode($counts));
    }
}
