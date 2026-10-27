<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * «Άμεση τιμολόγηση» (the «γκρινιάρης») without an ekdosi customer yet (owner 2026-10-27): never issued unattended, but
 * flagged and belled so staff create the customer; and an auto-issued document takes the customer's own details,
 * its country included (it was always GR: review of #716).
 */
class ImmediateWithoutCustomerTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        $tenant = Company::create(['name' => 'Auto', 'slug' => 'iwc-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off', 'whmcs_auto_issue_immediate' => true,
            // Armed for auto-issue (as WhmcsAutoIssueCommandTest): without a source the run does nothing at all.
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php', 'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret']);
        VatCategory::create(['company_id' => $tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'description' => 'Cash', 'due_days' => 0]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $tenant->update(['whmcs_default_invoice_type_id' => $type->id]);

        return $tenant->fresh();
    }

    /** @return array<string, mixed> */
    private function mycsPayload(int $id, bool $immediate = true, string $status = 'Paid'): array
    {
        return ['invoiceid' => $id, 'userid' => 900 + $id, 'status' => $status, 'total' => '12.40', 'subtotal' => '10.00', 'tax' => '2.40',
            'taxrate' => '24.00', 'date' => '2026-10-27', 'companyname' => 'ROTOGRAN',
            'items' => ['item' => [['id' => 1, 'description' => 'Hosting', 'amount' => '10.00', 'taxed' => '1']]],
            'mycs' => ['immediate_invoicing' => $immediate, 'ignore_invoicing' => false]];
    }

    public function test_a_row_asking_for_it_without_a_customer_is_flagged_and_belled_but_never_issued(): void
    {
        $tenant = $this->tenant();
        $operator = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $tenant->users()->attach($operator->id);

        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->mycsPayload(31));
        $row = PendingWhmcsInvoice::query()->where('company_id', $tenant->id)->where('whmcs_invoice_id', 31)->sole();

        $this->assertNull($row->customer_id);
        $this->assertTrue($row->immediateWithoutCustomer());
        $bell = $operator->fresh()->notifications()->sole();
        $this->assertStringContainsString('δεν υπάρχει ακόμα πελάτης', (string) ($bell->data['body'] ?? ''));
        $this->assertStringContainsString('ROTOGRAN', (string) ($bell->data['body'] ?? ''));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);
        $this->assertSame(0, Invoice::query()->where('company_id', $tenant->id)->count(), 'never issued unattended without a customer');
    }

    public function test_not_flagged_when_not_asked_or_unpaid(): void
    {
        $tenant = $this->tenant();
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->mycsPayload(32, immediate: false));
        app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->mycsPayload(33, status: 'Unpaid'));

        foreach ([32, 33] as $id) {
            $this->assertFalse(PendingWhmcsInvoice::query()->where('company_id', $tenant->id)->where('whmcs_invoice_id', $id)->sole()->immediateWithoutCustomer());
        }
    }

    public function test_an_auto_issued_document_takes_the_customers_country_and_address(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Κυπριακή ΛΤΔ', 'afm' => '111111112', 'needs_immediate_invoice' => true,
            'address1' => 'Μακαρίου 1', 'city' => 'Λευκωσία', 'postcode' => '1065', 'country' => 'CY']);
        $row = PendingWhmcsInvoice::create(['company_id' => $tenant->id, 'whmcs_invoice_id' => 41, 'customer_id' => $customer->id,
            'payload' => ['invoiceid' => 41, 'userid' => 1, 'status' => 'Paid', 'date' => '2026-10-27', 'total' => '124.00',
                'items' => ['item' => [['description' => 'Hosting 1y', 'amount' => '124.00', 'taxed' => '1']]]],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $invoice = Invoice::query()->findOrFail($row->fresh()->invoice_id);
        $this->assertSame('CY', $invoice->country, 'not GR whatever the customer');
        $this->assertSame('Μακαρίου 1', $invoice->address1);
        $this->assertSame('Λευκωσία', $invoice->city);
    }
}
