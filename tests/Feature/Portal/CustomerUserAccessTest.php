<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The portal ACCESS GRANTS model — the «ποιο login βλέπει ποιον πελάτη» bridge.
 * Nothing customer-facing reads it yet; this locks the data model + relations
 * the later documents-view slice will depend on.
 */
class CustomerUserAccessTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'cua-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function grant(CustomerUser $login, Company $company, Customer $customer, string $role = CustomerUserAccess::ROLE_OWNER): CustomerUserAccess
    {
        return CustomerUserAccess::create([
            'customer_user_id' => $login->id,
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'role' => $role,
            'granted_at' => now(),
        ]);
    }

    public function test_a_login_can_hold_grants_across_companies(): void
    {
        $login = CustomerUser::factory()->create();
        $c1 = $this->company();
        $c2 = $this->company();
        $cust1 = Customer::create(['company_id' => $c1->id, 'name' => 'A', 'afm' => '090000045']);
        $cust2 = Customer::create(['company_id' => $c2->id, 'name' => 'B', 'afm' => '090000045']);

        $this->grant($login, $c1, $cust1);
        $this->grant($login, $c2, $cust2, CustomerUserAccess::ROLE_RESELLER);

        $this->assertCount(2, $login->accessGrants()->get());
        $this->assertEqualsCanonicalizing(
            [$c1->id, $c2->id],
            $login->accessGrants()->pluck('company_id')->all(),
        );
    }

    public function test_active_scope_excludes_revoked_grants(): void
    {
        $login = CustomerUser::factory()->create();
        $company = $this->company();
        $live = Customer::create(['company_id' => $company->id, 'name' => 'Live', 'afm' => '1']);
        $gone = Customer::create(['company_id' => $company->id, 'name' => 'Gone', 'afm' => '2']);

        $this->grant($login, $company, $live);
        $revoked = $this->grant($login, $company, $gone);
        $revoked->forceFill(['revoked_at' => now()])->save();

        $this->assertCount(2, $login->accessGrants()->get());
        $this->assertCount(1, $login->activeAccessGrants()->get());
        $this->assertSame($live->id, $login->activeAccessGrants()->first()->customer_id);
    }

    public function test_a_login_cannot_hold_two_grants_for_the_same_customer(): void
    {
        $login = CustomerUser::factory()->create();
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'C', 'afm' => '3']);

        $this->grant($login, $company, $customer);

        $this->expectException(QueryException::class);   // unique(login, company, customer)
        $this->grant($login, $company, $customer);
    }

    public function test_customer_relation_resolves_cross_company_despite_ambient_tenant_scope(): void
    {
        // A grant is inherently cross-company; the customer must resolve even when
        // the panel's active tenant (ambient CompanyScope) is a DIFFERENT company.
        $login = CustomerUser::factory()->create();
        $companyA = $this->company();
        $companyB = $this->company();
        $custB = Customer::create(['company_id' => $companyB->id, 'name' => 'CrossCo', 'afm' => '999']);
        $grant = $this->grant($login, $companyB, $custB);

        app(CompanyContext::class)->actAs($companyA, function () use ($grant): void {
            $fresh = CustomerUserAccess::find($grant->id);
            $this->assertNotNull($fresh->customer, 'cross-company customer must resolve despite CompanyScope');
            $this->assertSame('CrossCo', $fresh->customer->name);
        });
    }

    public function test_relations_resolve(): void
    {
        $login = CustomerUser::factory()->create();
        $company = $this->company();
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'D', 'afm' => '4']);
        $grant = $this->grant($login, $company, $customer);

        $this->assertTrue($grant->customerUser->is($login));
        $this->assertTrue($grant->company->is($company));
        $this->assertTrue($grant->customer->is($customer));
        $this->assertTrue($grant->isActive());
    }
}
