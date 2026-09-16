<?php

namespace Tests\Feature\CustomerLedger;

use App\Filament\Resources\Customers\Pages\CustomerLedger;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reproduce the production 404. Boot the page exactly as Filament does:
 * - auth a user
 * - bind the panel tenant (Filament::setTenant)
 * - Livewire::test the page with the record param
 *
 * If this test 404s, we can see WHERE in the stack the abort fires.
 * If it passes, the prod 404 is config / env / middleware, not the code.
 */
class CustomerLedgerLivewireMountTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_mounts_for_logged_in_user_in_correct_tenant(): void
    {
        $tenant = Company::create([
            'name' => 'Test',
            'slug' => 'mount-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);

        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Καρτέλα Test',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        // Bypass the policy gate in mount() — we're testing the
        // page-mount mechanics (the TypeError on $record), NOT the
        // CustomerPolicy. In production an operator with view
        // permission satisfies the policy; the test user doesn't
        // have Shield-generated permissions.
        Gate::before(fn () => true);

        $this->actingAs($user);

        // Bind the Filament tenant exactly like the IdentifyTenant
        // middleware would after the {tenant:slug} route param resolves.
        Filament::setTenant($tenant);

        // Mount the page with the record param Livewire receives from
        // the route. This exercises canAccess + mountCanAuthorizeAccess
        // + mountCanAuthorizeResourceAccess + mount().
        $response = Livewire::test(CustomerLedger::class, ['record' => $customer->id]);

        // If we reached here without aborting, the page mounted OK.
        $response->assertStatus(200);
        $this->assertSame($customer->id, $response->get('record')->id);
    }

    public function test_ledger_defaults_to_chronological_oldest_first_on_first_load(): void
    {
        // Regression guard: on a records()-backed table, defaultSort() does NOT
        // seed the sort state, so the no-click default arrives as (null,null). The
        // page must still show OLDEST-first (χρονολογικά) without a header click —
        // proving the reverse triggers on the default state, not only on 'asc'.
        Gate::before(fn () => true);

        $tenant = Company::create([
            'name' => 'Test', 'slug' => 'order-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'name' => 'Credit', 'due_days' => 30, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'name' => 'T', 'code' => 'TST', 'invcount' => 0, 'payment_method_id' => $pm->id]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'K']);

        foreach ([['2019-03-07', 'OLD1', 1], ['2025-11-19', 'NEW1', 2]] as [$date, $code, $seq]) {
            Invoice::create([
                'company_id' => $tenant->id, 'customer_id' => $customer->id,
                'invoice_type_id' => $type->id, 'payment_method_id' => $pm->id,
                'invcode' => $code, 'code' => $seq, 'issued_at' => $date,
                'gross_total' => 124.0, 'net_total' => 100.0, 'local_status' => 'active',
            ]);
        }

        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x')]);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(CustomerLedger::class, ['record' => $customer->id])
            ->assertOk()
            // Oldest date appears BEFORE the newest — chronological, no click needed.
            ->assertSeeInOrder(['07/03/2019', '19/11/2025']);
    }
}
