<?php

namespace Database\Seeders;

use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Database\Seeder;

/** Ayar varsayilanlarini yazar; var olan (panelden degistirilmis) degerlere dokunmaz. */
final class PanelSettingSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [];
        foreach ((array) config('panel.defaults') as $key => $value) {
            $rows[] = ['key' => $key, 'value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'version' => 1, 'updated_by' => null, 'updated_at' => Clock::db()];
        }
        $added = PanelDb::table('panel_settings')->insertOrIgnore($rows);
        $this->command?->info('Ayar: +'.$added);
    }
}
