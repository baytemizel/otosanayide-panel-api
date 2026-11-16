<?php

use Illuminate\Support\Facades\File;

// Iki veritabani: ana Otosanayide (varsayilan baglanti) ve panel (`panel`). Kaynak kod taranir; baglanti kurulmaz.

/** @return array<string,string> yol => icerik (panel kodu ve seeder'lar; vendor edilen cekirdek haric) */
function panelSources(): array
{
    $out = [];
    foreach (['app/Panel', 'app/Http', 'app/Console', 'app/Providers', 'database/seeders', 'routes'] as $dir) {
        foreach (File::allFiles(base_path($dir)) as $file) {
            if ($file->getExtension() === 'php') {
                $out[$dir.'/'.$file->getRelativePathname()] = $file->getContents();
            }
        }
    }

    return $out;
}

/**
 * Sorgu zinciri: baslangic satiri ve `->` ile baslayan devam satirlari; `;` ya da yeni bir sorgu baslangicinda biter.
 *
 * @return list<array{0:string,1:list<string>}> zincir ve icindeki tablo adlari
 */
function queryChains(string $code, string $start): array
{
    $chains = [];
    $offset = 0;
    while (($pos = strpos($code, $start, $offset)) !== false) {
        $lines = explode("\n", substr($code, $pos));
        $chain = array_shift($lines);
        foreach ($lines as $line) {
            if (! str_starts_with(ltrim($line), '->')) {
                break;
            }
            $chain .= "\n".$line;
        }
        $chain = strtok($chain, ';');
        $next = preg_match('/(?<!Panel)DB::table\(|PanelDb::table\(/', $chain, $m, PREG_OFFSET_CAPTURE, strlen($start));
        if ($next === 1) {
            $chain = substr($chain, 0, $m[0][1]);
        }
        preg_match_all("/(?:table|join|leftJoin|rightJoin|from)\\('([a-z_]+)/", $chain, $m);
        $chains[] = [$chain, $m[1]];
        $offset = $pos + strlen($start);
    }

    return $chains;
}

it('reaches panel tables only through the panel connection', function () {
    foreach (panelSources() as $path => $code) {
        expect($code)->not->toContain("DB::table('panel_", $path);
        foreach (queryChains($code, 'PanelDb::table(') as [$chain, $tables]) {
            foreach ($tables as $table) {
                expect($table)->toStartWith('panel_', $path.': '.mb_substr($chain, 0, 160));
            }
        }
    }
});

it('never joins a main database query with a panel table', function () {
    foreach (panelSources() as $path => $code) {
        foreach (queryChains(str_replace('PanelDb::table(', 'PanelDb::panelTable(', $code), 'DB::table(') as [$chain, $tables]) {
            foreach ($tables as $table) {
                expect(str_starts_with($table, 'panel_'))->toBeFalse($path.': '.mb_substr($chain, 0, 160));
            }
        }
    }
});

it('prohibits db:wipe and migrate:fresh because the default connection is the main database', function () {
    $this->artisan('db:wipe', ['--force' => true])->assertFailed();
    $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
});
