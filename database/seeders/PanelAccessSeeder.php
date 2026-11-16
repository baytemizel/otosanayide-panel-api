<?php

namespace Database\Seeders;

use App\Panel\Auth\PermissionSync;
use App\Support\Tx;
use Illuminate\Database\Seeder;

/** config/panel_permissions.php: yetki katalogu ve yedi sistem rolu (panel:permissions-sync ile ayni islem). */
final class PanelAccessSeeder extends Seeder
{
    public function run(): void
    {
        $report = Tx::run(fn () => PermissionSync::run(true));
        $this->command?->info(sprintf('Yetki: +%d, rol: +%d, rol yetkisi değişen: %d', count($report['permissionsAdded']), count($report['rolesAdded']), count($report['rolePermissionsChanged'])));
    }
}
