<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\WorkItems\Queues;
use App\Panel\WorkItems\WorkItems;
use App\Support\Tx;
use Illuminate\Console\Command;

/** Ana API kuyruklarindaki acik kayitlardan panel isi acar/kapatir. Kaynak tabloya yazmaz. */
final class WorkItemsSync extends Command
{
    protected $signature = 'panel:work-items-sync {--queue=* : Yalniz bu kuyruklar} {--apply : Yaz (yoksa yalniz sayim)}';

    protected $description = 'Is kuyrugunu kaynak kuyruklarla uzlastirir (varsayilan kuru calisma).';

    public function handle(): int
    {
        $queues = (array) $this->option('queue') ?: array_keys(Queues::all());
        $unknown = array_diff($queues, array_keys(Queues::all()));
        if ($unknown !== []) {
            $this->error('Bilinmeyen kuyruk: '.implode(', ', $unknown));

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        $rows = [];
        foreach ($queues as $queue) {
            $rows[] = array_values($apply ? Tx::run(fn () => WorkItems::sync($queue, true)) : WorkItems::sync($queue, false));
        }
        $this->table(['Kuyruk', 'Kaynakta açık', 'Açılacak', 'Yeniden açılacak', 'Kapanacak', 'Durumu değişen'], $rows);
        if ($apply) {
            Audit::console('work_items.synced', null, null, null, ['queues' => $queues]);
        } else {
            $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');
        }

        return self::SUCCESS;
    }
}
