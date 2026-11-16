<?php

namespace App\Panel\Support;

use Illuminate\Database\Migrations\DatabaseMigrationRepository;

/** `panel_migrations` defteri her zaman panel veritabanindadir (`migrate:install` dahil). */
final class PanelMigrationRepository extends DatabaseMigrationRepository
{
    /** @param  string|null  $name */
    public function setSource($name)
    {
        parent::setSource(PanelMigrator::assertPanelConnection($name));
    }
}
