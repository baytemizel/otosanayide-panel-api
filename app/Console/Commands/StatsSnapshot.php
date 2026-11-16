<?php

namespace App\Console\Commands;

use App\Panel\Stats\Stats;
use App\Panel\Support\Clock;
use App\Panel\Support\PanelDb;
use Illuminate\Console\Command;

/** Gunluk sayim anlik goruntusu (Europe/Istanbul gunu). Ayni gun yeniden calisirsa deger guncellenir. */
final class StatsSnapshot extends Command
{
    protected $signature = 'panel:stats-snapshot {--date= : YYYY-AA-GG (varsayilan bugun, Europe/Istanbul)} {--apply : Yaz (yoksa yalniz yazdir)}';

    protected $description = 'Katalog, hesap ve kuyruk sayimlarini panel_stat_snapshots tablosuna yazar.';

    public function handle(): int
    {
        $date = (string) ($this->option('date') ?: Clock::now()->setTimezone('Europe/Istanbul')->toDateString());
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            $this->error('Tarih YYYY-AA-GG olmalı.');

            return self::INVALID;
        }
        $rows = Stats::snapshotRows();
        $this->table(['Ölçü', 'Boyut', 'Değer'], array_map(static fn (array $r): array => [$r['metric'], $r['dimension'], $r['value']], $rows));
        if (! $this->option('apply')) {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');

            return self::SUCCESS;
        }
        $now = Clock::db();
        PanelDb::table('panel_stat_snapshots')->upsert(
            array_map(static fn (array $r): array => ['snapshot_date' => $date, 'metric' => $r['metric'], 'dimension' => $r['dimension'], 'value' => $r['value'], 'created_at' => $now], $rows),
            ['snapshot_date', 'metric', 'dimension'],
            ['value', 'created_at'],
        );
        $this->info(count($rows).' ölçü yazıldı ('.$date.').');

        return self::SUCCESS;
    }
}
