<?php

use Illuminate\Support\Facades\Schedule;

// Panel zamanlanmis isleri varsayilan kapali (ana API kurali). Acmadan once ayni komutun kuru calismasi incelenir.
// Sunucu cron'u: * * * * * php artisan schedule:run (bkz. docs/DEPLOY.md).

if (config('panel.workers.housekeeping')) {
    Schedule::command('panel:housekeeping --apply')->hourly()->withoutOverlapping(55)->onOneServer();
}

if (config('panel.workers.work_items_sync')) {
    Schedule::command('panel:work-items-sync --apply')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
}

if (config('panel.workers.stats_snapshot')) {
    Schedule::command('panel:stats-snapshot --apply')->dailyAt('03:15')->timezone('Europe/Istanbul')->withoutOverlapping(60)->onOneServer();
}
