<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Kullanici talimati: veritabani baglantisi kurulmaz. Paket yalniz DB_PORT=1 ile baslar.
        if ((string) config('database.connections.mysql.port') !== '1') {
            throw new \RuntimeException('Testler veritabanina baglanmaz: DB_PORT=1 olmali.');
        }
    }
}
