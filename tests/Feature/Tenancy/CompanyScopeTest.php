<?php

namespace Tests\Feature\Tenancy;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Supplier;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The CompanyScope global scope: tenant-owned models auto-filter by the
 * ambient CompanyContext when set, are a no-op when it isn't (so the existing
 * explicit-scoped CLI paths are unaffected), and can be opted out per query.
 */
class CompanyScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Company::create(['name' => 'A', 'slug' => 'a-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        $this->b = Company::create(['name' => 'B', 'slug' => 'b-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);

        Customer::create(['company_id' => $this->a->id, 'name' => 'Cust A1']);
        Customer::create(['company_id' => $this->a->id, 'name' => 'Cust A2']);
        Customer::create(['company_id' => $this->b->id, 'name' => 'Cust B1']);

        // Reset ambient context between tests (singleton persists in-process).
        app(CompanyContext::class)->clear();
    }

    protected function tearDown(): void
    {
        app(CompanyContext::class)->clear();
        parent::tearDown();
    }

    public function test_no_context_is_a_noop_sees_all(): void
    {
        $this->assertSame(3, Customer::count(), 'no ambient tenant → scope is a no-op');
    }

    public function test_context_filters_to_the_set_company(): void
    {
        app(CompanyContext::class)->set($this->a);
        $this->assertSame(2, Customer::count());
        $this->assertEqualsCanonicalizing(['Cust A1', 'Cust A2'], Customer::pluck('name')->all());

        app(CompanyContext::class)->set($this->b);
        $this->assertSame(1, Customer::count());
        $this->assertSame('Cust B1', Customer::first()->name);
    }

    public function test_actAs_scopes_inside_and_restores_after(): void
    {
        app(CompanyContext::class)->set($this->b);

        $namesInA = app(CompanyContext::class)->actAs($this->a, fn () => Customer::pluck('name')->all());
        $this->assertEqualsCanonicalizing(['Cust A1', 'Cust A2'], $namesInA);

        // Restored to B afterwards.
        $this->assertSame(1, Customer::count());
        $this->assertSame($this->b->id, app(CompanyContext::class)->id());
    }

    public function test_without_global_scope_bypasses_the_filter(): void
    {
        app(CompanyContext::class)->set($this->a);

        // Both the singular and plural forms used across the codebase work.
        $this->assertSame(
            3,
            Customer::withoutGlobalScope(\App\Models\Scopes\CompanyScope::class)->count(),
        );
        $this->assertSame(3, Customer::withoutGlobalScopes()->count());
    }

    public function test_actAs_restores_to_null_context(): void
    {
        // Prior context is null → actAs must restore it to null, not leak A.
        $this->assertNull(app(CompanyContext::class)->id());

        $count = app(CompanyContext::class)->actAs($this->a, fn () => Customer::count());
        $this->assertSame(2, $count);

        $this->assertNull(app(CompanyContext::class)->id(), 'context restored to null');
        $this->assertSame(3, Customer::count(), 'no-op again after actAs');
    }

    public function test_scope_applies_across_models_and_survives_creates_under_context(): void
    {
        app(CompanyContext::class)->set($this->a);

        // A create that sets company_id explicitly to B is still WRITABLE
        // (scope is read-only) but won't be visible under context A.
        Supplier::create(['company_id' => $this->b->id, 'afm' => '111', 'name' => 'Sup B', 'source' => 'manual']);
        Supplier::create(['company_id' => $this->a->id, 'afm' => '222', 'name' => 'Sup A', 'source' => 'manual']);

        $this->assertSame(1, Supplier::count(), 'only company A supplier visible under context A');
        $this->assertSame('Sup A', Supplier::first()->name);
    }
}
