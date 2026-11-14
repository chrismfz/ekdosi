<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;
use Tests\TestCase;

/**
 * The suite must never run against a real database (a cached config made it wipe
 * the devbox DB, 2026-11-14). Pure-unit: no app, no DB — so the guard can't break
 * silently without this failing.
 */
class TestDatabaseGuardTest extends PhpUnitTestCase
{
    public static function safe(): array
    {
        return [
            'sqlite in memory' => ['sqlite', ':memory:'],
            'mariadb *_test' => ['mariadb', 'ekdosi_test'],
            'sqlite *_test file' => ['sqlite', '/tmp/ekdosi_test.sqlite'],
        ];
    }

    public static function unsafe(): array
    {
        return [
            'the app mariadb db' => ['mariadb', 'ekdosi'],
            'a prod-like name' => ['mysql', 'myipgr_invoicer'],
            'an app sqlite file' => ['sqlite', '/var/www/app/database/database.sqlite'],
            'empty name' => ['mariadb', ''],
            '_test only in the middle' => ['mariadb', 'ekdosi_test_backup'],
        ];
    }

    #[DataProvider('safe')]
    public function test_test_databases_are_allowed(string $driver, string $database): void
    {
        TestCase::assertSafeTestDatabase($driver, $database);
        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafe')]
    public function test_real_databases_are_refused(string $driver, string $database): void
    {
        $this->expectException(RuntimeException::class);
        TestCase::assertSafeTestDatabase($driver, $database);
    }
}
