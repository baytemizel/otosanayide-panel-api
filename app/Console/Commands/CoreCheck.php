<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Vendor edilen alan cekirdegi (ana API'den birebir kopya) icin surum kaymasi denetimi. Veritabanina baglanmaz.
 *  - Yerel kayma: panel deposundaki kopya core-manifest.json ozetinden farkliysa (elle degistirilmis).
 *  - Kaynak kayma: ana API'deki dosya manifest ozetinden farkliysa (ana API degismis, panel guncellenmeli).
 * --sync kaynaktaki farkli dosyalari panel deposuna kopyalar ve manifesti yeniler (git farki incelenmeli).
 */
final class CoreCheck extends Command
{
    protected $signature = 'panel:core-check {--source= : Ana API kok dizini (varsayilan PANEL_CORE_SOURCE)}
        {--sync : Kaynaktan kopyala ve manifesti yenile}
        {--write-manifest : Yerel dosyalardan manifesti yeniden yaz}';

    protected $description = 'Ana API alan cekirdegi kopyasinin kaynakla esitligini denetler.';

    public function handle(): int
    {
        $manifestPath = base_path((string) config('panel.core.manifest'));
        $manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
        $source = (string) ($this->option('source') ?: config('panel.core.source_path'));
        $source = str_starts_with($source, '/') ? $source : base_path($source);

        if ($this->option('write-manifest')) {
            $files = is_array($manifest['files'] ?? null) ? array_keys($manifest['files']) : [];
            if ($files === []) {
                $this->error('Manifestte dosya listesi yok.');

                return self::FAILURE;
            }
            $this->writeManifest($manifestPath, $files, $source);

            return self::SUCCESS;
        }
        if (! is_array($manifest) || ! is_array($manifest['files'] ?? null)) {
            $this->error('core-manifest.json okunamadı.');

            return self::FAILURE;
        }

        $rows = [];
        $localDrift = 0;
        $upstreamDrift = 0;
        $missingSource = ! is_dir($source.'/app');
        foreach ($manifest['files'] as $file => $hash) {
            $local = is_file(base_path($file)) ? hash_file('sha256', base_path($file)) : null;
            $upstream = $missingSource ? null : (is_file($source.'/'.$file) ? hash_file('sha256', $source.'/'.$file) : 'missing');
            $l = $local === $hash ? 'ok' : ($local === null ? 'eksik' : 'değişmiş');
            $u = $upstream === null ? '-' : ($upstream === $hash ? 'ok' : ($upstream === 'missing' ? 'kaynakta yok' : 'kaynak değişmiş'));
            $localDrift += $l === 'ok' ? 0 : 1;
            $upstreamDrift += in_array($u, ['ok', '-'], true) ? 0 : 1;
            if ($l !== 'ok' || ! in_array($u, ['ok', '-'], true)) {
                $rows[] = [$file, $l, $u];
            }
        }
        $this->line(sprintf('Kaynak commit (manifest): %s · dosya: %d', $manifest['sourceCommit'] ?? '?', count($manifest['files'])));
        if ($missingSource) {
            $this->warn('Ana API dizini bulunamadı ('.$source.'): yalnız yerel kayma denetlendi.');
        }
        if ($rows !== []) {
            $this->table(['Dosya', 'Panel kopyası', 'Ana API'], $rows);
        }
        if ($this->option('sync') && ! $missingSource && $upstreamDrift > 0) {
            foreach ($rows as [$file, , $u]) {
                if ($u === 'kaynak değişmiş') {
                    copy($source.'/'.$file, base_path($file));
                    $this->line('kopyalandı: '.$file);
                }
            }
            $this->writeManifest($manifestPath, array_keys($manifest['files']), $source);
            $this->warn('Yeni bağımlılık dosyaları otomatik eklenmez: scripts/core-closure.py ile kapanışı yeniden hesaplayın, testleri çalıştırın.');

            return self::SUCCESS;
        }
        if ($localDrift === 0 && $upstreamDrift === 0) {
            $this->info('Çekirdek kopyası kaynakla aynı.');

            return self::SUCCESS;
        }
        $this->error("Kayma: yerel {$localDrift}, kaynak {$upstreamDrift}.");

        return self::FAILURE;
    }

    /** @param list<string> $files */
    private function writeManifest(string $path, array $files, string $source): void
    {
        sort($files);
        $hashes = [];
        foreach ($files as $f) {
            $hashes[$f] = hash_file('sha256', base_path($f));
        }
        $commit = is_dir($source.'/.git') ? trim((string) shell_exec('git -C '.escapeshellarg($source).' rev-parse HEAD 2>/dev/null')) : '';
        file_put_contents($path, json_encode([
            'description' => 'Ana API (otosanayide-api) alan cekirdeginin birebir kopyasi. Elle degistirilmez; panel:core-check.',
            'sourceRepository' => 'otosanayide-api',
            'sourceCommit' => $commit !== '' ? $commit : null,
            'generatedAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'files' => $hashes,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->info('core-manifest.json yazıldı ('.count($hashes).' dosya).');
    }
}
