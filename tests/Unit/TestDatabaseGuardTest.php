<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;
use Tests\TestCase;

class TestDatabaseGuardTest extends PhpUnitTestCase
{
    public static function unsafeDatabases(): array
    {
        return [
            'gelistirme veritabani' => ['mysql', 'medikal_stok'],
            'mariadb gelistirme' => ['mariadb', 'medikal_stok'],
            'ad bos' => ['mysql', null],
            'sonek ortada' => ['mysql', 'medikal_stok_test_backup'],
        ];
    }

    public static function safeDatabases(): array
    {
        return [
            'ayri mysql test veritabani' => ['mysql', 'medikal_stok_hr_test'],
            'bellek ici sqlite' => ['sqlite', ':memory:'],
        ];
    }

    #[DataProvider('unsafeDatabases')]
    public function test_unsafe_database_is_rejected(string $driver, ?string $database): void
    {
        $this->expectException(RuntimeException::class);

        TestCase::assertSafeTestDatabase($driver, $database);
    }

    #[DataProvider('safeDatabases')]
    public function test_safe_database_is_allowed(string $driver, ?string $database): void
    {
        TestCase::assertSafeTestDatabase($driver, $database);

        $this->addToAssertionCount(1);
    }
}
