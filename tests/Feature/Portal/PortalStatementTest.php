<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Portal\CustomerLedgerFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * «Η καρτέλα μου» — the portal statement. Two load-bearing properties: it is
 * grant-scoped (only a granted customer's ledger is shown) and it reads the SAME
 * canonical engine as the operator Καρτέλα (never recomputes), so the customer's
 * balance byte-matches the operator's.
 */
class PortalStatementTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'stmt-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $login, Customer $customer, string $role = CustomerUserAccess::ROLE_OWNER): CustomerUserAccess
    {
        return CustomerUserAccess::create([
            'customer_user_id' => $login->id, 'company_id' => $customer->company_id,
            'customer_id' => $customer->id, 'role' => $role, 'granted_at' => now(),
        ]);
    }

    private int $seq = 0;

    private function creditTermInvoice(Company $t, Customer $c, float $gross): Invoice
    {
        $this->seq++;
        $type = InvoiceType::create(['company_id' => $t->id, 'code' => 'ΤΙΜ'.$this->seq, 'name' => 'Τ', 'invcount' => 1, 'mydata_type' => '1.1']);
        $method = PaymentMethod::create(['company_id' => $t->id, 'description' => 'Επί Πιστώσει '.$this->seq, 'due_days' => 30]);
        $inv = Invoice::create([
            'company_id' => $t->id, 'invcode' => 'ΤΙΜ'.random_int(1, 9999), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'customer_id' => $c->id, 'issued_at' => now()->subDays(5),
            'local_status' => 'active', 'payment_method_id' => $method->id,
        ]);
        $inv->forceFill(['net_total' => $gross, 'gross_total' => $gross])->save();

        return $inv;
    }

    public function test_statement_shows_the_balance_and_ledger_for_a_granted_customer(): void
    {
        $t = $this->company();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $this->creditTermInvoice($t, $c, 100);

        $login = $this->login();
        $this->grant($login, $c);

        $statements = app(CustomerLedgerFeed::class)->forLogin($login);

        $this->assertCount(1, $statements);
        $this->assertSame(100.0, $statements[0]['owed']);
        $this->assertSame(100.0, $statements[0]['balance']);
        $this->assertCount(1, $statements[0]['rows']);
        $this->assertSame(100.0, $statements[0]['rows'][0]['debit']);

        // Consistency: the portal balance equals the operator engine's balance.
        $builderBalance = app(CustomerLedgerBuilder::class)->build($c->fresh())->stats['balance'];
        $this->assertSame((float) $builderBalance, $statements[0]['balance']);
    }

    public function test_only_granted_customers_appear(): void
    {
        $t = $this->company();
        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '2']);
        $this->creditTermInvoice($t, $mine, 100);
        $this->creditTermInvoice($t, $other, 999);

        $login = $this->login();
        $this->grant($login, $mine);

        $statements = app(CustomerLedgerFeed::class)->forLogin($login);
        $this->assertCount(1, $statements);
        $this->assertSame('Mine', $statements[0]['customer']);
        $this->assertSame(100.0, $statements[0]['owed']);
    }

    public function test_a_revoked_grant_yields_no_statement(): void
    {
        $t = $this->company();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $this->creditTermInvoice($t, $c, 100);

        $login = $this->login();
        $this->grant($login, $c)->forceFill(['revoked_at' => now()])->save();

        $this->assertCount(0, app(CustomerLedgerFeed::class)->forLogin($login));
    }

    public function test_a_prepaid_on_account_payment_shows_as_a_credit_balance(): void
    {
        $t = $this->company();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        // On-account payment (invoice_id null), no invoices → the customer is in credit.
        Payment::create([
            'company_id' => $t->id, 'customer_id' => $c->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 50, 'pay_date' => now(),
        ]);

        $login = $this->login();
        $this->grant($login, $c);

        $st = app(CustomerLedgerFeed::class)->forLogin($login)[0];
        $this->assertSame(-50.0, $st['balance']);
        $this->assertSame(50.0, $st['credit']);
        $this->assertSame(0.0, $st['owed']);
    }

    public function test_row_labels_do_not_leak_internal_ids(): void
    {
        $t = $this->company();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        Payment::create([
            'company_id' => $t->id, 'customer_id' => $c->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 50, 'pay_date' => now(),
        ]);
        $login = $this->login();
        $this->grant($login, $c);

        $rows = app(CustomerLedgerFeed::class)->forLogin($login)[0]['rows'];
        $this->assertSame('Πληρωμή', $rows[0]['label']);   // «Πληρωμή #<id>» → «Πληρωμή»
        $this->assertStringNotContainsString('#', $rows[0]['label']);
    }

    public function test_a_grant_whose_company_mismatches_its_customer_is_skipped(): void
    {
        $companyA = $this->company();
        $companyB = $this->company();
        $customerB = Customer::create(['company_id' => $companyB->id, 'name' => 'B', 'afm' => '1']);
        $this->creditTermInvoice($companyB, $customerB, 100);

        $login = $this->login();
        // A malformed grant: company_id = A, but the customer belongs to B.
        CustomerUserAccess::create([
            'customer_user_id' => $login->id, 'company_id' => $companyA->id,
            'customer_id' => $customerB->id, 'role' => CustomerUserAccess::ROLE_OWNER, 'granted_at' => now(),
        ]);

        $this->assertCount(0, app(CustomerLedgerFeed::class)->forLogin($login));
    }

    public function test_statement_page_renders(): void
    {
        $t = $this->company();
        $c = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $this->creditTermInvoice($t, $c, 100);
        $login = $this->login();
        $this->grant($login, $c);

        $this->actingAs($login, 'portal')->get('/user/statement')
            ->assertOk()
            ->assertSee('Η καρτέλα μου')
            ->assertSee('Οφειλόμενο υπόλοιπο')
            // The invoices are reachable both from the nav (relabelled from the
            // generic «Αρχική») AND a top-of-page button — the statement links to
            // «Τα παραστατικά μου» so a customer never has to hunt for their documents.
            ->assertSee('Τα παραστατικά μου')
            ->assertSee(route('portal.home'), false);
    }

    public function test_statement_requires_portal_auth(): void
    {
        $this->get('/user/statement')->assertRedirect(route('portal.login'));
    }
}
