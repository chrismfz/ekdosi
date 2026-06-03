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

    public function test_reactivation_is_idempotent(): void
    {
        $inv = $this->draftInvoice();
        $this->line($inv, $this->tracked, 3);

        $inv->update(['local_status' => 'active']);
        $inv->update(['local_status' => 'draft']);
        $inv->update(['local_status' => 'active']);

        $this->assertSame(7.0, app(StockService::class)->currentStock($this->tracked->fresh())); // −3 once, not −6
    }

    public function test_credit_note_activation_does_not_decrement(): void
    {
        $original = $this->draftInvoice();
        $credit = $this->draftInvoice(creditedId: $original->id);
        $this->line($credit, $this->tracked, 3);

        $credit->update(['local_status' => 'active']);

        $this->assertSame(10.0, app(StockService::class)->currentStock($this->tracked->fresh())); // untouched
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
