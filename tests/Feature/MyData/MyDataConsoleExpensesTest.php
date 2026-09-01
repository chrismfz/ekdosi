<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsoleExpenses;
use App\Models\Company;
use App\Models\Expense;
use App\Models\User;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Page wiring for the expenses console (E4): both directions, the inbound
 * orphan rendering, and the import action gating. The live fetch hits AADE,
 * so we inject a serialized result (the shape serialize() produces) rather
 * than calling AADE.
 */
class MyDataConsoleExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenantUser(string $provider = 'gr-mydata', string $mode = 'sandbox'): Company
    {
        $tenant = Company::create([
            'name' => 'Exp console',
            'slug' => 'expcon-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => $provider,
            'mydata_mode' => $mode,
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function fakeResult(): array
    {
        $orphan = [
            'mark' => '400012434052701', 'uid' => null, 'expenseId' => null, 'invcode' => null,
            'issuedAt' => '15/01/2026', 'counterpartName' => 'ΑΛΦΑΝΕΤ ΑΕ', 'gross' => 174.0,
            'localState' => null, 'localStatus' => null, 'aadeState' => 'VALID',
            'cancelledByMark' => null, 'problem' => 'Στο myDATA, χωρίς τοπική εγγραφή.', 'url' => null,
        ];

        return [
            'from' => '01/01/2026', 'to' => '31/01/2026',
            'aadeTotal' => 1, 'localTotal' => 0, 'discrepancyCount' => 1,
            'matched' => [], 'stateMismatch' => [],
            'missingAtAade' => [], 'missingLocally' => [$orphan], 'duplicateLocal' => [],
        ];
    }

    public function test_console_exposes_one_reconcile_plus_the_write_actions(): void
    {
        $this->bootTenantUser();

        // The two read buttons collapsed into one «Έλεγχος»; the writes
        // (self-declared + orphan import) stay as their own actions.
        Livewire::test(MyDataConsoleExpenses::class)
            ->assertOk()
            ->assertActionExists('reconcile')
            ->assertActionDoesNotExist('find_orphans')
            ->assertActionExists('import_self_declared');
    }

    public function test_inbound_view_highlights_orphan_expenses_and_offers_import(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsoleExpenses::class)
            ->set('ran', true)
            ->set('resultMode', 'inbound')
            ->set('fromLabel', '01/01/2026')
            ->set('toLabel', '31/01/2026')
            ->set('result', $this->fakeResult())
            ->assertSee('Αδέσποτα έξοδα')
            ->assertSee('400012434052701')      // the orphan MARK
            ->assertSee('ΑΛΦΑΝΕΤ ΑΕ')           // supplier name column
            ->assertActionVisible('import_orphans');
    }

    public function test_import_action_hidden_without_orphans(): void
    {
        $this->bootTenantUser();

        $empty = $this->fakeResult();
        $empty['missingLocally'] = [];

        Livewire::test(MyDataConsoleExpenses::class)
            ->set('ran', true)
            ->set('resultMode', 'inbound')
            ->set('result', $empty)
            ->assertActionHidden('import_orphans');
    }

    public function test_non_mydata_tenant_cannot_access(): void
    {
        $this->bootTenantUser(provider: 'none', mode: 'off');

        $this->assertFalse(MyDataConsoleExpenses::canAccess());
    }

    /**
     * Regression: importing then refreshing must survive a d/m/Y window whose
     * day > 12. The reconciler outputs d/m/Y; an earlier version round-tripped
     * it through Carbon::parse (reads '/' as m/d/Y) → InvalidFormatException on
     * day 15. The action parses with createFromFormat and refreshes via Y-m-d.
     */
    public function test_import_orphans_handles_dmy_window_and_creates_expense(): void
    {
        $tenant = $this->bootTenantUser();

        // Two responses: one for the import fetch, one for the refresh fetch.
        // Two responses: one for the import fetch, one for the refresh fetch.
        MyDataConsoleExpenses::$testHandler = new MockHandler([
            new Response(200, [], $this->orphanDoc()),
            new Response(200, [], $this->orphanDoc()),
        ]);

        try {
            Livewire::test(MyDataConsoleExpenses::class)
                ->set('ran', true)
                ->set('resultMode', 'inbound')
                ->set('fromLabel', '15/01/2026')   // day > 12 → the crash case
                ->set('toLabel', '20/01/2026')
                ->set('result', $this->fakeResult())
                ->callAction('import_orphans')
                ->assertHasNoErrors();
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        // The αδέσποτο was recorded (import ran) and the refresh didn't crash.
        $this->assertDatabaseHas('expenses', [
            'company_id' => $tenant->id,
            'mydata_mark' => '400012434052701',
        ]);
    }

    public function test_sync_states_action_hidden_without_state_mismatches(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsoleExpenses::class)
            ->set('ran', true)
            ->set('resultMode', 'both')
            ->set('result', $this->fakeResult())   // stateMismatch is empty
            ->assertActionHidden('sync_states');
    }

    public function test_sync_states_applies_aade_cancellation_to_the_local_expense(): void
    {
        // MYD-014: a supplier cancellation surfaces as a stateMismatch; the operator
        // action flips OUR existing expense to CANCELLED (audited), no re-import.
        $tenant = $this->bootTenantUser();

        $expense = Expense::create([
            'company_id' => $tenant->id,
            'mydata_mark' => '400000000000001',
            'mydata_state' => 'VALID',
            'issue_date' => '2026-01-10',
            'series' => 'A', 'aa' => '1', 'invoice_type' => '1.1',
            'supplier_afm' => '998482379', 'gross_total' => '124.00',
            'source' => 'sync',
        ]);

        // The cached snapshot only makes the button VISIBLE; the sync itself trusts
        // NONE of it — it re-reconciles against AADE and acts on the fresh row
        // (MYD-014 review). The serialized stateMismatch below is deliberately
        // present so the button shows, but its values are never applied directly.
        $result = $this->fakeResult();
        $result['stateMismatch'] = [[
            'mark' => '400000000000001', 'uid' => null, 'expenseId' => $expense->id, 'invcode' => 'A 1',
            'issuedAt' => '10/01/2026', 'counterpartName' => 'ΠΡΟΜΗΘΕΥΤΗΣ', 'afm' => '998482379', 'gross' => 124.0,
            'localState' => 'VALID', 'localStatus' => null, 'aadeState' => 'CANCELLED',
            'cancelledByMark' => '900000000000001', 'problem' => 'Ακυρωμένο στο AADE.', 'url' => null,
        ]];
        $result['missingLocally'] = [];
        $result['discrepancyCount'] = 1;

        // TWO fetches: the fresh reconcile inside syncStates(), then the post-sync
        // display refresh. Both return the doc as cancelled.
        MyDataConsoleExpenses::$testHandler = new MockHandler([
            new Response(200, [], $this->cancelledDoc()),
            new Response(200, [], $this->cancelledDoc()),
        ]);

        try {
            Livewire::test(MyDataConsoleExpenses::class)
                ->set('ran', true)
                ->set('resultMode', 'both')
                ->set('fromLabel', '01/01/2026')
                ->set('toLabel', '31/01/2026')
                ->set('result', $result)
                ->assertActionVisible('sync_states')
                ->callAction('sync_states')
                ->assertHasNoErrors();
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        $fresh = $expense->fresh();
        $this->assertSame('CANCELLED', $fresh->mydata_state);
        $this->assertSame('900000000000001', $fresh->cancelled_by_mark);
        $this->assertDatabaseHas('expense_marks', [
            'expense_id' => $expense->id,
            'mydata_action' => 'STATE_SYNC',
        ]);
    }

    public function test_sync_states_ignores_a_stale_cached_row_absent_from_fresh_aade(): void
    {
        // MYD-014 review: if the (up-to-12h) cached snapshot claims a stateMismatch
        // but a FRESH reconcile no longer reports it (supplier re-filed, MARK gone,
        // or a tampered snapshot), NOTHING is mutated — the sync acts only on fresh
        // AADE truth.
        $tenant = $this->bootTenantUser();

        $expense = Expense::create([
            'company_id' => $tenant->id,
            'mydata_mark' => '400000000000001',
            'mydata_state' => 'VALID',
            'issue_date' => '2026-01-10',
            'series' => 'A', 'aa' => '1', 'invoice_type' => '1.1',
            'supplier_afm' => '998482379', 'gross_total' => '124.00',
            'source' => 'sync',
        ]);

        $result = $this->fakeResult();
        $result['stateMismatch'] = [[
            'mark' => '400000000000001', 'uid' => null, 'expenseId' => $expense->id, 'invcode' => 'A 1',
            'issuedAt' => '10/01/2026', 'counterpartName' => 'ΠΡΟΜΗΘΕΥΤΗΣ', 'afm' => '998482379', 'gross' => 124.0,
            'localState' => 'VALID', 'localStatus' => null, 'aadeState' => 'CANCELLED',
            'cancelledByMark' => '900000000000001', 'problem' => 'Ακυρωμένο στο AADE.', 'url' => null,
        ]];
        $result['missingLocally'] = [];
        $result['discrepancyCount'] = 1;

        // Fresh AADE now reports the SAME doc as VALID (no cancellation) → no
        // stateMismatch → no sync. Two identical responses (reconcile + refresh).
        MyDataConsoleExpenses::$testHandler = new MockHandler([
            new Response(200, [], $this->validDoc()),
            new Response(200, [], $this->validDoc()),
        ]);

        try {
            Livewire::test(MyDataConsoleExpenses::class)
                ->set('ran', true)
                ->set('resultMode', 'both')
                ->set('fromLabel', '01/01/2026')
                ->set('toLabel', '31/01/2026')
                ->set('result', $result)
                ->callAction('sync_states')
                ->assertHasNoErrors();
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        $fresh = $expense->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNull($fresh->cancelled_by_mark);
        $this->assertDatabaseMissing('expense_marks', [
            'expense_id' => $expense->id,
            'mydata_action' => 'STATE_SYNC',
        ]);
    }

    private function cancelledDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400000000000001</mark>
            <cancelledByMark>900000000000001</cancelledByMark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΠΡΟΜΗΘΕΥΤΗΣ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    public function test_sync_states_skips_a_cancellation_without_a_mark_without_aborting(): void
    {
        // MYD-014 review (finding 1): AADE reports the doc CANCELLED via the standalone
        // list but WITHOUT a cancellation MARK. SyncExpenseStateFromAade refuses that
        // (no evidence); syncStates() must SKIP the row — not let the throw abort the
        // whole batch — leaving the expense untouched and no audit row written.
        $tenant = $this->bootTenantUser();

        $expense = Expense::create([
            'company_id' => $tenant->id,
            'mydata_mark' => '400000000000001',
            'mydata_state' => 'VALID',
            'issue_date' => '2026-01-10',
            'series' => 'A', 'aa' => '1', 'invoice_type' => '1.1',
            'supplier_afm' => '998482379', 'gross_total' => '124.00',
            'source' => 'sync',
        ]);

        $result = $this->fakeResult();
        $result['stateMismatch'] = [[
            'mark' => '400000000000001', 'uid' => null, 'expenseId' => $expense->id, 'invcode' => 'A 1',
            'issuedAt' => '10/01/2026', 'counterpartName' => 'ΠΡΟΜΗΘΕΥΤΗΣ', 'afm' => '998482379', 'gross' => 124.0,
            'localState' => 'VALID', 'localStatus' => null, 'aadeState' => 'CANCELLED',
            'cancelledByMark' => null, 'problem' => 'Ακυρωμένο στο AADE.', 'url' => null,
        ]];
        $result['missingLocally'] = [];
        $result['discrepancyCount'] = 1;

        // Fresh reconcile + refresh: both report CANCELLED-without-cancellation-MARK.
        MyDataConsoleExpenses::$testHandler = new MockHandler([
            new Response(200, [], $this->cancelledNoMarkDoc()),
            new Response(200, [], $this->cancelledNoMarkDoc()),
        ]);

        try {
            Livewire::test(MyDataConsoleExpenses::class)
                ->set('ran', true)
                ->set('resultMode', 'both')
                ->set('fromLabel', '01/01/2026')
                ->set('toLabel', '31/01/2026')
                ->set('result', $result)
                ->callAction('sync_states')
                ->assertHasNoErrors();   // the batch did NOT abort
        } finally {
            MyDataConsoleExpenses::$testHandler = null;
        }

        // The evidence-less row was skipped: expense untouched, no audit row.
        $fresh = $expense->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertNull($fresh->cancelled_by_mark);
        $this->assertDatabaseMissing('expense_marks', [
            'expense_id' => $expense->id,
            'mydata_action' => 'STATE_SYNC',
        ]);
    }

    private function cancelledNoMarkDoc(): string
    {
        // The doc is present as VALID in <invoicesDoc>, then listed in
        // <cancelledInvoicesDoc> with NO <cancellationMark> — so the fold marks it
        // cancelled but carries a null cancelledByMark (the evidence-less case).
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400000000000001</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΠΡΟΜΗΘΕΥΤΗΣ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
    <cancelledInvoicesDoc>
        <cancelledInvoice>
            <invoiceMark>400000000000001</invoiceMark>
            <cancellationDate>2026-01-13</cancellationDate>
        </cancelledInvoice>
    </cancelledInvoicesDoc>
</RequestedDoc>
XML;
    }

    private function validDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400000000000001</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΠΡΟΜΗΘΕΥΤΗΣ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>1</aa><issueDate>2026-01-10</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceSummary><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }

    private function orphanDoc(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc>
        <invoice>
            <mark>400012434052701</mark>
            <issuer><vatNumber>998482379</vatNumber><country>GR</country><name>ΑΛΦΑΝΕΤ ΑΕ</name></issuer>
            <counterpart><vatNumber>801280908</vatNumber><country>GR</country></counterpart>
            <invoiceHeader><series>A</series><aa>42</aa><issueDate>2026-01-15</issueDate><invoiceType>1.1</invoiceType></invoiceHeader>
            <invoiceDetails>
                <lineNumber>1</lineNumber><netValue>100.00</netValue><vatCategory>1</vatCategory><vatAmount>24.00</vatAmount>
            </invoiceDetails>
            <invoiceSummary><totalNetValue>100.00</totalNetValue><totalVatAmount>24.00</totalVatAmount><totalGrossValue>124.00</totalGrossValue></invoiceSummary>
        </invoice>
    </invoicesDoc>
</RequestedDoc>
XML;
    }
}
