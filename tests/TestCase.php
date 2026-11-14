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
     * That happened on the devbox (2026-11-14). Only sqlite or a `*_test` database is
     * allowed; anything else aborts BEFORE a single table is dropped.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $driver = config("database.connections.{$connection}.driver");
        $database = (string) config("database.connections.{$connection}.database");

        if ($driver !== 'sqlite' && ! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against database «{$database}» ({$driver}): only sqlite or a *_test database. "
                .'A cached config ignores .env.testing — run `php artisan optimize:clear` first.'
            );
        }

        return parent::setUpTraits();
    }
}
