<?php

namespace App\Providers;

use App\Panel\Support\PanelDb;
use App\Panel\Support\PanelMigrationRepository;
use App\Panel\Support\PanelMigrator;
use Illuminate\Database\Console\Migrations\FreshCommand;
use Illuminate\Database\Console\WipeCommand;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Migration'lar ve defterleri yalniz panel veritabanina gider (varsayilan baglanti ana veritabanidir).
        $this->app->extend('migration.repository', static function (mixed $repository, $app): PanelMigrationRepository {
            $panel = new PanelMigrationRepository($app['db'], (string) $app['config']['database.migrations.table']);
            $panel->setSource(PanelDb::CONNECTION);

            return $panel;
        });
        $this->app->extend('migrator', static fn (mixed $migrator, $app): PanelMigrator => new PanelMigrator($app['migration.repository'], $app['db'], $app['files'], $app['events']));
    }

    public function boot(): void
    {
        // db:wipe varsayilan baglantiyi, yani ana Otosanayide veritabanini bosaltir; migrate:fresh onu cagirir.
        // Panelde hic gerekmez: panel tablolari migrate:rollback / migrate:reset ile (yalniz panel veritabaninda) geri alinir.
        WipeCommand::prohibit();
        FreshCommand::prohibit();

        // Ana API ile ayni kolon bicimleri: kimlikler buyuk/kucuk harf duyarli, zamanlar milisaniyeli.
        Blueprint::macro('cid', function (string $column, int $length = 128): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, $length)->charset('utf8mb4')->collation('utf8mb4_bin');
        });
        Blueprint::macro('ms', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->dateTime($column, 3);
        });
        Blueprint::macro('sha256', function (string $column): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->char($column, 64)->charset('ascii')->collation('ascii_bin');
        });
        Blueprint::macro('ascii', function (string $column, int $length = 36): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, $length)->charset('ascii')->collation('ascii_bin');
        });
    }
}
