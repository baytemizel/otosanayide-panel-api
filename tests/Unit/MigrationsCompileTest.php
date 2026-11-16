<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

// Baglanti kurmadan: migration'lar MySQL DDL'ine derlenir (pretend, sahte PDO). Gercek motor kabulu DEGILDIR.
function panelCompiledSql(): array
{
    DB::connection()->setPdo(new class extends PDO
    {
        public function __construct() {}

        public function getAttribute(int $attribute): mixed
        {
            return '8.4.13';
        }
    });
    $sql = [];
    foreach (collect(File::files(database_path('migrations')))->sortBy->getFilename() as $file) {
        $migration = require $file->getPathname();
        foreach (DB::connection()->pretend(fn () => $migration->up()) as $entry) {
            $sql[$file->getFilename()][] = $entry['query'];
        }
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
    expect($access)->toContain("`account_id` varchar(128) character set utf8mb4 collate 'utf8mb4_bin'")
        ->toContain('references `auth_accounts` (`id`) on delete cascade');
});

it('stores a request id as long as SecurityHeaders accepts (64)', function () {
    $sql = implode("\n", panelCompiledSql()['2026_11_16_100002_create_panel_operation_tables.php']);
    expect($sql)->toContain('`request_id` varchar(64)');
});

it('keeps the panel migration ledger separate from the main API', function () {
    expect(config('database.migrations.table'))->toBe('panel_migrations');
});
