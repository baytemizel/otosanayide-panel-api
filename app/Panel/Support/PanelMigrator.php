<?php

namespace App\Panel\Support;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Panel migration'lari yalniz panel veritabaninda calisir. `--database` verilmezse `panel` kullanilir (defter
 * `panel_migrations` da orada); ana veritabani baglantisi (`mysql`) reddedilir. Varsayilan baglanti ana
 * veritabani oldugu icin bu olmadan `php artisan migrate` panel tablolarini ana veritabanina kurardi.
 */
final class PanelMigrator extends Migrator
{
    public const CONNECTIONS = [PanelDb::CONNECTION, 'panel_migrator'];

    public static function assertPanelConnection(?string $name): string
    {
        $name ??= PanelDb::CONNECTION;
        if (! in_array(Str::before($name, '::'), self::CONNECTIONS, true)) {
            throw new InvalidArgumentException("Panel migration'ları yalnız panel veritabanında çalışır: --database=panel veya --database=panel_migrator.");
        }

        return $name;
    }

    /** @param  string|null  $name */
    public function setConnection($name)
    {
        parent::setConnection(self::assertPanelConnection($name));
    }
}
