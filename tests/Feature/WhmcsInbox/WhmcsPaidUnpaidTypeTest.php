<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Paid/unpaid-aware type suggestion (Phase 1): the draft pre-selects the right
 * invoice type from the WHMCS payment status + the customer's τιμολόγιο/απόδειξη
 * intent, so an UNPAID public-sector invoice is issued επί πιστώσει (open
 * receivable) rather than as settled-at-issue.
 */
class WhmcsPaidUnpaidTypeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $invoiceType;

    private InvoiceType $receiptType;

    private InvoiceType $unpaidType;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'PU', 'slug' => 'pu-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->invoiceType = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $this->receiptType = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΑΛΠ', 'code' => 'ΑΛΠ', 'invcount' => 1]);
        $this->unpaidType = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'Επί πιστώσει', 'code' => 'ΤΙΜ', 'invcount' => 1]);
        $this->tenant->update([
            'whmcs_default_invoice_type_id' => $this->invoiceType->id,
            'whmcs_default_receipt_type_id' => $this->receiptType->id,
            'whmcs_default_unpaid_type_id' => $this->unpaidType->id,
        ]);
    }

    private function row(string $status, ?Customer $customer): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => random_int(1, 99999),
            'customer_id' => $customer?->id,
            'payload' => ['status' => $status],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_unpaid_invoice_suggests_the_credit_term_type(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δημόσιο ΑΕ', 'afm' => '090000045']);
        $row = $this->row('Unpaid', $customer);

        $this->assertTrue($row->whmcsIsUnpaid());
        $this->assertSame('Unpaid', $row->whmcsStatus());
        $this->assertSame($this->unpaidType->id, $row->suggestedInvoiceTypeId($this->tenant));
    }

    public function test_paid_invoice_with_afm_suggests_the_invoice_type(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Εταιρία', 'afm' => '090000045']);
        $row = $this->row('Paid', $customer);

        $this->assertFalse($row->whmcsIsUnpaid());
        $this->assertSame($this->invoiceType->id, $row->suggestedInvoiceTypeId($this->tenant));
    }

    public function test_paid_invoice_without_afm_suggests_the_receipt_type(): void
    {
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Λιανική', 'afm' => null]);
        $row = $this->row('Paid', $customer);

        $this->assertTrue($row->ownLinesAreReceipt());
        $this->assertSame($this->receiptType->id, $row->suggestedInvoiceTypeId($this->tenant));
    }

    public function test_unpaid_falls_back_to_invoice_type_when_no_unpaid_default(): void
    {
        $this->tenant->update(['whmcs_default_unpaid_type_id' => null]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δημόσιο', 'afm' => '090000045']);
        $row = $this->row('Unpaid', $customer);

        $this->assertSame($this->invoiceType->id, $row->suggestedInvoiceTypeId($this->tenant->fresh()));
    }
}
