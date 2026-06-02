<?php

namespace Tests\Feature\Accounting;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Supplier;
use App\Services\Accounting\LedgerBook;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Βιβλίο Εσόδων-Εξόδων (read-model). Verifies the chronological projection over
 * invoices + expenses: signed credit notes, live/AADE-cancelled scoping, period
 * scoping, book + category filters, the period totals and the ΦΠΑ balance.
 */
class LedgerBookTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Ledger test',
            'slug' => 'ledger-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'TPY',
            'name' => 'ΤΠΥ',
            'invcount' => 0,
            'mydata_income_class_category' => 'category1_3',  // Παροχή Υπηρεσιών
        ]);
    }

    private function invoice(string $issuedAt, float $net, float $gross, array $extra = []): Invoice
    {
        // mydata_state is GUARDED on Invoice (a form must not set it), so it is
        // applied via forceFill rather than mass-assignment.
        $state = $extra['mydata_state'] ?? 'VALID';
        unset($extra['mydata_state']);

        $invoice = Invoice::create(array_merge([
            'company_id' => $this->tenant->id,
            'invoice_type_id' => $this->type->id,
            'invcode' => 'TPY'.uniqid(),
            'code' => random_int(1, 999999),
            'issued_at' => $issuedAt,
            'net_total' => $net,
            'gross_total' => $gross,
            'local_status' => 'active',
        ], $extra));

        $invoice->forceFill(['mydata_state' => $state])->save();

        return $invoice;
    }

    private function expense(string $issueDate, float $net, float $vat, float $gross, array $extra = []): Expense
    {
        return Expense::create(array_merge([
            'company_id' => $this->tenant->id,
            'mydata_mark' => (string) random_int(1, PHP_INT_MAX),
            'issue_date' => $issueDate,
            'net_total' => $net,
            'vat_total' => $vat,
            'gross_total' => $gross,
            'mydata_state' => 'VALID',
            'source' => 'sync',
            'classification_category' => 'category2_3',  // Λήψη Υπηρεσιών
        ], $extra));
    }

    private function book(string $from = '2026-01-01', string $to = '2026-01-31', string $bk = 'all', ?string $cat = null)
    {
        return (new LedgerBook($this->tenant))->forPeriod(
            Carbon::parse($from)->startOfDay(),
            Carbon::parse($to)->endOfDay(),
            $bk,
            $cat,
        );
    }

    public function test_income_and_expense_rows_with_signed_credit_notes(): void
    {
        $original = $this->invoice('2026-01-10 10:00:00', 100, 124);            // income net 100, vat 24
        $this->invoice('2026-01-20 10:00:00', 20, 24, [                          // credit note → −20 / −4
            'credited_invoice_id' => $original->id,
        ]);

        $this->expense('2026-01-12', 50, 12, 62);                                // expense net 50, vat 12
        $this->expense('2026-01-15', 10, 2, 12, ['invoice_type' => '14.31']);    // πιστωτικό → −10 / −2

        $result = $this->book();

        $this->assertCount(4, $result->rows);

        // Έσοδα net of the credit note.
        $this->assertSame(80.0, $result->incomeNet());
        $this->assertSame(20.0, $result->incomeVat());
        $this->assertSame(100.0, $result->incomeGross());
        $this->assertSame(2, $result->incomeCount());

        // Έξοδα net of the supplier πιστωτικό.
        $this->assertSame(40.0, $result->expenseNet());
        $this->assertSame(10.0, $result->expenseVat());
        $this->assertSame(50.0, $result->expenseGross());

        // ΦΠΑ εκροών − εισροών.
        $this->assertSame(10.0, $result->vatBalance());

        // The credit rows carry negative amounts and the flag.
        $creditRows = array_filter($result->rows, fn ($r) => $r->isCredit);
        $this->assertCount(2, $creditRows);
        foreach ($creditRows as $r) {
            $this->assertLessThan(0, $r->net);
        }
    }

    public function test_excludes_cancelled_and_out_of_period(): void
    {
        $this->invoice('2026-01-10 10:00:00', 100, 124);                                  // counts
        $this->invoice('2026-01-11 10:00:00', 999, 999, ['local_status' => 'cancelled']); // excluded (local)
        $this->invoice('2026-01-12 10:00:00', 999, 999, ['mydata_state' => 'CANCELLED']); // excluded (AADE)
        $this->invoice('2025-12-31 10:00:00', 999, 999);                                  // out of period

        $this->expense('2026-01-13', 50, 12, 62);                                         // counts
        $this->expense('2026-01-14', 999, 999, 1998, ['mydata_state' => 'CANCELLED']);    // excluded
        $this->expense('2025-12-30', 777, 777, 1554);                                     // out of period

        $result = $this->book();
        $this->assertSame(2, count($result->rows));
        $this->assertSame(100.0, $result->incomeNet());
        $this->assertSame(50.0, $result->expenseNet());
    }

    public function test_excludes_pure_drafts_but_keeps_legacy_imported_drafts(): void
    {
        $this->invoice('2026-01-10 10:00:00', 100, 124, ['local_status' => 'draft']);                    // pure draft → out
        $this->invoice('2026-01-11 10:00:00', 50, 62, ['local_status' => 'draft', 'legacy_id' => 5000]); // legacy → kept
        $this->invoice('2026-01-12 10:00:00', 30, 37);                                                    // active → kept

        $result = $this->book(bk: 'income');

        $this->assertSame(2, count($result->rows));
        $this->assertSame(80.0, $result->incomeNet());  // 50 (legacy) + 30 (active); the 100 pure draft is excluded
    }

    public function test_book_and_category_filters(): void
    {
        $this->invoice('2026-01-10 10:00:00', 100, 124);   // category1_3
        $this->expense('2026-01-12', 50, 12, 62);          // category2_3

        $incomeOnly = $this->book(bk: 'income');
        $this->assertSame(1, count($incomeOnly->rows));
        $this->assertSame('income', $incomeOnly->rows[0]->book);

        $byCategory = $this->book(cat: 'category2_3');
        $this->assertSame(1, count($byCategory->rows));
        $this->assertSame('expense', $byCategory->rows[0]->book);
        $this->assertSame('category2_3', $byCategory->rows[0]->categoryCode);
    }

    public function test_counterparty_and_category_label_are_resolved(): void
    {
        $customer = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Πελάτης ΑΕ',
            'afm' => '123456789',
        ]);
        $this->invoice('2026-01-10 10:00:00', 100, 124, ['customer_id' => $customer->id]);

        $supplier = Supplier::create([
            'company_id' => $this->tenant->id,
            'name' => 'Προμηθευτής ΑΕ',
            'afm' => '987654321',
        ]);
        $this->expense('2026-01-12', 50, 12, 62, ['supplier_id' => $supplier->id]);

        $result = $this->book();
        $income = $result->incomeRows()[0];
        $expense = $result->expenseRows()[0];

        $this->assertSame('Πελάτης ΑΕ', $income->counterparty);
        $this->assertSame('123456789', $income->afm);
        $this->assertNotNull($income->categoryLabel, 'category1_3 should map to a Greek label');
        $this->assertSame('73', $income->accountCode, 'category1_3 → ΕΓΛΣ 73');

        $this->assertSame('Προμηθευτής ΑΕ', $expense->counterparty);
        $this->assertSame('987654321', $expense->afm);
        $this->assertNotNull($expense->categoryLabel, 'category2_3 should map to a Greek label');
        $this->assertSame('61', $expense->accountCode, 'category2_3 → ΕΓΛΣ 61');
    }
}
