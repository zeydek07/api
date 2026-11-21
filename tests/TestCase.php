<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['db']->connection();
        self::assertSafeTestDatabase($connection->getDriverName(), $connection->getDatabaseName());

        return $app;
    }

    /** RefreshDatabase tüm tabloları siler; testler geliştirme veritabanında asla çalışmamalı. */
    public static function assertSafeTestDatabase(string $driver, ?string $database): void
    {
        if (in_array($driver, ['mysql', 'mariadb'], true) && ! str_ends_with((string) $database, '_test')) {
            throw new RuntimeException(
                "Testler durduruldu: '{$database}' bir test veritabanı değil (adı _test ile bitmeli)."
            );
        }
    }
}
