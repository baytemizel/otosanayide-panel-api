<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\Auth\PermissionSync;
use App\Support\Tx;
use Illuminate\Console\Command;

final class PermissionsSync extends Command
{
    protected $signature = 'panel:permissions-sync {--apply : Yaz (yoksa yalniz fark raporu)}';

    protected $description = 'Yetki katalogunu ve sistem rollerini config/panel_permissions.php ile esitler.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $report = $apply ? Tx::run(fn () => PermissionSync::run(true)) : PermissionSync::run(false);
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
        if ($report['orphanPermissions'] !== []) {
            $this->warn('Katalogda olmayan yetkiler silinmedi: '.implode(', ', $report['orphanPermissions']));
        }
        if ($apply) {
            Audit::console('rbac.synced', null, null, null, $report);
            $this->info('Eşitlendi.');
        } else {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');
        }

        return self::SUCCESS;
    }
}
