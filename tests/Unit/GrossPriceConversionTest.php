<?php

namespace Tests\Unit;

use App\Filament\Resources\Invoices\Schemas\InvoiceForm;
use PHPUnit\Framework\TestCase;

/**
 * G7 gross-price line entry. The form lets an operator type a
 * VAT-inclusive unit price; we back-compute the NET price_per_item
 * (the stored source of truth). These pure helpers carry that math —
 * locking the formula, the 2dp rounding (matching InvoiceLine's
 * decimal:2 columns), and the null pass-through for blank inputs.
 */
class GrossPriceConversionTest extends TestCase
{
    public function test_gross_from_net_applies_vat(): void
    {
        $this->assertSame(124.0, InvoiceForm::grossFromNet(100.0, 24.0));
        $this->assertSame(113.0, InvoiceForm::grossFromNet(100.0, 13.0));
        $this->assertSame(100.0, InvoiceForm::grossFromNet(100.0, 0.0));
    }

    public function test_net_from_gross_strips_vat(): void
    {
        $this->assertSame(100.0, InvoiceForm::netFromGross(124.0, 24.0));
        $this->assertSame(100.0, InvoiceForm::netFromGross(113.0, 13.0));
        $this->assertSame(100.0, InvoiceForm::netFromGross(100.0, 0.0));
    }

    public function test_rounds_to_two_decimals(): void
    {
        // 100 gross @ 24% → 80.6451… → 80.65 net (decimal:2 storage).
        $this->assertSame(80.65, InvoiceForm::netFromGross(100.0, 24.0));
        // 33 net @ 24% → 40.92 gross.
        $this->assertSame(40.92, InvoiceForm::grossFromNet(33.0, 24.0));
    }

    public function test_null_passes_through(): void
    {
        $this->assertNull(InvoiceForm::grossFromNet(null, 24.0));
        $this->assertNull(InvoiceForm::netFromGross(null, 24.0));
    }

    public function test_null_vat_treated_as_zero(): void
    {
        // A missing rate must not divide-by-anything-weird — treat as 0%.
        $this->assertSame(100.0, InvoiceForm::grossFromNet(100.0, null));
        $this->assertSame(100.0, InvoiceForm::netFromGross(100.0, null));
    }

    public function test_gross_edit_round_trip_loss_is_detectable(): void
    {
        // MON-7: 10.00 @24% can't round-trip through 2dp net (8.06) → 9.99. The form
        // warns exactly on this |recomputed − entered| ≥ 0.005 condition.
        $lossyNet = InvoiceForm::netFromGross(10.00, 24.0);
        $this->assertSame(8.06, $lossyNet);
        $this->assertSame(9.99, InvoiceForm::grossFromNet($lossyNet, 24.0));
        $this->assertGreaterThanOrEqual(0.005, abs(InvoiceForm::grossFromNet($lossyNet, 24.0) - 10.00));

        // A clean value round-trips exactly → no warning: 12.40 @24% → net 10.00 → 12.40.
        $cleanNet = InvoiceForm::netFromGross(12.40, 24.0);
        $this->assertSame(10.0, $cleanNet);
        $this->assertSame(12.40, InvoiceForm::grossFromNet($cleanNet, 24.0));
        $this->assertLessThan(0.005, abs(InvoiceForm::grossFromNet($cleanNet, 24.0) - 12.40));
    }
}
