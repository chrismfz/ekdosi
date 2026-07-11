<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceMailLog;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Per-customer email history (Customer::invoiceMailLog HasManyThrough) — the
 * data behind the customer «Ιστορικό email» tab and the tenant-wide resource.
 */
class EmailHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
    }

    private function invoiceFor(Customer $c): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $c->id,
            'issued_at' => now(), 'net_total' => 100, 'gross_total' => 124, 'local_status' => 'active',
        ]);
    }

    private function logFor(Invoice $inv, string $status, string $recipient): InvoiceMailLog
    {
        return InvoiceMailLog::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'recipient' => $recipient, 'trigger' => 'auto', 'status' => $status, 'queued_at' => now(),
        ]);
    }

    public function test_customer_mail_log_spans_all_their_invoices_and_excludes_others(): void
    {
        $a = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Α', 'email' => 'a@x.gr']);
        $b = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Β', 'email' => 'b@x.gr']);

        // Customer A: two invoices, three mail attempts total.
        $a1 = $this->invoiceFor($a);
        $a2 = $this->invoiceFor($a);
        $this->logFor($a1, 'sent', 'a@x.gr');
        $this->logFor($a1, 'failed', 'a@x.gr');
        $this->logFor($a2, 'sent', 'a@x.gr');

        // Customer B: one invoice, one mail — must NOT bleed into A's history.
        $b1 = $this->invoiceFor($b);
        $this->logFor($b1, 'sent', 'b@x.gr');

        $aLog = $a->invoiceMailLog()->get();
        $this->assertCount(3, $aLog);
        $this->assertEqualsCanonicalizing(['a@x.gr'], $aLog->pluck('recipient')->unique()->all());

        $this->assertCount(1, $b->invoiceMailLog()->get());
    }

    public function test_mail_log_is_tenant_scoped(): void
    {
        $other = Company::create([
            'name' => 'o', 'slug' => 'o-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $otherType = InvoiceType::create(['company_id' => $other->id, 'code' => 'Τ', 'name' => 'Τ', 'invcount' => 1]);
        $otherCust = Customer::create(['company_id' => $other->id, 'name' => 'Ξ']);
        $otherInv = Invoice::create([
            'company_id' => $other->id, 'invcode' => 'X'.uniqid(), 'code' => 1,
            'invoice_type_id' => $otherType->id, 'customer_id' => $otherCust->id,
            'issued_at' => now(), 'net_total' => 1, 'gross_total' => 1, 'local_status' => 'active',
        ]);
        InvoiceMailLog::create([
            'company_id' => $other->id, 'invoice_id' => $otherInv->id,
            'recipient' => 'x@o.gr', 'trigger' => 'auto', 'status' => 'sent', 'queued_at' => now(),
        ]);

        $mine = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Δ', 'email' => 'd@x.gr']);
        $inv = $this->invoiceFor($mine);
        $this->logFor($inv, 'sent', 'd@x.gr');

        // The other tenant's row exists globally but not in this customer's history.
        $this->assertCount(1, $mine->invoiceMailLog()->get());
        $this->assertSame(2, InvoiceMailLog::withoutGlobalScopes()->count());
    }
}
