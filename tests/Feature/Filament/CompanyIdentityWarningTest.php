<?php

namespace Tests\Feature\Filament;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MYD-024 (downgraded) — the ΑΦΜ/ΓΕΜΗ change advisory.
 *
 * The original finding demanded a full frozen issuer snapshot. That is stricter
 * than the protocol: the AADE issuer block on an invoice is ONLY vatNumber +
 * country + branch, so a company's name, address, ΔΟΥ or ΚΑΔ changing cannot
 * rewrite a filed invoice's payload. The one field that genuinely IS filing
 * identity — the series — is now frozen per document (MYD-018).
 *
 * What remains real is that the ΑΦΜ is also the myDATA/provider CREDENTIAL
 * identity: changing it means a different legal entity, which in practice means
 * a new company, not an edit. That is an operator advisory, not a code guard —
 * blocking it would prevent the legitimate case (fixing a typo before anything
 * has been filed) while not preventing the illegitimate one (the operator who
 * really has re-registered still has to be told what to do).
 */
class CompanyIdentityWarningTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'ΑΚΜΗ ΟΕ', 'slug' => 'ident-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
    }

    public function test_a_company_with_nothing_filed_reports_zero(): void
    {
        $this->assertSame(0, $this->company()->filedDocumentCount());
    }

    public function test_invoice_and_delivery_marks_both_count_as_filed(): void
    {
        $tenant = $this->company();
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
        ]);

        $note = DeliveryNote::create([
            'company_id' => $tenant->id, 'delivery_type_id' => $type->id,
            'customer_id' => $customer->id, 'invcode' => 'ΔΑ1', 'code' => 1,
            'issued_at' => now(), 'mydata_type' => '9.3', 'move_purpose' => 8,
            'local_status' => 'draft',
        ]);
        DeliveryMark::create([
            'company_id' => $tenant->id, 'delivery_note_id' => $note->id,
            'mark' => '400001965177932', 'mydata_action' => 'INSERT',
        ]);

        $this->assertSame(2, $tenant->filedDocumentCount());
    }

    public function test_a_cancelled_document_still_counts(): void
    {
        // The count is of MARKs, not live documents: a cancellation is itself a
        // filing under the current identity, and its MARK stays at AADE.
        $tenant = $this->company();
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
            'local_status' => 'cancelled', 'mydata_state' => 'CANCELLED',
        ]);
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
        ]);
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177999', 'mydata_action' => 'CANCEL',
        ]);

        $this->assertSame(2, $tenant->filedDocumentCount());
    }

    public function test_a_dry_run_or_rejection_does_not_count_as_filed(): void
    {
        // mydata_marks also stores forensic rows with a NULL mark — DRY_RUN,
        // REJECTED, PROVIDER_FAILED. Counting those would raise the warning during
        // exactly the pre-first-filing phase it must stay silent in: a
        // `mydata:test-submit` dry-run, or an AADE rejection — and it would warn
        // against fixing the very ΑΦΜ typo that caused the rejection.
        $tenant = $this->company();
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);

        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => null, 'mydata_action' => 'DRY_RUN',
        ]);
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => null, 'mydata_action' => 'REJECTED',
        ]);
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '', 'mydata_action' => 'PROVIDER_FAILED',
        ]);

        $this->assertSame(0, $tenant->filedDocumentCount());

        // The first ACCEPTED filing is what flips it.
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
        ]);

        $this->assertSame(1, $tenant->filedDocumentCount());
    }

    public function test_the_count_is_not_zeroed_by_the_ambient_tenant_context(): void
    {
        // MyDataMark/DeliveryMark carry CompanyScope, and CompanyResource is
        // panel-global: a super_admin can edit ANY company while a different tenant
        // is selected. Without opting out of the scope this returns 0 and the
        // warning silently never renders — the worst kind of failure for a guard,
        // because the screen looks fine. (EditCompany::afterSave() documents the
        // same hazard.)
        $edited = $this->company();
        $ambient = $this->company();

        $type = InvoiceType::create([
            'company_id' => $edited->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $edited->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);
        $invoice = Invoice::create([
            'company_id' => $edited->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $edited->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
        ]);

        $counted = app(CompanyContext::class)->actAs(
            $ambient,
            fn (): int => $edited->filedDocumentCount(),
        );

        $this->assertSame(1, $counted, 'the named company decides, not the selected tenant');

        // And the explicit company_id still scopes it — opting out of the scope
        // must not turn this into an all-tenant count.
        $this->assertSame(0, app(CompanyContext::class)->actAs(
            $edited,
            fn (): int => $ambient->filedDocumentCount(),
        ));
    }

    public function test_another_tenants_filings_do_not_count(): void
    {
        $mine = $this->company();
        $theirs = $this->company();
        $type = InvoiceType::create([
            'company_id' => $theirs->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $customer = Customer::create([
            'company_id' => $theirs->id, 'name' => 'Πελάτης', 'afm' => '997073525',
        ]);
        $invoice = Invoice::create([
            'company_id' => $theirs->id, 'invcode' => 'ΤΠΥ1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'header_discount_percent' => 0,
        ]);
        MyDataMark::create([
            'company_id' => $theirs->id, 'invoice_id' => $invoice->id,
            'mark' => '400001965177931', 'mydata_action' => 'INSERT',
        ]);

        $this->assertSame(0, $mine->filedDocumentCount());
        $this->assertSame(1, $theirs->filedDocumentCount());
    }
}
