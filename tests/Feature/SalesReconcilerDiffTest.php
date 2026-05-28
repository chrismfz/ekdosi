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
            'mydata_aade_id' => 'TESTUSER',
            'mydata_subscription_key' => 'TESTKEY',
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
            $this->aade('400000000000001', cancelled: false),
            $this->aade('400000000000002', cancelled: true),
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
        $this->invoice('600000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aade('600000000000001', cancelled: false)],
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
        $this->invoice('800000000000001', 'VALID');
        $this->invoice('800000000000001', 'VALID');

        $result = (new SalesReconciler($this->tenant))->diff(
            [$this->aade('800000000000001', cancelled: false)],
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
        $this->invoice('700000000000001', 'VALID');
        $this->invoice('700000000000002', 'CANCELLED');

        $result = (new SalesReconciler($this->tenant))->diff(
            [
                $this->aade('700000000000001', cancelled: false),
                $this->aade('700000000000002', cancelled: true),
            ],
            $this->localCollection(),
            '01/01/2026',
            '31/01/2026',
        );

        $this->assertFalse($result->hasDiscrepancies());
        $this->assertCount(2, $result->matched);
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
            ->with('customer')
            ->get();
    }

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
}
