<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\Settings\Settings;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Console\Command;

/**
 * Denetim kaydi saklama. Saklama suresi tanimsizsa (varsayilan) hicbir sey silmez; sure en az 365 gundur.
 * Silme geri alinamaz: --apply ile birlikte --confirm-count=<kuru calismadaki sayi> istenir.
 */
final class AuditPrune extends Command
{
    protected $signature = 'panel:audit-prune {--days= : Saklama (gun); bos: panel ayari audit.retention_days}
        {--confirm-count= : Kuru calismanin verdigi sayi}
        {--apply : Sil (yoksa yalniz sayim)}';

    protected $description = 'Saklama suresini asan denetim kayitlarini siler (varsayilan kuru calisma).';

    public function handle(): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : Settings::get('audit.retention_days');
        if ($days === null) {
            $this->info('Saklama süresi tanımsız (audit.retention_days): hiçbir kayıt silinmez.');

            return self::SUCCESS;
        }
        if (! is_int($days) || $days < 365) {
            $this->error('Saklama süresi en az 365 gün olmalı.');

            return self::INVALID;
        }
        $cutoff = Clock::db(Clock::now()->subDays($days));
        $count = PanelDb::table('panel_audit_logs')->where('occurred_at', '<', $cutoff)->count();
        $this->line("{$days} günden eski kayıt: {$count} (sınır {$cutoff} UTC)");
        if (! $this->option('apply')) {
            $this->warn('Kuru çalışma: silinmedi. Silmek için --apply --confirm-count='.$count);

            return self::SUCCESS;
        }
        if ((string) $this->option('confirm-count') !== (string) $count) {
            $this->error('--confirm-count kuru çalışmadaki sayıyla aynı olmalı.');

            return self::FAILURE;
        }
        $deleted = 0;
        do {
            $batch = PanelDb::table('panel_audit_logs')->where('occurred_at', '<', $cutoff)->orderBy('id')->limit(5000)->delete();
            $deleted += $batch;
        } while ($batch > 0);
        Audit::console('audit.pruned', null, null, null, ['days' => $days, 'deleted' => $deleted, 'cutoff' => $cutoff]);
        $this->info("Silindi: {$deleted}");

        return self::SUCCESS;
    }
}
