<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Actions\StornoAndReissue;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StornoAndReissueTest extends TestCase
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
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 6,
        ]);
        $this->creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'Πιστωτικό', 'code' => 'ΠΤ',
            'invcount' => 1, 'is_credit' => true,
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C']);
    }

    private function originalWithLines(): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ5', 'code' => 5,
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            'header_discount_percent' => 10, 'company_name' => 'ACME', 'vat_no' => '123456789',
            'mydata_state' => 'VALID',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24, 'product_descr' => 'Widget',
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 30, 'vat_percent' => 24, 'product_descr' => 'Gadget',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    public function test_storno_full_credits_original_and_opens_a_fresh_draft(): void
    {
        $original = $this->originalWithLines();

        $result = app(StornoAndReissue::class)($original, $this->creditType);

        $credit = $result['credit'];
        $reissue = $result['reissue'];

        // 1) Full credit note reverses the original (balance → Credited, owed 0).
        $this->assertSame($original->id, $credit->credited_invoice_id);
        $this->assertCount(2, $credit->lines);
        $b = app(InvoiceBalance::class)->for($original->refresh());
        $this->assertSame(PaymentStatus::Credited, $b->status);
        $this->assertSame(0.0, $b->owed);

        // 2) Reissue is a fresh DRAFT copy: same type/customer/party/lines, a
        //    PROVISIONAL identity (gapless-at-send — its real ΑΑ, continuing the ΤΠΥ
        //    counter, is allocated only when it is transmitted), no MARK, not a credit.
        $this->assertSame('draft', $reissue->local_status);
        $this->assertNull($reissue->credited_invoice_id);
        $this->assertNull($reissue->mydata_state);
        $this->assertSame($this->type->id, $reissue->invoice_type_id);
        $this->assertSame($this->customer->id, $reissue->customer_id);
        $this->assertSame('ACME', $reissue->company_name);
        $this->assertSame('123456789', $reissue->vat_no);
        $this->assertNull($reissue->code);                       // no ΑΑ consumed by a draft
        $this->assertSame('ΠΡΟΣ-ΤΠΥ-'.$reissue->id, $reissue->invcode);
        $this->assertNotSame($original->id, $reissue->id);
        $this->assertCount(2, $reissue->lines);

        // Reissue totals match the original (same lines + header discount).
        $this->assertEqualsWithDelta((float) $original->gross_total, (float) $reissue->gross_total, 0.001);
    }

    public function test_storno_on_a_credit_note_is_refused(): void
    {
        $original = $this->originalWithLines();
        $result = app(StornoAndReissue::class)($original, $this->creditType);

        $this->expectException(RuntimeException::class);
        app(StornoAndReissue::class)($result['credit'], $this->creditType);
    }

    public function test_storno_carries_withholding_rate_and_category_to_the_reissue(): void
    {
        $original = $this->originalWithLines();
        // Withholding is now rate-driven: carry the RATE + category; the reissue's
        // amount is recomputed from its own net (RecomputeInvoiceTaxes).
        $original->forceFill(['withhold_rate' => 20, 'withhold_category' => 3])->save();

        $reissue = app(StornoAndReissue::class)($original->fresh(), $this->creditType)['reissue'];

        $this->assertEqualsWithDelta(20.0, (float) $reissue->withhold_rate, 0.001);
        $this->assertSame(3, (int) $reissue->withhold_category);
        // 20% × the reissue's net → a non-zero amount, recomputed on save.
        $this->assertGreaterThan(0, (float) $reissue->withhold_amount);
    }

    public function test_storno_on_an_already_fully_credited_invoice_throws(): void
    {
        $original = $this->originalWithLines();
        app(StornoAndReissue::class)($original, $this->creditType);   // fully credited

        // A second storno finds no remaining qty → reverseRemaining's
        // «ήδη πιστωθεί πλήρως» guard fires (caught & surfaced by the Filament action).
        $this->expectException(RuntimeException::class);
        app(StornoAndReissue::class)($original->fresh(), $this->creditType);
    }

    public function test_storno_after_a_partial_credit_reverses_only_the_remainder(): void
    {
        // PROV-018: before the fix, storno always requested the full ORIGINAL qty
        // and threw «Επιστροφή 2 > διαθέσιμη ποσότητα 1» the moment a line had been
        // partially credited — leaving a provider invoice impossible to cancel.
        $original = $this->originalWithLines();       // Widget ×2 @50, Gadget ×1 @30
        $widget = $original->lines->firstWhere('product_descr', 'Widget');

        // Partially credit the Widget line (1 of 2) first.
        app(IssueCreditNote::class)($original->fresh(['lines']), $this->creditType, [
            ['line_id' => $widget->id, 'qty' => 1],
        ]);

        // Storno now reverses the REMAINDER (Widget 1 left + Gadget 1) — no throw.
        $result = app(StornoAndReissue::class)($original->fresh(['lines']), $this->creditType);
        $credit = $result['credit'];

        $this->assertCount(2, $credit->lines);
        $widgetCredit = $credit->lines->firstWhere('original_line_id', $widget->id);
        $this->assertEqualsWithDelta(1.0, (float) $widgetCredit->qty, 0.001);   // only the leftover

        // The original ends fully credited overall, and the reissue draft still
        // carries both original lines at full qty (a clean copy to correct).
        $b = app(InvoiceBalance::class)->for($original->refresh());
        $this->assertSame(PaymentStatus::Credited, $b->status);
        $this->assertSame(0.0, $b->owed);
        $this->assertCount(2, $result['reissue']->lines);
    }
}
