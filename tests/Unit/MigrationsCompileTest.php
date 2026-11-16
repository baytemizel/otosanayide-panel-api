<?php

use App\Panel\Support\PanelDb;
use App\Panel\Support\PanelMigrationRepository;
use App\Panel\Support\PanelMigrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Baglanti kurmadan: migration'lar panel baglantisinda MySQL DDL'ine derlenir (pretend, sahte PDO). Migrator gibi
// varsayilan baglanti calisma suresince `panel` yapilir. Gercek motor kabulu DEGILDIR.
function panelCompiledSql(): array
{
    DB::connection(PanelDb::CONNECTION)->setPdo(new class extends PDO
    {
        public function __construct() {}

        public function getAttribute(int $attribute): mixed
        {
            return '8.4.13';
        }
    });
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection(PanelDb::CONNECTION);
    $sql = [];
    try {
        foreach (collect(File::files(database_path('migrations')))->sortBy->getFilename() as $file) {
            $migration = require $file->getPathname();
            foreach (DB::connection(PanelDb::CONNECTION)->pretend(fn () => $migration->up()) as $entry) {
                $sql[$file->getFilename()][] = $entry['query'];
            }
        }
    } finally {
        DB::setDefaultConnection($previous);
    }

    return $sql;
}

it('compiles every panel migration without a database connection', function () {
    $all = panelCompiledSql();
    expect($all)->toHaveCount(4);
    foreach ($all as $statements) {
        expect($statements)->not->toBeEmpty();
    }
});

it('creates only panel_ prefixed tables and never alters main API tables', function () {
    $sql = implode("\n", array_merge(...array_values(panelCompiledSql())));
    preg_match_all('/create table `([a-z0-9_]+)`/', $sql, $created);
    expect($created[1])->toHaveCount(22);
    foreach ($created[1] as $table) {
        expect($table)->toStartWith('panel_');
    }
    preg_match_all('/alter table `([a-z0-9_]+)`/', $sql, $altered);
    foreach ($altered[1] as $table) {
        expect($table)->toStartWith('panel_');
    }
    expect($sql)->not->toContain('drop table');
});

it('uses InnoDB, the Turkish collation and the main API identity column format', function () {
    foreach (panelCompiledSql() as $statements) {
        foreach ($statements as $sql) {
            if (str_starts_with($sql, 'create table')) {
                expect($sql)->toContain('engine = InnoDB')->toContain('utf8mb4_tr_0900_ai_ci');
            }
        }
    }
    $access = implode("\n", panelCompiledSql()['2026_11_16_100001_create_panel_access_tables.php']);
    expect($access)->toContain("`account_id` varchar(128) character set utf8mb4 collate 'utf8mb4_bin'");
});

it('has no foreign key into the main database', function () {
    $sql = implode("\n", array_merge(...array_values(panelCompiledSql())));
    preg_match_all('/references `([a-z0-9_]+)`/', $sql, $referenced);
    expect($referenced[1])->not->toBeEmpty();
    foreach ($referenced[1] as $table) {
        expect($table)->toStartWith('panel_');
    }
});

it('stores a request id as long as SecurityHeaders accepts (64)', function () {
    $sql = implode("\n", panelCompiledSql()['2026_11_16_100002_create_panel_operation_tables.php']);
    expect($sql)->toContain('`request_id` varchar(64)');
});

it('keeps the panel migration ledger separate from the main API', function () {
    expect(config('database.migrations.table'))->toBe('panel_migrations');
});

it('runs migrations and the ledger on the panel database by default', function () {
    $migrator = app('migrator');
    expect($migrator)->toBeInstanceOf(PanelMigrator::class)
        ->and(app('migration.repository'))->toBeInstanceOf(PanelMigrationRepository::class);

    $default = DB::getDefaultConnection();
    $seen = $migrator->usingConnection(null, fn () => [$migrator->getConnection(), DB::getDefaultConnection(), $migrator->getRepository()->getConnection()->getName()]);
    expect($seen)->toBe(['panel', 'panel', 'panel'])
        ->and(DB::getDefaultConnection())->toBe($default);

    expect($migrator->usingConnection('panel_migrator', fn () => $migrator->getConnection()))->toBe('panel_migrator');
});

it('refuses to run panel migrations on the main database', function (string $connection) {
    expect(fn () => app('migrator')->usingConnection($connection, fn () => null))->toThrow(InvalidArgumentException::class);
    expect(fn () => app('migration.repository')->setSource($connection))->toThrow(InvalidArgumentException::class);
})->with(['mysql', 'sqlite']);

it('points the two connections at different databases', function () {
    expect(config('database.default'))->toBe('mysql')
        ->and(config('database.connections.panel.database'))->toBe('offline_no_panel_database')
        ->and(config('database.connections.panel.database'))->not->toBe(config('database.connections.mysql.database'))
        ->and(config('database.connections.panel_migrator.database'))->toBe(config('database.connections.panel.database'));
});
