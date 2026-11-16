<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Yalniz referans veri ve yalniz panel_* tablolari: yetki katalogu ve sistem rolleri, ayar varsayilanlari,
 * siniflandirma listeleri (ana API resources/data). Idempotent; ana API tablolarina ve hesaplara yazmaz.
 * Ilk yonetici: PanelBootstrapAdminSeeder (acik --class ile). Sentetik veri: LocalPanelFixtureSeeder (yalniz yerel).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PanelAccessSeeder::class,
            PanelSettingSeeder::class,
            TaxonomySeeder::class,
        ]);
    }
}
