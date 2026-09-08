<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Expense;
use App\Models\ExpenseMark;
use App\Services\MyData\AadeDocSummary;
use App\Services\MyData\ExpenseReconciler;
use App\Services\MyData\SyncExpenseStateFromAade;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use RuntimeException;
use Tests\TestCase;

/**
 * MYD-014: an operator-confirmed, audited sync of AADE's state onto an EXISTING
 * local expense — the expense-side twin of SyncInvoiceStateFromAade. A supplier
 * cancellation (which ExpenseImporter skips because the MARK already exists) can
 * now flip our VALID expense to CANCELLED without a re-import or a duplicate.
 */
class SyncExpenseStateFromAadeTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Sync exp', 'slug' => 'syncexp-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908',
        ]);
    }

    private function expense(string $state = 'VALID'): Expense
    {
        return Expense::create([
            'company_id' => $this->tenant->id,
            'mydata_mark' => '400000000000001',
            'mydata_state' => $state,
            'issue_date' => '2026-01-10',
            'series' => 'A',
            'aa' => '1',
            'invoice_type' => '1.1',
            'supplier_afm' => '998482379',
            'net_total' => '100.00',
            'gross_total' => '124.00',
            'source' => 'sync',
        ]);
    }

    public function test_supplier_cancellation_flips_valid_expense_to_cancelled(): void
    {
        $expense = $this->expense('VALID');

        $result = (new SyncExpenseStateFromAade)->sync($expense, 'CANCELLED', '900000000000001');

        $this->assertTrue($result['changed']);
        $fresh = $expense->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('900000000000001', $fresh->cancelled_by_mark);

        // Exactly one forensic STATE_SYNC row; NO duplicate/re-imported expense.
        $this->assertSame(1, ExpenseMark::query()
            ->where('expense_id', $expense->id)->where('mydata_action', 'STATE_SYNC')->count());
        $this->assertSame(1, Expense::query()->where('mydata_mark', '400000000000001')->count());
    }

    public function test_sync_is_idempotent(): void
    {
        $expense = $this->expense('VALID');
        (new SyncExpenseStateFromAade)->sync($expense, 'CANCELLED', '900000000000001');

        $second = (new SyncExpenseStateFromAade)->sync($expense->fresh(), 'CANCELLED', '900000000000001');

        $this->assertFalse($second['changed']);
        $this->assertSame(1, ExpenseMark::query()->where('expense_id', $expense->id)->count());
    }

    public function test_unknown_state_throws(): void
    {
        $this->expectException(RuntimeException::class);
        (new SyncExpenseStateFromAade)->sync($this->expense('VALID'), 'WEIRD');
    }

    public function test_cancellation_without_a_cancellation_mark_is_refused(): void
    {
        // MYD-014 review: a CANCELLED sync WITHOUT the AADE cancellation MARK must
        // throw — never record a cancellation whose evidence (the ΜΑΡΚ ακύρωσης) is
        // missing, and never mutate the expense on that path.
        $expense = $this->expense('VALID');

        try {
            (new SyncExpenseStateFromAade)->sync($expense, 'CANCELLED', null);
            $this->fail('Expected a RuntimeException for CANCELLED without a cancellation MARK.');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $expense->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNull($fresh->cancelled_by_mark);
        $this->assertSame(0, ExpenseMark::query()->where('expense_id', $expense->id)->count());
    }

    public function test_cancellation_with_a_blank_cancellation_mark_is_refused(): void
    {
        // A whitespace-only MARK is not evidence either.
        $this->expectException(RuntimeException::class);
        (new SyncExpenseStateFromAade)->sync($this->expense('VALID'), 'CANCELLED', '   ');
    }

    public function test_valid_direction_clears_cancelled_by_mark(): void
    {
        $expense = $this->expense('CANCELLED');
        $expense->forceFill(['cancelled_by_mark' => '900000000000001'])->save();

        $result = (new SyncExpenseStateFromAade)->sync($expense, 'VALID');

        $this->assertTrue($result['changed']);
        $this->assertSame('VALID', $expense->fresh()->mydata_state);
        $this->assertNull($expense->fresh()->cancelled_by_mark);
    }

    public function test_after_sync_reconciliation_moves_row_from_mismatch_to_matched(): void
    {
        // Acceptance: a supplier cancellation shows as stateMismatch; after the
        // sync, re-running the diff moves it to matched — no re-import, no dup.
        $expense = $this->expense('VALID');

        $aadeCancelled = new AadeDocSummary(
            mark: '400000000000001', uid: 'U', cancelled: true, cancelledByMark: '900000000000001',
            series: 'A', aa: '1', issueDate: '2026-01-10',
            counterpartName: 'ΠΡΟΜΗΘΕΥΤΗΣ', counterpartVat: '998482379', gross: 124.0, net: 100.0, invoiceType: '1.1',
        );

        $before = (new ExpenseReconciler($this->tenant))->diff(
            [$aadeCancelled], $this->localCollection(), '01/01/2026', '31/01/2026',
        );
        $this->assertCount(1, $before->stateMismatch);
        $this->assertCount(0, $before->matched);

        (new SyncExpenseStateFromAade)->sync($expense, 'CANCELLED', '900000000000001');

        $after = (new ExpenseReconciler($this->tenant))->diff(
            [$aadeCancelled], $this->localCollection(), '01/01/2026', '31/01/2026',
        );
        $this->assertCount(0, $after->stateMismatch);
        $this->assertCount(1, $after->matched);
    }

    /** @return Collection<int, Expense> */
    private function localCollection(): Collection
    {
        return Expense::query()
            ->where('company_id', $this->tenant->id)
            ->whereNotNull('mydata_mark')
            ->with('supplier')
            ->get();
    }
}
