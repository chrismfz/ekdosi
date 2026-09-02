<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the ΑΦΜ-ONLY customer match strategy:
 *   1. Direct customers.whmcs_client_id link (operator-set)
 *   2. AFM exact match (with normalisation: "EL123456789" → "123456789")
 *   …otherwise UNMATCHED. Email and name matching were REMOVED (false
 *   matches pre-filled the wrong customer). The matcher NEVER auto-writes
 *   whmcs_client_id — linking is an operator decision.
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
        // Two customers — one linked by id, the OTHER matching the payload's
        // vatno. The linked one MUST win over the AFM signal. (One ΑΦΜ per
        // tenant is now DB-enforced, so the two carry different ΑΦΜ.)
        $linked = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Linked',
            'afm' => '987654321',
            'whmcs_client_id' => 42,
        ]);
        $other = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'AfmOnly',
            'afm' => '123456789',  // matches the payload vatno — must NOT be picked
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

    public function test_email_does_NOT_match_afm_only(): void
    {
        // A customer with the same email exists — but email matching was
        // removed (shared/role emails caused wrong-customer pre-fill).
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'EmailGuy',
            'email' => 'someone@example.com',
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 50,
            'email' => 'someone@example.com',   // exact email, no ΑΦΜ
        ]);

        $this->assertFalse($match->isMatched());
        $this->assertSame('unmatched', $match->reason);
    }

    public function test_name_does_NOT_match_afm_only(): void
    {
        // Same company name exists — but name matching was removed (common
        // names across entities caused false matches).
        Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Acme Ltd',
        ]);

        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id' => 70,
            'firstname' => 'John',
            'lastname' => 'Doe',
            'companyname' => 'Acme Ltd',   // exact name, no ΑΦΜ
        ]);

        $this->assertFalse($match->isMatched());
        $this->assertSame('unmatched', $match->reason);
    }

    /**
     * Raw GetInvoices rows have `id` = INVOICE id and `userid` = CLIENT
     * id (verified at vendor WHMCS API docs). Matcher must prefer
     * userid for the direct-link tier — otherwise it would look up
     * customers.whmcs_client_id = <invoice id> and miss every match.
     * Pre-PR-28-review: this was reversed and only worked because the
     * dry-run command pre-massaged the payload.
     */
    public function test_direct_link_works_on_raw_get_invoices_row(): void
    {
        $linked = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Linked',
            'whmcs_client_id' => 555,
        ]);

        // Shape of a real WHMCS GetInvoices row: `id` is the invoice
        // id (9876), `userid` is the client id (555). The matcher
        // must follow `userid`, not `id`.
        $match = app(WhmcsCustomerMatcher::class)->match($this->tenant, [
            'id'     => 9876,   // invoice id — NOT the client id
            'userid' => 555,    // actual client id
        ]);

        $this->assertTrue($match->isMatched());
        $this->assertSame($linked->id, $match->customer->id);
        $this->assertSame('linked', $match->reason);
        $this->assertSame(555, $match->whmcsClientId);
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
        // Tenant doesn't have vatno mapped — matcher must not crash, just skip
        // the AFM tier. With ΑΦΜ-only matching there's no email fallback, so
        // the result is UNMATCHED (the operator links / creates the customer).
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

        $match = app(WhmcsCustomerMatcher::class)->match($tenantNoMap, [
            'id' => 0,
            'customfields' => [['id' => 13, 'value' => '123']],
            'email' => 'x@y.com',
        ]);

        $this->assertSame('unmatched', $match->reason);
    }
}
