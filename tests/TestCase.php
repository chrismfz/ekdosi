<?php

namespace Tests;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The CompanyContext (BelongsToCompany global scope) is a singleton.
        // Reset it per test so a tenant set by one test can't bleed into the
        // next and silently filter queries. Process-wide infrastructure, not a
        // per-test responsibility.
        app(CompanyContext::class)->clear();
    }

    /**
     * Refuse to touch a real database. RefreshDatabase (run from setUpTraits) does
     * `migrate:fresh` — on a host where `config:cache` is live (every deploy runs it),
     * Laravel ignores phpunit.xml/.env.testing and the suite would wipe the APP's DB.
     * That happened on the devbox (2026-11-14). Only an in-memory sqlite or a `*_test`
     * database is allowed; anything else aborts BEFORE a single table is dropped.
     */
    protected function setUpTraits()
    {
        $connection = (string) config('database.default');
        self::assertSafeTestDatabase(
            (string) config("database.connections.{$connection}.driver"),
            (string) config("database.connections.{$connection}.database"),
        );

        return parent::setUpTraits();
    }

    /**
     * The guard itself, public + static so it is unit-testable without a database.
     * sqlite only as `:memory:` (or a file named *_test / *_test.sqlite) — a plain
     * sqlite FILE may be an app database; any other driver needs a `*_test` name.
     */
    public static function assertSafeTestDatabase(string $driver, string $database): void
    {
        $name = pathinfo($database, PATHINFO_FILENAME);
        $safe = ($driver === 'sqlite' && $database === ':memory:') || str_ends_with($name, '_test');

        if (! $safe) {
            throw new RuntimeException(
                "Refusing to run tests against database «{$database}» ({$driver}): only in-memory sqlite or a *_test database. "
                .'A cached config ignores .env.testing — run `php artisan optimize:clear` first.'
            );
        }
    }
}
