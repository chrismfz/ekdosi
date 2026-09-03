<?php

namespace Tests\Feature\Stock;

use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryNote;
use App\Models\DeliveryNoteLine;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ReturnInvoiceExtra;
use App\Models\StockMovement;
use App\Models\VatCategory;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * S2 — auto stock-OUT on sale (invoice activation + Πώληση δελτίο), whichever-first.
 */
class StockSaleTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Product $tracked;

    private Product $untracked;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Stock sale', 'slug' => 'sale-'.uniqid(), 'country_code' => 'GR',
        ]);
        $cat = ProductCategory::create(['company_id' => $this->tenant->id, 'description_short' => 'HW', 'markup' => 0]);
        $vat = VatCategory::create(['company_id' => $this->tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);

        $this->tracked = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'SSD',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => true,
        ]);
        $this->untracked = Product::create([
            'company_id' => $this->tenant->id, 'description_short' => 'Υπηρεσία',
            'product_category_id' => $cat->id, 'vat_category_id' => $vat->id, 'track_stock' => false,
        ]);

        $this->type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο',
            'invcount' => 1, 'mydata_type' => '1.1',
        ]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης', 'afm' => '123456789']);

        // opening stock
        app(StockService::class)->record($this->tracked, 10, StockMovement::REASON_INITIAL);
    }

    private function draftInvoice(?int $creditedId = null): Invoice
    {
        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'ΤΙΜ'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'issued_at' => now(), 'local_status' => 'draft', 'credited_invoice_id' => $creditedId,
        ]);
    }

    private function line(Invoice $inv, Product $p, float $qty): InvoiceLine
    {
        return InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'product_id' => $p->id,
            'qty' => $qty, 'price_per_item' => 10, 'vat_percent' => 24,
        ]);
    }

    public function test_invoice_activation_decrements_tracked_goods_only(): void
    {
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);
        $this->line($inv, $this->untracked, 5);

        $inv->update(['local_status' => 'active']);

        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // 10 − 3
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->tracked->id, 'reason' => 'sale', 'source_type' => InvoiceLine::class,
        ]);
        // untracked product never gets a movement
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $this->untracked->id, 'reason' => 'sale']);
    }

    public function test_sale_dedup_is_tenant_scoped(): void
    {
        // The idempotency/whichever-first guards run from the InvoiceObserver with
        // NO ambient CompanyContext (the global scope is a no-op there), so they
        // now filter company_id explicitly. Prove it: plant a foreign-tenant
        // movement whose source_id COLLIDES with our line id. source_id is a
        // globally-unique surrogate PK so this can't happen in production — but the
        // synthetic collision shows the dedup query no longer matches across
        // tenants (the unscoped version would have wrongly skipped our sale).
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);

        $other = Company::create(['name' => 'Other', 'slug' => 'oth-'.uniqid(), 'country_code' => 'GR']);
        StockMovement::create([
            'company_id' => $other->id,
            // A different product so it can't pollute currentStock(tracked) — the
            // dedup (lineHasMovement) keys on source_type+source_id, not product.
            'product_id' => $this->untracked->id,
            'qty_change' => -1,
            'reason' => StockMovement::REASON_SALE,
            'source_type' => InvoiceLine::class,
            'source_id' => $line->id,           // the colliding key
            'occurred_at' => now(),
        ]);

        $inv->update(['local_status' => 'active']);

        // Our sale still fires (the foreign movement is filtered out by company_id).
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // 10 − 3
        $this->assertSame(1, StockMovement::query()
            ->where('company_id', $this->tenant->id)
            ->where('source_id', $line->id)
            ->where('reason', StockMovement::REASON_SALE)
            ->count());
    }

    public function test_reactivation_is_idempotent(): void
    {
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);

        $inv->update(['local_status' => 'active']);
        $inv->update(['local_status' => 'draft']);
        $inv->update(['local_status' => 'active']);

        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // −3 once, not −6
    }

    public function test_credit_note_activation_returns_stock(): void
    {
        $original = $this->draftInvoice();
        $this->line($original, $this->tracked, 3);
        $original->update(['local_status' => 'active']);   // sale −3 → 7

        $credit = $this->draftInvoice(creditedId: $original->id);
        $this->line($credit, $this->tracked, 3);
        $credit->update(['local_status' => 'active']);      // return +3 → 10

        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->tracked->id, 'reason' => 'return',
        ]);
    }

    public function test_invoice_cancel_reverses_the_sale(): void
    {
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);    // −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $inv->update(['local_status' => 'cancelled']); // +3 reverse → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        // idempotent: reversing again is a no-op (no double +3).
        app(StockService::class)->reverseSaleForInvoice($inv->fresh());
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_sale_delivery_note_decrements_but_non_sale_does_not(): void
    {
        $sale = $this->makeNote(movePurpose: 1, qty: 2);
        app(StockService::class)->recordSaleForDeliveryNote($sale);
        $this->assertSame(8.0, app(StockService::class)->currentStock($this->tracked->fresh())); // 10 − 2

        $internal = $this->makeNote(movePurpose: 8, qty: 4); // ενδοδιακίνηση
        app(StockService::class)->recordSaleForDeliveryNote($internal);
        $this->assertSame(8.0, app(StockService::class)->currentStock($this->tracked->fresh())); // unchanged
    }

    public function test_whichever_first_linked_pair_counts_once(): void
    {
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);              // −3 via invoice

        $note = $this->makeNote(movePurpose: 1, qty: 3, invoiceId: $inv->id); // linked Πώληση δελτίο
        app(StockService::class)->recordSaleForDeliveryNote($note);

        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // still −3, not −6
    }

    public function test_full_credit_then_cancel_does_not_double_return(): void
    {
        // THE blocker repro: sell 3, fully credit (return +3), then cancel the
        // invoice — must NOT add another +3 (would inflate 10→13).
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);                 // sale −3 → 7

        ReturnInvoiceExtra::create([
            'company_id' => $this->tenant->id,
            'invoice_line_id' => $line->id,
            'qty_returned' => 3,                                     // fully credited
        ]);
        app(StockService::class)->record($this->tracked, 3, StockMovement::REASON_RETURN); // return +3 → 10

        $inv->update(['local_status' => 'cancelled']);              // remainder 3−3=0 → no reverse

        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_partial_credit_then_cancel_reverses_only_remainder(): void
    {
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);                 // sale −3 → 7

        ReturnInvoiceExtra::create([
            'company_id' => $this->tenant->id,
            'invoice_line_id' => $line->id,
            'qty_returned' => 2,                                     // 2 of 3 credited
        ]);
        app(StockService::class)->record($this->tracked, 2, StockMovement::REASON_RETURN); // return +2 → 9

        $inv->update(['local_status' => 'cancelled']);              // remainder 3−2=1 → +1 → 10

        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_delivery_note_cancel_reverses_the_sale(): void
    {
        // STOCK-001: cancelling a Πώληση δελτίο returns its goods to stock.
        $note = $this->makeNote(movePurpose: 1, qty: 2);
        app(StockService::class)->recordSaleForDeliveryNote($note);       // −2 → 8
        $this->assertSame(8.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        app(StockService::class)->reverseSaleForDeliveryNote($note->fresh('lines')); // +2 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        // idempotent: reversing again is a no-op.
        app(StockService::class)->reverseSaleForDeliveryNote($note->fresh('lines'));
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_delivery_note_cancel_reverses_nothing_when_linked_invoice_moved_the_sale(): void
    {
        // whichever-first: the invoice recorded the sale, the linked δελτίο skipped.
        // Cancelling the δελτίο must reverse NOTHING — the sale belongs to the invoice.
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);                       // −3 via invoice → 7

        $note = $this->makeNote(movePurpose: 1, qty: 3, invoiceId: $inv->id);
        app(StockService::class)->recordSaleForDeliveryNote($note);       // no-op (group already moved)
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        app(StockService::class)->reverseSaleForDeliveryNote($note->fresh('lines'));
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // unchanged
    }

    public function test_credit_note_cancel_reverses_the_return(): void
    {
        // STOCK-001: cancelling a credit note reverses its return-IN (goods did not
        // actually come back). Driven through the observer's cancelled branch.
        $original = $this->draftInvoice();
        $this->line($original, $this->tracked, 3);
        $original->update(['local_status' => 'active']);                  // sale −3 → 7

        $credit = $this->draftInvoice(creditedId: $original->id);
        $this->line($credit, $this->tracked, 3);
        $credit->update(['local_status' => 'active']);                    // return +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $credit->update(['local_status' => 'cancelled']);                // reverse return −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        // idempotent
        app(StockService::class)->reverseReturnForCreditNote($credit->fresh());
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_cancel_credit_note_then_cancel_invoice_does_not_inflate_stock(): void
    {
        // THE interaction repro. Without reversing the credit note's return-IN, the
        // freed qty_returned (MON-1) lets the invoice-cancel reverse the FULL sale
        // again → inflates 10→13. Both moves together must net to the opening 10.
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);                       // sale −3 → 7

        $credit = $this->draftInvoice(creditedId: $inv->id);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id, 'product_id' => $this->tracked->id,
            'qty' => 3, 'price_per_item' => 10, 'vat_percent' => 24,
            'original_line_id' => $line->id,                              // so MON-1 tracks qty_returned
        ]);
        $credit->update(['local_status' => 'active']);                    // return +3 → 10, qty_returned=3
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
        $this->assertSame(3.0, (float) ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned'));

        $credit->update(['local_status' => 'cancelled']);                // reverse return −3 → 7, qty_returned freed → 0
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));
        $this->assertSame(0.0, (float) (ReturnInvoiceExtra::where('invoice_line_id', $line->id)->value('qty_returned') ?? 0));

        $inv->update(['local_status' => 'cancelled']);                   // reverse full sale 3−0=3 → +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_cancel_invoice_then_cancel_credit_note_does_not_understate_stock(): void
    {
        // MIRROR order of the test above (P1 regression guard). Cancelling the
        // ORIGINAL first defers its sale-reversal (remainder 0 while the return is
        // live); cancelling the credit note afterwards must NOT strand that sale —
        // reverseReturnForCreditNote skips when the original is cancelled. Reachable
        // in prod: the myDATA-side cancel («Ακύρωση μέσω myDATA») is NOT gated on a
        // live credit note the way the local cancel is. Stock must stay at opening 10.
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);                       // sale −3 → 7

        $credit = $this->draftInvoice(creditedId: $inv->id);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id, 'product_id' => $this->tracked->id,
            'qty' => 3, 'price_per_item' => 10, 'vat_percent' => 24, 'original_line_id' => $line->id,
        ]);
        $credit->update(['local_status' => 'active']);                    // return +3 → 10, qty_returned=3

        $inv->update(['local_status' => 'cancelled']);                    // sale reversal deferred (remainder 0) → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $credit->update(['local_status' => 'cancelled']);                // must SKIP (original cancelled) → stays 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_invoice_cancel_then_revive_reapplies_the_sale(): void
    {
        // STOCK-001 revive edge: Ακύρωση→Επαναφορά must re-apply the sale-out. Before
        // the fix recordSaleForInvoice skipped on revive (REASON_SALE already present)
        // while the cancel's +qty compensation stood → stock stuck at 10 instead of 7.
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);

        $inv->update(['local_status' => 'active']);      // sale −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $inv->update(['local_status' => 'cancelled']);   // reverse +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $inv->update(['local_status' => 'active']);      // REVIVE: re-apply −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $this->tracked->id, 'reason' => 'revive', 'source_type' => InvoiceLine::class,
        ]);
    }

    public function test_credit_note_cancel_then_revive_reapplies_the_return(): void
    {
        // The mirror for a credit note: return-IN +qty, cancelled −qty, revived +qty.
        $inv = $this->draftInvoice();
        $line = $this->line($inv, $this->tracked, 3);
        $inv->update(['local_status' => 'active']);      // sale −3 → 7

        $credit = $this->draftInvoice(creditedId: $inv->id);
        InvoiceLine::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $credit->id, 'product_id' => $this->tracked->id,
            'qty' => 3, 'price_per_item' => 10, 'vat_percent' => 24, 'original_line_id' => $line->id,
        ]);
        $credit->update(['local_status' => 'active']);    // return +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $credit->update(['local_status' => 'cancelled']); // reverse return −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $credit->update(['local_status' => 'active']);    // REVIVE: re-apply return +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    public function test_invoice_cancel_revive_recancel_is_idempotent(): void
    {
        // The compensation must be NET-aware: each revive returns the balance to
        // zero so a SECOND cancel compensates afresh. An existence-keyed guard would
        // skip the re-cancel and leave stock stuck at 7.
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);

        $inv->update(['local_status' => 'active']);      // −3 → 7
        $inv->update(['local_status' => 'cancelled']);   // +3 → 10
        $inv->update(['local_status' => 'active']);      // revive −3 → 7
        $inv->update(['local_status' => 'cancelled']);   // re-cancel +3 → 10
        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh()));

        $inv->update(['local_status' => 'active']);      // revive again −3 → 7
        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh()));
    }

    private function makeNote(int $movePurpose, float $qty, ?int $invoiceId = null): DeliveryNote
    {
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id, 'delivery_type_id' => $this->type->id,
            'invcode' => 'ΔΑΠ'.uniqid(), 'code' => random_int(1, 99999), 'issued_at' => now(),
            'mydata_type' => '9.3', 'move_purpose' => $movePurpose, 'local_status' => 'active',
            'invoice_id' => $invoiceId,
        ]);
        DeliveryNoteLine::create([
            'company_id' => $this->tenant->id, 'delivery_note_id' => $note->id,
            'product_id' => $this->tracked->id, 'qty' => $qty,
        ]);

        return $note->fresh('lines');
    }
}
