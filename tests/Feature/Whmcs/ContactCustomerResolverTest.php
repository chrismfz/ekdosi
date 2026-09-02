<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Whmcs\ContactCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T-1b: find-or-create an ekdosi Customer from a resolved third-party contact.
 */
class ContactCustomerResolverTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'ccr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function resolver(): ContactCustomerResolver
    {
        return app(ContactCustomerResolver::class);
    }

    public function test_creates_customer_decoding_entities_and_normalising_afm(): void
    {
        $tenant = $this->tenant();
        $customer = $this->resolver()->resolve($tenant, [
            'id' => 1,
            'company_name' => 'Σ&amp;Φ Τσιρώνη ΟΕ',
            'gr_vatno' => 'EL081951154',
            'tax_office' => 'Ηγουμενίτσας',
            'address1' => 'Γαρδίκι',
            'city' => 'Σούλι',
            'description' => 'Ξυλογλυπτική',
        ]);

        $this->assertNotNull($customer);
        $this->assertSame('Σ&Φ Τσιρώνη ΟΕ', $customer->name, 'HTML entities decoded');
        $this->assertSame('081951154', $customer->afm, 'EL prefix stripped');
        $this->assertSame('Ηγουμενίτσας', $customer->tax_office);
        $this->assertSame($tenant->id, $customer->company_id);
    }

    public function test_is_idempotent_by_afm(): void
    {
        $tenant = $this->tenant();
        $contact = ['id' => 1, 'company_name' => 'Acme', 'gr_vatno' => '123456789'];

        $a = $this->resolver()->resolve($tenant, $contact);
        $b = $this->resolver()->resolve($tenant, $contact + ['company_name' => 'Acme renamed']);

        $this->assertSame($a->id, $b->id, 'second resolve returns the same customer');
        $this->assertSame(1, Customer::where('company_id', $tenant->id)->count());
    }

    public function test_matches_existing_customer_by_afm(): void
    {
        $tenant = $this->tenant();
        $existing = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Pre-existing', 'afm' => '999888777',
        ]);

        $resolved = $this->resolver()->resolve($tenant, [
            'id' => 7, 'company_name' => 'From WHMCS', 'gr_vatno' => '999888777',
        ]);

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame('Pre-existing', $resolved->name, 'does not clobber the existing customer name');
    }

    public function test_deleted_owner_raises_a_greek_message_instead_of_a_unique_error(): void
    {
        $tenant = $this->tenant();
        Customer::create(['company_id' => $tenant->id, 'name' => 'Σβησμένος', 'afm' => '111222333'])->delete();

        try {
            $this->resolver()->resolve($tenant, ['gr_vatno' => 'EL 111222333', 'company_name' => 'Acme']);
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ΔΙΑΓΡΑΜΜΕΝΟΣ', $e->getMessage());
        }

        $this->assertSame(1, Customer::withTrashed()->where('company_id', $tenant->id)->count());
    }

    public function test_returns_null_when_contact_has_no_afm(): void
    {
        $tenant = $this->tenant();
        $this->assertNull($this->resolver()->resolve($tenant, [
            'id' => 1, 'company_name' => 'No tax id', 'gr_vatno' => '',
        ]));
        $this->assertSame(0, Customer::where('company_id', $tenant->id)->count());
    }

    public function test_afm_match_is_tenant_scoped(): void
    {
        $a = $this->tenant();
        $b = $this->tenant();
        Customer::create(['company_id' => $a->id, 'name' => 'A-side', 'afm' => '111222333']);

        $resolved = $this->resolver()->resolve($b, [
            'id' => 1, 'company_name' => 'B-side', 'gr_vatno' => '111222333',
        ]);

        $this->assertSame($b->id, $resolved->company_id, 'creates in tenant B, ignoring tenant A match');
        $this->assertSame('B-side', $resolved->name);
    }
}
