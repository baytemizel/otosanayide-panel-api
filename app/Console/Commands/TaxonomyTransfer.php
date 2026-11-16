<?php

namespace App\Console\Commands;

use App\Panel\Audit\Audit;
use App\Panel\Support\PanelDb;
use App\Panel\Taxonomy\Taxonomy;
use Illuminate\Console\Command;

/**
 * import: resources/data (veya --source) JSON'larindan eksik kayitlari panel_taxonomy_* tablolarina ekler.
 * export: tablolardan ana API bicimiyle catalogue.json ve coverage.json yazar (yalniz dosya; veritabani okunur).
 */
final class TaxonomyTransfer extends Command
{
    protected $signature = 'panel:taxonomy {direction : import|export}
        {--source= : import icin JSON dizini (varsayilan resources/data)}
        {--out= : export icin cikis dizini (varsayilan storage/app/private/taxonomy-export)}
        {--apply : import icin yaz (yoksa yalniz sayim)}';

    protected $description = 'Siniflandirma listelerini ana API JSON bicimiyle ice/disa aktarir.';

    public function handle(): int
    {
        $direction = (string) $this->argument('direction');
        if ($direction === 'import') {
            $src = Taxonomy::readSource((string) ($this->option('source') ?: resource_path('data')));
            $apply = (bool) $this->option('apply');
            $counts = $apply ? PanelDb::tx(fn () => Taxonomy::import($src, true)) : Taxonomy::import($src, false);
            $this->table(array_keys($counts), [array_values($counts)]);
            if ($apply) {
                Audit::console('taxonomy.imported', null, null, null, $counts);
            } else {
                $this->warn('Kuru çalışma: yazılmadı. Yazmak için --apply.');
            }

            return self::SUCCESS;
        }
        if ($direction !== 'export') {
            $this->error('direction import veya export olmalı.');

            return self::INVALID;
        }
        $bundled = Taxonomy::readSource(resource_path('data'));
        $export = Taxonomy::export((array) ($bundled['coverage']['source'] ?? []));
        $out = rtrim((string) ($this->option('out') ?: storage_path('app/private/taxonomy-export')), '/');
        if (! is_dir($out) && ! mkdir($out, 0750, true) && ! is_dir($out)) {
            $this->error('Çıkış dizini oluşturulamadı.');

            return self::FAILURE;
        }
        foreach ($export as $name => $data) {
            file_put_contents($out.'/'.$name.'.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->info('Yazıldı: '.$out.'/catalogue.json, '.$out.'/coverage.json. Ana API resources/data altına incelemeyle alınmalı.');

        return self::SUCCESS;
    }
}
