<?php

namespace Tests;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
