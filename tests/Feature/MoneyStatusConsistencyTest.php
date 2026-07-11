<?php

namespace Tests\Feature;

use App\Actions\IssueCreditNote;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\CustomerLedger\CustomerLedgerBuilder;
use App\Services\Dashboard\DashboardMetrics;
use App\Services\InvoiceBalance;
use App\Services\RecomputeInvoiceTotals;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cross-surface CONSISTENCY checks. The independent review found that
 * most bugs were one money surface disagreeing with another (ledger
 * counted soft-deleted payments; dashboard double-counted credit notes;
 * ledger counted cancelled credit notes). The unit tests missed them
 * because each surface was tested in isolation.
 *
 * These tests build randomized-but-deterministic scenarios (sales,
 * allocated + on-account payments, partial/full credit notes, cancels,
 * soft-deletes) and assert the THREE surfaces stay mutually consistent:
 *
 *   INVARIANT A: DashboardMetrics::outstandingReceivables(tenant)
 *                == Σ over customers of CustomerLedgerBuilder balance.
 *   INVARIANT B: every invoice's cached {paid_total, credited_total,
 *                payment_status} == a freshly-computed InvoiceBalance.
 *   INVARIANT C: Σ invoices.credited_total == Σ gross of non-cancelled
 *                credit notes (the cache equals the live truth).
 *
 * Amounts are kept exact-2dp (unit gross 124.00) so equality is exact.
 * Credit notes target ONLY credit-term originals — crediting a cash-term
 * original is a separately-documented FIFO fuzziness, deliberately
 * excluded so this invariant stays clean.
 */
class MoneyStatusConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;
    private PaymentMethod $credit;
    private InvoiceType $saleType;
    private InvoiceType $creditType;

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
        $this->saleType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $this->creditType = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'Πιστωτικό', 'code' => 'ΠΤ',
            'invcount' => 1, 'is_credit' => true,
        ]);
    }

    /** A credit-term sale with one line of `qty` units (unit gross 124.00). */
    private function makeSale(Customer $c, int $qty): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->saleType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            'local_status' => 'active',   // MON-5: an issued sale (drafts don't count)
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => $qty, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'W',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    /** A credit-term sale (line net 100 → gross 124) carrying 20% withholding. */
    private function makeWithholdingSale(Customer $c): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->saleType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            'withhold_rate' => 20, 'withhold_category' => 1,   // §8.4 cat 1 → reduces the collectible
            'local_status' => 'active',   // MON-5: an issued sale
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24, 'product_descr' => 'W',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    public function test_withholding_reduces_the_receivable_consistently_across_all_three_surfaces(): void
    {
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'WH', 'afm' => '199999999']);
        $inv = $this->makeWithholdingSale($c)->refresh();

        // gross = net+VAT (124); payable = collectible (124 − 20 withholding = 104).
        $this->assertSame('124.00', (string) $inv->gross_total);
        $this->assertSame('104.00', (string) $inv->payable_total);

        // All three money surfaces agree on the REDUCED receivable (104, not 124).
        $this->assertInvariants(collect([$c]));

        $dashboard = (new DashboardMetrics($this->tenant))->outstandingReceivables();
        $ledger = app(CustomerLedgerBuilder::class)->build($c)->stats['balance'];
        $this->assertEqualsWithDelta(104.0, $dashboard, 0.001);
        $this->assertEqualsWithDelta(104.0, $ledger, 0.001);
    }

    /** A STANDALONE legacy credit note (is_credit type, NO credited_invoice_id). */
    private function makeStandaloneCreditNote(Customer $c, int $netPerUnit): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΠΤ'.uniqid(), 'code' => 1,
            'invoice_type_id' => $this->creditType->id, 'customer_id' => $c->id,
            'payment_method_id' => $this->credit->id, 'issued_at' => '2026-05-10 10:00:00',
            // deliberately NO credited_invoice_id — this is how the ETL imports a
            // legacy ΠΙΣ: a credit-TYPE document with no correlation row.
        ]);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id,
            'qty' => 1, 'price_per_item' => $netPerUnit, 'vat_percent' => 24, 'product_descr' => 'ΠΙΣ',
        ]);

        return app(RecomputeInvoiceTotals::class)($inv);
    }

    public function test_standalone_legacy_credit_note_reduces_all_three_receivables_surfaces(): void
    {
        // MON-9: a standalone legacy credit note has no original carrying a
        // credited_total, so the SQL AR surfaces can't net it via credited_total —
        // they must subtract its payable directly to match the ledger (which
        // reduces the balance by EVERY credit note).
        $c = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΠΙΣ', 'afm' => '188888888']);

        $this->makeSale($c, 1);                          // credit-term sale: gross/payable 124
        $this->makeStandaloneCreditNote($c, 50)->refresh(); // standalone ΠΙΣ: gross/payable 62

        // Ledger: 124 − 62 = 62.
        $ledger = app(CustomerLedgerBuilder::class)->build($c)->stats['balance'];
        $this->assertEqualsWithDelta(62.0, $ledger, 0.001);

        // Dashboard headline: 124 owed − 62 standalone credit − 0 paid = 62.
        $dashboard = (new DashboardMetrics($this->tenant))->outstandingReceivables();
        $this->assertEqualsWithDelta(62.0, $dashboard, 0.001);

        // Per-customer scope (powers the debtor table / CustomersTable): also 62.
        $scoped = Customer::query()
            ->where('customers.company_id', $this->tenant->id)
            ->withOutstandingBalance($this->tenant->id)
            ->where('customers.id', $c->id)
            ->first();
        $this->assertEqualsWithDelta(62.0, (float) $scoped->outstanding_balance, 0.001);

        // And the full cross-surface invariant (dashboard == Σ ledger) holds.
        $this->assertInvariants(collect([$c]));
    }

    public function test_surfaces_stay_consistent_across_randomized_scenarios(): void
    {
        $customers = collect();
        $custSeq = 0;

        // Several deterministic seeds → broad coverage, not one lucky path.
        foreach ([20260528, 1, 42, 777, 31337] as $seed) {
            mt_srand($seed);
            $batch = collect(range(1, 5))->map(function () use (&$custSeq) {
                $custSeq++;

                return Customer::create([
                    'company_id' => $this->tenant->id, 'name' => "C{$custSeq}",
                    'afm' => (string) (100000000 + $custSeq),
                ]);
            });
            $customers = $customers->merge($batch);
            $this->generateScenario($batch);
        }
        unset($seed);

        $this->assertInvariants($customers);
    }

    /** @param \Illuminate\Support\Collection<int, Customer> $customers */
    private function generateScenario($customers): void
    {
        foreach ($customers as $c) {
            $sales = [];
            foreach (range(1, mt_rand(2, 4)) as $_) {
                $sales[] = $this->makeSale($c, mt_rand(1, 3));   // gross = 124 × qty
            }

            // Payments: some allocated to a sale, some on-account, some
            // then soft-deleted.
            foreach (range(1, mt_rand(0, 4)) as $_) {
                $onAccount = mt_rand(0, 1) === 1;
                $target = $onAccount ? null : $sales[array_rand($sales)];
                $p = Payment::create([
                    'company_id' => $this->tenant->id, 'customer_id' => $c->id,
                    'invoice_id' => $target?->id,
                    'amount' => mt_rand(1, 200), 'pay_date' => '2026-05-12',
                ]);
                if (mt_rand(0, 3) === 0) {
                    $p->delete();   // soft delete — must drop out everywhere
                }
            }

            // Credit notes against credit-term sales: partial or full,
            // some then cancelled.
            foreach (range(1, mt_rand(0, 2)) as $_) {
                /** @var Invoice $sale */
                $sale = $sales[array_rand($sales)];
                $line = $sale->lines()->first();
                $remaining = (int) $line->qty
                    - (int) (\App\Models\ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned') ?? 0);
                if ($remaining < 1) {
                    continue;
                }
                $creditQty = mt_rand(1, $remaining);
                $credit = app(IssueCreditNote::class)($sale, $this->creditType, [
                    ['line_id' => $line->id, 'qty' => $creditQty],
                ]);
                if (mt_rand(0, 3) === 0) {
                    $credit->forceFill(['mydata_state' => 'CANCELLED'])->save();   // observer reverts original
                }
            }

            // Occasionally a standalone legacy credit note (is_credit type, no
            // credited_invoice_id — the ETL shape). Must reduce the balance on
            // all surfaces exactly like a correlated one.
            if (mt_rand(0, 2) === 0) {
                $this->makeStandaloneCreditNote($c, mt_rand(10, 80));
            }

            // Occasionally cancel a sale locally (detach payments →
            // on-account), mimicking the cancel_local action. Skip sales
            // that have credit notes (cancelling those is a rare edge the
            // ledger/dashboard handle differently — excluded to keep the
            // invariant exact).
            if (mt_rand(0, 2) === 0) {
                /** @var Invoice $sale */
                $sale = $sales[array_rand($sales)];
                $hasCredit = Invoice::where('credited_invoice_id', $sale->id)->exists();
                if (! $hasCredit && $sale->local_status !== 'cancelled') {
                    \App\Models\Payment::where('invoice_id', $sale->id)->get()->each->update(['invoice_id' => null]);
                    $sale->update(['local_status' => 'cancelled']);
                }
            }
        }
    }

    /** @param \Illuminate\Support\Collection<int, Customer> $customers */
    private function assertInvariants($customers): void
    {
        // The backfill must be a no-op — caches were kept fresh by the
        // observers + recompute as data was created.
        $this->artisan('invoices:recompute-balances', ['--company' => $this->tenant->id])->assertSuccessful();

        // INVARIANT A: dashboard == Σ per-customer ledger balance.
        $dashboard = (new DashboardMetrics($this->tenant))->outstandingReceivables();
        $ledgerSum = round($customers->sum(
            fn (Customer $c) => app(CustomerLedgerBuilder::class)->build($c)->stats['balance']
        ), 2);
        $this->assertEqualsWithDelta($ledgerSum, $dashboard, 0.001,
            "Dashboard receivables ({$dashboard}) must equal Σ ledger balances ({$ledgerSum}).");

        // INVARIANT B + C across every invoice.
        $balance = app(InvoiceBalance::class);
        $sumCredited = 0.0;
        $sumNonCancelledCreditNoteGross = 0.0;

        foreach (Invoice::where('company_id', $this->tenant->id)->get() as $inv) {
            $live = $balance->for($inv);

            // B: cache == freshly computed.
            $this->assertEqualsWithDelta($live->paid, (float) $inv->paid_total, 0.001,
                "paid_total cache stale on {$inv->invcode}");
            $this->assertEqualsWithDelta($live->credited, (float) $inv->credited_total, 0.001,
                "credited_total cache stale on {$inv->invcode}");
            $this->assertSame($live->status->value, $inv->payment_status,
                "payment_status cache stale on {$inv->invcode}");

            $sumCredited += (float) $inv->credited_total;

            $isCreditNote = $inv->credited_invoice_id !== null;
            $isCancelled = $inv->mydata_state === 'CANCELLED';
            if ($isCreditNote && ! $isCancelled) {
                $sumNonCancelledCreditNoteGross += (float) $inv->gross_total;
            }
        }

        // C: Σ credited_total == Σ gross of non-cancelled credit notes.
        $this->assertEqualsWithDelta($sumNonCancelledCreditNoteGross, $sumCredited, 0.001,
            'Σ credited_total must equal Σ gross of non-cancelled credit notes.');
    }
}
