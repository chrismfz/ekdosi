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

    public function test_wh4_promo_discount_line_is_folded_into_a_line_discount_and_files(): void
    {
        // The reported #32328 shape: a taxed service + a taxed WHMCS promotion
        // (negative-amount) line. myDATA rejects a negative line, so the mapper now
        // FOLDS the promo into the charge as a line-level discount % (58/290 = 20%)
        // → one clean line net 232 / VAT 55.68 / gross 287.68 (== WHMCS
        // subtotal/tax/total), instead of holding the row. subtotal+tax present →
        // the mapper detects NET line amounts.
        $pending = $this->makePending([
            ['description' => 'Business10 - kyklops.com.gr', 'amount' => '290.00', 'taxed' => '1'],
            ['description' => 'Κωδικός Promotion: zombee20 - 20.00%', 'amount' => '-58.00', 'taxed' => '1'],
        ], total: '287.68', payloadExtra: ['subtotal' => '232.00', 'tax' => '55.68', 'taxrate' => '24.00']);

        $this->file($pending);

        $this->assertSame(1, Invoice::count());
        $invoice = Invoice::first();
        $this->assertSame(1, $invoice->lines()->count(), 'the negative promo line was folded, not kept');
        $line = $invoice->lines()->first();
        $this->assertEqualsWithDelta(20.0, (float) $line->discount, 0.001, 'promo became a 20% line discount');
        $this->assertEqualsWithDelta(290.00, (float) $line->price_per_item, 0.001, 'pre-discount net per unit');
        $this->assertEqualsWithDelta(232.00, (float) $line->net_price, 0.001);
        $this->assertEqualsWithDelta(287.68, (float) $line->gross_price, 0.001);
    }

    public function test_wh4_a_discount_with_no_matching_taxable_charge_is_still_held(): void
    {
        // Safe-fold boundary: the discount (taxed=0) has no positive charge in its
        // OWN tax group to absorb it — the charge is taxed=1. Folding across tax
        // treatments would misstate the VAT split, so it is NOT folded; the negative
        // line stays and assertPayloadFilable HOLDS the row for the operator.
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1'],
            ['description' => 'Promo credit', 'amount' => '-20.00', 'taxed' => '0'],
        ], total: '104.00');

        $this->assertHeldWith($pending, 'αρνητικό ποσό');
    }

    public function test_wh4_a_promo_not_below_its_charge_is_not_folded_and_is_held(): void
    {
        // Boundary: a taxed promo whose magnitude is ≥ the charge in its OWN tax
        // group can't fold to a valid net > 0, so it is NOT folded — the negative
        // line stays and assertPayloadFilable HOLDS the row (never a ≤0-value line).
        $pending = $this->makePending([
            ['description' => 'Hosting', 'amount' => '100.00', 'taxed' => '1'],
            ['description' => 'Κωδικός Promotion: 100% off', 'amount' => '-100.00', 'taxed' => '1'],
        ], total: '0.00');

        $this->assertHeldWith($pending, 'αρνητικό ποσό');
    }

    public function test_wh4_a_near_total_discount_rounding_to_100pct_is_held(): void
    {
        // P2-4 boundary: a discount that rounds to exactly 100% (19999.99/20000 =
        // 99.99995 → 100.0000) would fold to a €0.00 line. foldPromoDiscounts rejects
        // a pct that rounds to ≥100, so the negative line stays and the row is HELD.
        $pending = $this->makePending([
            ['description' => 'Big service', 'amount' => '20000.00', 'taxed' => '1'],
            ['description' => 'Κωδικός Promotion: near-total', 'amount' => '-19999.99', 'taxed' => '1'],
        ], total: '0.01');

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
