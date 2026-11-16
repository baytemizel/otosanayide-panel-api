<?php

namespace App\Panel\Support;

use App\Support\Tx;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Panel veritabani (`panel` baglantisi). Iki veritabani vardir:
 *  - ana Otosanayide veritabani: varsayilan baglanti (`DB::table`, `App\Support\Tx`); alan cekirdegi buraya yazar;
 *  - panel veritabani: yalniz `panel_*` tablolari; her erisim bu siniftan gecer.
 *
 * Kurallar: iki veritabani arasinda join, yabanci anahtar ve tek transaction yoktur. Iki tarafa yazan islemde
 * panel yazimlari once `PanelDb::tx` icinde yapilir, ana veritabani yazimi (`Tx::run`) o transaction'in son
 * adimidir: ana yazim basarisiz olursa panel yazimi geri alinir; ana yazim tamamlandiktan sonra panel
 * transaction'i baska sorgu calistirmadan kapanir.
 */
final class PanelDb
{
    public const CONNECTION = 'panel';

    public static function connection(): Connection
    {
        return DB::connection(self::CONNECTION);
    }

    public static function table(string $table): Builder
    {
        return self::connection()->table($table);
    }

    /**
     * @template T
     *
     * @param  Closure():T  $work
     * @return T
     */
    public static function tx(Closure $work): mixed
    {
        return self::connection()->transaction($work, Tx::ATTEMPTS);
    }
}
