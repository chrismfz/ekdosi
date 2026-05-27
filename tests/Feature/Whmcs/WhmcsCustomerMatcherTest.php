<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the 4-tier customer match strategy:
 *   1. Direct customers.whmcs_client_id link (operator-set)
 *   2. AFM exact match (with normalisation: "EL123456789" → "123456789")
 *   3. Email exact match (case-insensitive)
 *   4. Company / fullname exact match (case-insensitive)
 *
 * Crucially: the matcher NEVER auto-writes whmcs_client_id. Linking
 * is an operator decision (different B2B Acme Ltd's exist in
 * different countries — name matches could be false positives).
 */
class WhmcsCustomerMatcherTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Tenant',
            'slug' => 'whmcs-match-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['vatno' => 13],
        ]);
    }

    public function test_direct_link_takes_precedence_over_other_signals(): void
    {
        // Two customers — one linked by id, one matching by AFM. The
        // linked one MUST win even if both have the same vatno match.
        $linked = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Linked',
            'afm' => '123456789',
            'whmcs_client_id' => 42,
        ]);
        $other = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'AfmOnly',
            'afm' => '123456789',  // same afm — must NOT be picked
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 42,
            'customfields' => [['id' => 13, 'value' => '123456789']],
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame($linked->id, $match->customer->id);
        $this->assertSame('linked', $match->reason);
    }

    public function test_afm_match_handles_greek_prefix(): void
    {
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'AcmeGreek',
            'afm' => '123456789',  // stored without EL prefix
        ]);

        // WHMCS-side value has EL prefix; matcher must normalise.
        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 99,
            'customfields' => [['id' => 13, 'value' => 'EL123456789']],
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame('AcmeGreek', $match->customer->name);
        $this->assertSame('afm', $match->reason);
    }

    public function test_email_match_case_insensitive(): void
    {
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'EmailGuy',
            'email' => 'someone@example.com',
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 50,
            'email' => 'SOMEONE@example.com',  // different case
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame('EmailGuy', $match->customer->name);
        $this->assertSame('email', $match->reason);
    }

    public function test_name_match_falls_back_to_firstname_lastname_concat(): void
    {
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'John Doe',
        ]);

        // WHMCS has no companyname (B2C client); falls back to
        // firstname + lastname concatenation.
        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 60,
            'firstname' => 'John',
            'lastname' => 'Doe',
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame('name', $match->reason);
    }

    public function test_name_match_uses_companyname_when_present(): void
    {
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Acme Ltd',
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 70,
            'firstname' => 'John',  // not used — companyname wins
            'lastname' => 'Doe',
            'companyname' => 'Acme Ltd',
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame('name', $match->reason);
    }

    public function test_unmatched_returns_null_customer_with_unmatched_reason(): void
    {
        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 999,
            'email' => 'nobody@example.com',
            'companyname' => 'Nonexistent Ltd',
            'customfields' => [['id' => 13, 'value' => 'EL999999999']],
        ]);

        $this->assertFalse($match->isMatched());
        $this->assertNull($match->customer);
        $this->assertSame('unmatched', $match->reason);
    }

    public function test_tenant_scope_isolation(): void
    {
        // Another tenant has a customer with the same AFM. Matcher
        // must NOT cross the tenant boundary.
        $otherTenant = Company::create([
            'name' => 'Other tenant',
            'slug' => 'whmcs-match-other-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
        Customer::create([
            'company_id' => $otherTenant->id,
            'name' => 'Wrong tenant',
            'afm' => '123456789',
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 0,
            'customfields' => [['id' => 13, 'value' => '123456789']],
        ]);

        $this->assertFalse($match->isMatched(),
            'AFM match must be scoped to the tenant — other-tenant customer must NOT be returned.');
    }

    public function test_custom_field_map_role_not_set_skips_afm_lookup(): void
    {
        // Tenant doesn't have vatno mapped — matcher must not crash,
        // just skip the AFM tier and fall through.
        $tenantNoMap = Company::create([
            'name' => 'No map',
            'slug' => 'whmcs-nomap-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_custom_field_map' => [],
        ]);
        Customer::create([
            'company_id' => $tenantNoMap->id,
            'name' => 'X',
            'afm' => '123',
            'email' => 'x@y.com',
        ]);

        // WHMCS has a customfield at id 13 carrying an AFM, but the
        // tenant hasn't told us which fieldid is vatno. Email match
        // should still work.
        $match = app(WhmcsCustomerMatcher::class)->match($tenantNoMap, [
            'id' => 0,
            'customfields' => [['id' => 13, 'value' => '123']],
            'email' => 'x@y.com',
        ]);

        $this->assertSame('email', $match->reason);
    }
}
