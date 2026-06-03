<?php

namespace Tests\Feature\Stock;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\StockMovement;
use App\Models\VatCategory;
use App\Services\Stock\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The stock ledger foundation (S1): on-hand is the SUM of signed movements,
 * opt-in per product, never blocks (negative allowed).
 */
class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private ProductCategory $category;

    private VatCategory $vat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Stock test',
            'slug' => 'stk-'.uniqid(),
            'country_code' => 'GR',
        ]);

        $this->category = ProductCategory::create([
            'company_id' => $this->tenant->id,
            'description_short' => 'Hardware',
            'markup' => 0,
        ]);

        $this->vat = VatCategory::create([
            'company_id' => $this->tenant->id,
            'description' => '24%',
            'rate' => 24,
            'is_default' => true,
        ]);
    }

    private function product(bool $tracked = true): Product
    {
        return Product::create([
            'company_id' => $this->tenant->id,
            'description_short' => 'Δίσκος SSD',
            'product_category_id' => $this->category->id,
            'vat_category_id' => $this->vat->id,
            'track_stock' => $tracked,
        ]);
    }

    public function test_current_stock_is_the_sum_of_movements(): void
    {
        $product = $this->product();
        $svc = app(StockService::class);

        $svc->record($product, 10, StockMovement::REASON_RECEIPT);
        $svc->record($product, -3, StockMovement::REASON_ADJUSTMENT);

        $this->assertSame(7.0, $svc->currentStock($product->fresh()));
    }

    public function test_record_persists_signed_movement_with_fields(): void
    {
        $product = $this->product();

        $mv = app(StockService::class)->record($product, 5, StockMovement::REASON_INITIAL, note: 'απογραφή');

        $this->assertDatabaseHas('stock_movements', [
            'id' => $mv->id,
            'company_id' => $this->tenant->id,
            'product_id' => $product->id,
            'reason' => 'initial',
            'source_type' => null,
            'note' => 'απογραφή',
        ]);
        $this->assertSame(5.0, (float) $mv->qty_change);
    }

    public function test_stock_can_go_negative_never_blocks(): void
    {
        $product = $this->product();
        $svc = app(StockService::class);

        $svc->record($product, 1, StockMovement::REASON_RECEIPT);
        $svc->record($product, -4, StockMovement::REASON_ADJUSTMENT);

        $this->assertSame(-3.0, $svc->currentStock($product->fresh()));
    }

    public function test_untracked_product_reports_zero(): void
    {
        $product = $this->product(tracked: false);
        // even if a stray movement existed, an untracked product reports 0.
        app(StockService::class)->record($product, 99, StockMovement::REASON_RECEIPT);

        $this->assertSame(0.0, app(StockService::class)->currentStock($product->fresh()));
    }
}
