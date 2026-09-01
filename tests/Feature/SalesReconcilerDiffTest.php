<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\MyData\AadeDocSummary;
use App\Services\MyData\SalesReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pure-diff core of the live myDATA sales reconciliation. No network:
 * we hand SalesReconciler::diff() pre-built AADE summaries + local
 * invoices and assert the four buckets. The network fetch + firebed
 * parsing is covered separately by SalesReconcilerFetchTest.
 */
class SalesReconcilerDiffTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    private InvoiceType $type;

    private int $code = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Recon test',
            'slug' => 'recon-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης ΑΕ',
            'afm' => '123456789',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'Τιμολόγιο',
            'invcount' => 1,
            'mydata_type' => '1.1',
        ]);
    }

    public function test_diff_sorts_invoices_into_the_four_buckets(): void
    {
        $matchedValid = $this->invoice('400000000000001', 'VALID');
        $matchedCancelled = $this->invoice('400000000000002', 'CANCELLED');
        $aadeCancelledLocalActive = $this->invoice('400000000000003', 'VALID');
        $localCancelledAadeValid = $this->invoice('400000000000004', 'CANCELLED');
        $missingAtAade = $this->invoice('400000000000005', 'VALID');

        $aadeDocs = [
            $this->aadeFor($matchedValid, cancelled: false),
            $this->aadeFor($matchedCancelled, cancelled: true),
            $this->aade('400000000000003', cancelled: true),  // AADE cancelled, local active
            $this->aade('400000000000004', cancelled: false), // AADE valid, local cancelled
            // 400000000000005 deliberately absent → missing at AADE
            $this->aade('400000000000099', cancelled: false), // AADE-only → missing locally
        ];

        $result = (new SalesReconciler($this->tenant))->diff(
            $aadeDocs,
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(2, $result->matched);
        $this->assertCount(2, $result->stateMismatch);
        $this->assertCount(1, $result->missingAtAade);
        $this->assertCount(1, $result->missingLocally);

        $this->assertEqualsCanonicalizing(
            ['400000000000001', '400000000000002'],
            collect($result->matched)->pluck('mark')->all(),
        );

        $this->assertSame('400000000000005', $result->missingAtAade[0]->mark);
        $this->assertSame($missingAtAade->id, $result->missingAtAade[0]->invoiceId);

        $this->assertSame('400000000000099', $result->missingLocally[0]->mark);
        $this->assertNull($result->missingLocally[0]->invoiceId);

        $this->assertSame(5, $result->aadeTotal);
        $this->assertSame(5, $result->localTotal);
        $this->assertSame(4, $result->discrepancyCount());
        $this->assertTrue($result->hasDiscrepancies());
    }

    public function test_state_mismatch_problem_text_is_direction_aware(): void
    {
        $this->invoice('500000000000001', 'VALID');     // local active
        $this->invoice('500000000000002', 'CANCELLED'); // local cancelled

        $result = (new SalesReconciler($this->tenant))->diff(
            [
                $this->aade('500000000000001', cancelled: true),  // AADE cancelled
                $this->aade('500000000000002', cancelled: false), // AADE valid
            ],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $rows = collect($result->stateMismatch)->keyBy('mark');

        $this->assertStringContainsString('Ακυρωμένο στο AADE', $rows['500000000000001']->problem);
        $this->assertStringContainsString('Τοπικά ακυρωμένο', $rows['500000000000002']->problem);
    }

    public function test_local_invoices_without_a_mark_are_ignored(): void
    {
        // Draft (never filed) — no mydata_mark. Must not appear anywhere.
        $this->invoice(null, null);
        $filed = $this->invoice('600000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($filed, cancelled: false)],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertSame(1, $result->localTotal);
        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->missingAtAade);
    }

    public function test_duplicate_local_mark_is_surfaced_not_collapsed(): void
    {
        // Two local invoices sharing one MARK — a data-integrity fault
        // the console must surface (keyBy would otherwise hide one).
        $first = $this->invoice('800000000000001', 'VALID');
        $this->invoice('800000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($first, cancelled: false)],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(2, $result->duplicateLocal);
        $this->assertSame(2, $result->localTotal);
        $this->assertTrue($result->hasDiscrepancies());
        $this->assertSame(2, $result->discrepancyCount());
        // The collapsed survivor still matches AADE, but the collision is flagged.
        $this->assertCount(1, $result->matched);
    }

    public function test_no_discrepancies_when_everything_agrees(): void
    {
        $a = $this->invoice('700000000000001', 'VALID');
        $b = $this->invoice('700000000000002', 'CANCELLED');

        $result = (new SalesReconciler($this->tenant))->diff(
            [
                $this->aadeFor($a, cancelled: false),
                $this->aadeFor($b, cancelled: true),
            ],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertFalse($result->hasDiscrepancies());
        $this->assertCount(2, $result->matched);
    }

    public function test_same_mark_and_state_but_different_gross_is_a_content_mismatch(): void
    {
        // MYD-017: MARK + state agree, but the gross differs beyond the cent
        // tolerance → contentMismatch, NOT a false "matched".
        $inv = $this->invoice('410000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 999.00])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $this->assertStringContainsString('μικτό', $result->contentMismatch[0]->problem);
        $this->assertTrue($result->hasDiscrepancies());
        $this->assertSame(1, $result->discrepancyCount());
    }

    public function test_gross_within_a_cent_still_matches(): void
    {
        $inv = $this->invoice('420000000000001', 'VALID'); // gross_total 124.00

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['gross' => 124.009])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
    }

    public function test_different_type_or_series_is_a_content_mismatch(): void
    {
        $inv = $this->invoice('430000000000001', 'VALID');
        $inv->forceFill(['mydata_type' => '1.1'])->save(); // so the type compare runs

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['invoiceType' => '2.1', 'series' => 'ZZZ'])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(0, $result->matched);
        $this->assertCount(1, $result->contentMismatch);
        $problem = $result->contentMismatch[0]->problem;
        $this->assertStringContainsString('τύπος', $problem);
        $this->assertStringContainsString('σειρά', $problem);
    }

    public function test_retail_without_counterpart_afm_still_matches(): void
    {
        // Retail (11.x): AADE returns no counterpart. A local vat_no with no AADE
        // AFM to compare against is NOT a content difference.
        $inv = $this->invoice('440000000000001', 'VALID');
        $inv->forceFill(['vat_no' => '123456789'])->save();

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aadeFor($inv, override: ['counterpartVat' => null])],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertCount(1, $result->matched);
        $this->assertCount(0, $result->contentMismatch);
    }

    private function invoice(?string $mark, ?string $state): Invoice
    {
        $this->code++;

        $invoice = Invoice::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'TPY'.$this->code,
            'code' => $this->code,
            'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
            'gross_total' => 124.00,
        ]);

        if ($mark !== null) {
            $invoice->forceFill([
                'mydata_mark' => $mark,
                'mydata_state' => $state,
                'mydata_sent' => true,
            ])->save();
        }

        return $invoice;
    }

    /** @return Collection<int, Invoice> */
    private function localCollection(): Collection
    {
        return Invoice::query()
            ->where('company_id', $this->tenant->id)
            ->whereNotNull('mydata_mark')
            ->with('customer', 'invoiceType')
            ->get();
    }

    /**
     * An AADE summary NOT backed by a local invoice (missing-locally rows, or
     * state-mismatch rows whose content is never compared). Content is arbitrary.
     */
    private function aade(string $mark, bool $cancelled): AadeDocSummary
    {
        return new AadeDocSummary(
            mark: $mark,
            uid: 'UID-'.$mark,
            cancelled: $cancelled,
            cancelledByMark: $cancelled ? '9'.$mark : null,
            series: 'TPY',
            aa: substr($mark, -3),
            issueDate: '2026-01-15',
            counterpartName: 'Πελάτης ΑΕ',
            counterpartVat: '123456789',
            gross: 124.00,
        );
    }

    /**
     * An AADE summary that MIRRORS a local invoice's content — used for matched
     * rows so the MYD-017 content compare sees no difference. Pass overrides to
     * force a specific field to diverge (→ contentMismatch).
     */
    private function aadeFor(Invoice $inv, bool $cancelled = false, array $override = []): AadeDocSummary
    {
        return new AadeDocSummary(
            mark: (string) $inv->mydata_mark,
            uid: 'UID-'.$inv->mydata_mark,
            cancelled: $cancelled,
            cancelledByMark: $cancelled ? '9'.$inv->mydata_mark : null,
            series: $override['series'] ?? $inv->invoiceType?->code,
            aa: $override['aa'] ?? (string) $inv->code,
            issueDate: $override['issueDate'] ?? $inv->issued_at?->format('Y-m-d'),
            counterpartName: $inv->customer?->name,
            counterpartVat: $override['counterpartVat'] ?? $inv->vat_no,
            gross: $override['gross'] ?? ($inv->gross_total !== null ? (float) $inv->gross_total : null),
            invoiceType: $override['invoiceType'] ?? $inv->mydata_type,
        );
    }
}
