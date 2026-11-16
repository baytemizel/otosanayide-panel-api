<?php

namespace App\Providers;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
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
