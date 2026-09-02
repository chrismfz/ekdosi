<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ReturnInvoiceExtra;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use App\Services\RecomputeReturnedQuantities;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class IssueCreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PaymentMethod $credit;

    private InvoiceType $type;

    private InvoiceType $creditType;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 't', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $this->credit = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30,
        ]);
        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'Πιστωτικό', 'code' => 'ΠΤ',
            'invcount' => 1, 'is_credit' => true,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
    }

    private function originalWithLine(float $qty = 2, float $price = 50, float $vat = 24): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            'mydata_state' => 'VALID',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => $qty, 'price_per_item' => $price, 'vat_percent' => $vat, 'product_descr' => 'Widget',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    private function svc(): InvoiceBalance
    {
        return app(InvoiceBalance::class);
    }

    public function test_full_credit_note_zeroes_the_balance(): void
    {
        $original = $this->originalWithLine();        // gross 124
        $line = $original->lines->first();

        $credit = app(IssueCreditNote::class)($original, $this->creditType, [
            ['line_id' => $line->id, 'qty' => 2],
        ]);

        $this->assertSame($original->id, $credit->credited_invoice_id);
        $this->assertSame('ΠΤ1', $credit->invcode);
        $this->assertEqualsWithDelta(124.0, (float) $credit->gross_total, 0.001);   // positive lines
        $this->assertCount(1, $credit->lines);

        $b = $this->svc()->for($original->refresh());
        $this->assertSame(PaymentStatus::Credited, $b->status);
        $this->assertSame(0.0, $b->owed);
        $this->assertSame('124.00', (string) $original->credited_total);   // cache updated
    }

    public function test_credit_note_copies_the_income_class_snapshot(): void
    {
        // MYD-006: a credit reverses the SAME §8.6 income category as the original
        // line (the WHMCS-bridge per-line snapshot), not the credit type's default.
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00', 'mydata_state' => 'VALID',
        ]);
        $line = InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24, 'product_descr' => 'HW',
            'mydata_income_class' => 'E3_561_001', 'mydata_income_class_category' => 'category1_1',
        ]);
        app(RecomputeInvoiceTotals::class)($inv);

        $credit = app(IssueCreditNote::class)($inv->fresh(['lines']), $this->creditType, [
            ['line_id' => $line->id, 'qty' => 2],
        ]);

        $creditLine = $credit->lines->first();
        $this->assertSame('E3_561_001', $creditLine->mydata_income_class);
        $this->assertSame('category1_1', $creditLine->mydata_income_class_category);
    }

    public function test_partial_credit_reduces_owed(): void
    {
        $original = $this->originalWithLine();        // gross 124, qty 2
        $line = $original->lines->first();

        app(IssueCreditNote::class)($original, $this->creditType, [
            ['line_id' => $line->id, 'qty' => 1],     // credit half
        ]);

        $b = $this->svc()->for($original->refresh());
        $this->assertEqualsWithDelta(62.0, $b->credited, 0.001);
        $this->assertEqualsWithDelta(62.0, $b->owed, 0.001);

        $extra = ReturnInvoiceExtra::where('invoice_line_id', $line->id)->first();
        $this->assertEqualsWithDelta(1.0, (float) $extra->qty_returned, 0.001);
    }

    public function test_cannot_credit_more_than_remaining_qty(): void
    {
        $original = $this->originalWithLine();        // qty 2
        $line = $original->lines->first();

        $this->expectException(RuntimeException::class);
        app(IssueCreditNote::class)($original, $this->creditType, [
            ['line_id' => $line->id, 'qty' => 3],
        ]);
    }

    public function test_cancelling_a_credit_note_reverts_the_original_cache(): void
    {
        $original = $this->originalWithLine();
        $line = $original->lines->first();
        $credit = app(IssueCreditNote::class)($original, $this->creditType, [
            ['line_id' => $line->id, 'qty' => 2],
        ]);
        $this->assertSame('124.00', (string) $original->refresh()->credited_total);

        // Cancel the credit note → InvoiceObserver recomputes the original.
        $credit->forceFill(['mydata_state' => 'CANCELLED'])->save();

        $original->refresh();
        $this->assertSame('0.00', (string) $original->credited_total);
        $this->assertSame('unpaid', $original->payment_status);
    }

    public function test_cancelling_a_credit_note_frees_qty_and_allows_recredit(): void
    {
        // MON-1: the headline flow — issue a (wrong) full credit note, cancel
        // it at AADE, then re-issue. Before the fix qty_returned stayed at the
        // full qty forever and the re-issue threw «διαθέσιμη ποσότητα 0.000».
        $original = $this->originalWithLine();        // qty 2
        $line = $original->lines->first();

        app(IssueCreditNote::class)($original, $this->creditType, [
            ['line_id' => $line->id, 'qty' => 2],
        ]);
        $this->assertEqualsWithDelta(
            2.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );

        // Cancel at AADE (mydata_state) + local — MyDataSubmitter::cancel sets both.
        $credit = Invoice::where('credited_invoice_id', $original->id)->firstOrFail();
        $credit->forceFill(['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled'])->save();

        // qty_returned freed by the observer recompute.
        $this->assertEqualsWithDelta(
            0.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );

        // Re-crediting the full qty now succeeds.
        $credit2 = app(IssueCreditNote::class)($original->fresh(['lines']), $this->creditType, [
            ['line_id' => $line->id, 'qty' => 2],
        ]);
        $this->assertNotNull($credit2->id);
        $this->assertEqualsWithDelta(
            2.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );
    }

    public function test_local_cancel_of_a_credit_note_also_frees_qty(): void
    {
        // Same freeing via a LOCAL cancel (local_status), independent of AADE.
        $original = $this->originalWithLine();
        $line = $original->lines->first();
        app(IssueCreditNote::class)($original, $this->creditType, [['line_id' => $line->id, 'qty' => 2]]);

        Invoice::where('credited_invoice_id', $original->id)->firstOrFail()
            ->forceFill(['local_status' => 'cancelled'])->save();

        $this->assertEqualsWithDelta(
            0.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );
    }

    public function test_cancelling_one_of_two_credit_notes_frees_only_its_portion(): void
    {
        $original = $this->originalWithLine(qty: 5);
        $line = $original->lines->first();

        $c1 = app(IssueCreditNote::class)($original->fresh(['lines']), $this->creditType, [['line_id' => $line->id, 'qty' => 2]]);
        app(IssueCreditNote::class)($original->fresh(['lines']), $this->creditType, [['line_id' => $line->id, 'qty' => 1]]);
        $this->assertEqualsWithDelta(
            3.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );

        // Cancel c1 (qty 2) → only its portion frees; c2's 1 remains.
        $c1->forceFill(['mydata_state' => 'CANCELLED', 'local_status' => 'cancelled'])->save();
        $this->assertEqualsWithDelta(
            1.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );
    }

    public function test_recompute_leaves_legacy_returns_untouched(): void
    {
        // ETL-safety: a line with a legacy-imported qty_returned but NO native
        // credit note (no original_line_id link) must be left exactly as-is.
        $original = $this->originalWithLine(qty: 5);
        $line = $original->lines->first();
        ReturnInvoiceExtra::create([
            'company_id' => $this->tenant->id, 'invoice_line_id' => $line->id, 'qty_returned' => 3,
        ]);

        app(RecomputeReturnedQuantities::class)($original->fresh(['lines']));

        $this->assertEqualsWithDelta(
            3.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'), 0.001,
        );
    }

    public function test_gross_change_refreshes_payment_status_cache(): void
    {
        $original = $this->originalWithLine();        // gross 124, credit-term
        Payment::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->customer->id,
            'invoice_id' => $original->id, 'amount' => 62, 'pay_date' => '2026-05-11',
        ]);
        $this->assertSame('partial', $original->refresh()->payment_status);

        // Apply a 50% header discount → gross drops to 62, fully covered
        // by the 62 already paid. RecomputeInvoiceTotals must refresh the
        // money-status cache (was stale before the fix).
        $original->forceFill(['header_discount_percent' => 50])->save();
        app(RecomputeInvoiceTotals::class)($original);

        $this->assertSame('paid', $original->refresh()->payment_status);
    }

    public function test_refuses_non_credit_type(): void
    {
        $original = $this->originalWithLine();
        $line = $original->lines->first();

        $this->expectException(RuntimeException::class);
        app(IssueCreditNote::class)($original, $this->type, [   // $this->type is_credit = false
            ['line_id' => $line->id, 'qty' => 1],
        ]);
    }
}
