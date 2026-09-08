<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * WH-1 / WH-2 / WH-3 / WH-4 / WH-5 (AUDIT): the filing guards that HOLD a
 * WHMCS row rather than turn it into a wrong παραστατικό — non-EUR currency,
 * a wrong VAT rate / unreconciled total, negative (promo/credit) lines, and a
 * row the legacy app already filed. Every refusal must leave NO Invoice and
 * NOT consume an ΑΑ counter (the ghost-invoice hazard).
 *
 * Tenant runs off-mode (NullSubmitter) — the guards are mode-independent, so
 * they fire before any submitter is even reached.
 */
class WhmcsFilingGuardTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $invoiceType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'G', 'slug' => 'g-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true,
        ]);
        VatCategory::create([
            'company_id' => $this->tenant->id, 'name' => '0%', 'rate' => 0.00, 'is_default' => false,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true,
        ]);
        $this->invoiceType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 1, 'payment_method_id' => $pm->id,
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'ΑΚΜΕ', 'afm' => '111111111',
        ]);
    }

    /** @param array<string, mixed> $payloadExtra */
    private function makePending(array $items, string $total = '124.00', array $payloadExtra = []): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => 7777,
            'payload' => array_merge([
                'invoiceid' => 7777,
                'userid' => 1,
                'date' => '2026-06-01',
                'total' => $total,
                'items' => ['item' => $items],
            ], $payloadExtra),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $this->customer->id,
        ]);
    }

    private function file(PendingWhmcsInvoice $pending): void
    {
        app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $this->customer, $this->invoiceType);
    }

    private function assertHeldWith(PendingWhmcsInvoice $pending, string $needle): void
    {
        $countBefore = $this->invoiceType->fresh()->invcount;
        try {
            $this->file($pending);
            $this->fail('Expected the filer to refuse (LogicException).');
        } catch (LogicException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
        // No ghost invoice, no ΑΑ consumed, row stays pending.
        $this->assertSame(0, Invoice::count());
        $this->assertSame($countBefore, $this->invoiceType->fresh()->invcount);
        $this->assertNull($pending->fresh()->invoice_id);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_wh1_non_eur_currency_is_held(): void
    {
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']],
            payloadExtra: ['currencycode' => 'USD'],
        );

        $this->assertHeldWith($pending, 'νόμισμα USD');
    }

    public function test_eur_currency_is_accepted(): void
    {
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']],
            payloadExtra: ['currencycode' => 'EUR'],
        );

        $this->file($pending);
        $this->assertSame(1, Invoice::count());
    }

    public function test_wh4_negative_promo_line_is_held(): void
    {
        // The WHMCS total (104) is internally consistent with the two lines so
        // it's the NEGATIVE line — not a totals gap — that trips the guard.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => 'Promo credit', 'amount' => '-20.00', 'taxed' => '1'],
        ], total: '104.00');

        $this->assertHeldWith($pending, 'αρνητικό ποσό');
    }

    public function test_wh2_taxrate_mismatch_is_held_even_when_gross_reconciles(): void
    {
        // Tax-INCLUSIVE WHMCS invoice at 13%: the mapper reproduces the €113
        // gross (so the total reconciles), but WHMCS charged 13% while the
        // tenant default is 24% — the VAT split filed to AADE would be wrong.
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '113.00', 'taxed' => '1']],
            total: '113.00',
            payloadExtra: ['taxrate' => '13.00'],
        );

        $this->assertHeldWith($pending, 'ΦΠΑ 13% αλλά το ekdosi');
    }

    public function test_wh5_gross_mismatch_beyond_tolerance_is_held(): void
    {
        // WHMCS total (200) doesn't reconcile with the mapped gross (124):
        // a dropped/omitted amount or a rate the mapper can't reproduce.
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']],
            total: '200.00',
        );

        $this->assertHeldWith($pending, 'δεν συμφωνεί στα σύνολα');
    }

    public function test_cent_level_rounding_is_within_tolerance(): void
    {
        // A €0.01 back-computation residue must NOT block (WH-5 tolerance).
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']],
            total: '124.01',
        );

        $this->file($pending);
        $this->assertSame(1, Invoice::count());
    }

    public function test_create_draft_holds_non_eur(): void
    {
        // Currency + negative are unfixable in the draft form → held everywhere,
        // including the manual createDraft path.
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']],
            payloadExtra: ['currencycode' => 'USD'],
        );

        try {
            app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $pending, $this->customer, $this->invoiceType);
            $this->fail('Expected createDraft to refuse a non-EUR row.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('νόμισμα USD', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());
        $this->assertNull($pending->fresh()->invoice_id);
    }

    public function test_create_draft_allows_a_rate_mismatch_for_manual_fix(): void
    {
        // Deliberate asymmetry with file(): the manual draft path does NOT
        // hard-block a rate/totals mismatch — the operator fixes the per-line
        // VAT in the editable draft before issuing (the preview modal already
        // warns). The SAME row via file() is held
        // (see test_wh2_taxrate_mismatch_is_held_even_when_gross_reconciles).
        $pending = $this->makePending(
            [['description' => 'Hosting', 'amount' => '113.00', 'taxed' => '1']],
            total: '113.00',
            payloadExtra: ['taxrate' => '13.00'],
        );

        $invoice = app(WhmcsInvoiceFiler::class)->createDraft(
            $this->tenant, $pending, $this->customer, $this->invoiceType,
        );

        $this->assertSame('draft', $invoice->local_status);
        $this->assertSame(1, Invoice::count());
    }

    public function test_wh8_blank_description_with_amount_is_held(): void
    {
        // A real charge (€50) with an EMPTY description would be dropped by the
        // mapper and silently under-bill. The WHMCS total (174) is the honest
        // sum; assertPayloadFilable (which runs before totals-reconcile) holds it.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => '',        'amount' => '50.00',  'taxed' => '1'],
        ], total: '174.00');

        $this->assertHeldWith($pending, 'ΚΕΝΗ περιγραφή');
    }

    public function test_wh8_blank_description_with_amount_is_held_on_create_draft(): void
    {
        // The money-critical path: createDraft does NOT run totals-reconcile, so
        // WITHOUT the WH-8 mapper→guard surfacing this blank €50 charge would
        // silently vanish into a draft billing only €124. It must be held.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => '   ',     'amount' => '50.00',  'taxed' => '1'],
        ], total: '174.00');

        try {
            app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $pending, $this->customer, $this->invoiceType);
            $this->fail('Expected createDraft to refuse a blank-description charge line.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('ΚΕΝΗ περιγραφή', $e->getMessage());
        }
        $this->assertSame(0, Invoice::count());
        $this->assertNull($pending->fresh()->invoice_id);
    }

    public function test_wh8_blank_description_zero_amount_is_filable(): void
    {
        // A blank line with NO amount is a harmless spacer — it must not block.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => '',        'amount' => '0.00',   'taxed' => '1'],
        ], total: '124.00');

        $this->file($pending);
        $this->assertSame(1, Invoice::count());
    }

    public function test_wh3_legacy_invoiced_row_is_held(): void
    {
        $pending = $this->makePending([['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']]);
        $pending->update(['legacy_invoiced' => 5]);

        $this->assertHeldWith($pending->fresh(), 'legacy');
    }

    public function test_legacy_invoiced_zero_is_filable(): void
    {
        $pending = $this->makePending([['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']]);
        $pending->update(['legacy_invoiced' => 0]);

        $this->file($pending->fresh());
        $this->assertSame(1, Invoice::count());
    }
}
