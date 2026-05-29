<?php

namespace Tests\Feature;

use App\Enums\ExpenseSource;
use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\ExpenseMark;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * E1 data-model checks for the Έξοδα phase: the three tables migrate, the
 * relations/casts wire up, and the FK cascade/unique rules behave.
 */
class ExpenseModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Expense test',
            'slug' => 'exp-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);
    }

    public function test_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('expenses'));
        $this->assertTrue(Schema::hasTable('expense_lines'));
        $this->assertTrue(Schema::hasTable('expense_marks'));
    }

    public function test_full_graph_and_casts(): void
    {
        $supplier = Supplier::create([
            'company_id' => $this->tenant->id,
            'afm' => '998482379',
            'name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ',
            'source' => 'sync',
        ]);

        $expense = Expense::create([
            'company_id' => $this->tenant->id,
            'supplier_id' => $supplier->id,
            'mydata_mark' => '400012434052701',
            'invoice_type' => '1.1',
            'series' => 'A',
            'aa' => '1',
            'issue_date' => '2026-01-10',
            'supplier_afm' => '998482379',
            'supplier_name' => 'ΠΡΟΜΗΘΕΥΤΗΣ ΑΕ',
            'net_total' => '100.00',
            'vat_total' => '24.00',
            'gross_total' => '124.00',
            'mydata_state' => 'VALID',
            'source' => 'sync',
        ]);

        $expense->lines()->create([
            'company_id' => $this->tenant->id,
            'line_number' => 1,
            'item_descr' => 'Υπηρεσία',
            'net_value' => '100.00',
            'vat_category' => 1,
            'vat_amount' => '24.00',
        ]);

        // A 0%/exempt line (reverse-charge άρθ.39α) stored verbatim.
        $expense->lines()->create([
            'company_id' => $this->tenant->id,
            'line_number' => 2,
            'item_descr' => 'Κινητό (αρθ.39α)',
            'net_value' => '50.00',
            'vat_category' => 7,
            'vat_exemption_category' => 16,
            'vat_amount' => '0.00',
        ]);

        $expense->marks()->create([
            'company_id' => $this->tenant->id,
            'mark' => '400012434052701',
            'mydata_action' => 'RequestDocs',
            'request' => '<req/>',
            'response' => '<RequestedDoc/>',
        ]);

        $expense->refresh();

        // Relations.
        $this->assertSame($supplier->id, $expense->supplier->id);
        $this->assertCount(2, $expense->lines);
        $this->assertCount(1, $expense->marks);
        $this->assertSame($expense->id, $expense->lines->first()->expense->id);

        // Casts.
        $this->assertInstanceOf(ExpenseSource::class, $expense->source);
        $this->assertSame(ExpenseSource::Sync, $expense->source);
        $this->assertSame('2026-01-10', $expense->issue_date->format('Y-m-d'));

        $exemptLine = $expense->lines->firstWhere('line_number', 2);
        $this->assertSame(7, $exemptLine->vat_category);
        $this->assertSame(16, $exemptLine->vat_exemption_category);
    }

    public function test_deleting_expense_cascades_to_lines_and_marks(): void
    {
        $expense = Expense::create([
            'company_id' => $this->tenant->id,
            'mydata_mark' => '400000000000777',
            'source' => 'manual',
        ]);
        $expense->lines()->create(['company_id' => $this->tenant->id, 'net_value' => '10.00']);
        $expense->marks()->create(['company_id' => $this->tenant->id, 'mark' => 'X']);

        // Hard-delete to verify the DB cascade (softDeletes() would not cascade).
        $expense->forceDelete();

        $this->assertSame(0, ExpenseLine::where('expense_id', $expense->id)->count());
        $this->assertSame(0, ExpenseMark::where('expense_id', $expense->id)->count());
    }

    public function test_mydata_mark_is_unique_per_company_but_nulls_repeat(): void
    {
        // Two manual expenses with NULL mark must coexist.
        Expense::create(['company_id' => $this->tenant->id, 'source' => 'manual']);
        Expense::create(['company_id' => $this->tenant->id, 'source' => 'manual']);
        $this->assertSame(2, Expense::where('company_id', $this->tenant->id)->count());

        // Same non-null mark twice → unique violation.
        Expense::create(['company_id' => $this->tenant->id, 'mydata_mark' => 'DUP1', 'source' => 'sync']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        Expense::create(['company_id' => $this->tenant->id, 'mydata_mark' => 'DUP1', 'source' => 'sync']);
    }
}
