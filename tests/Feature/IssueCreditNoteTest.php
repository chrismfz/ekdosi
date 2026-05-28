<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\ReturnInvoiceExtra;
use App\Services\InvoiceBalance;
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

        return app(\App\Services\RecomputeInvoiceTotals::class)($inv);
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
