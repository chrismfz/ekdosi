<?php

namespace Tests\Unit;

use App\Support\MyData\CommonTaxPresets;
use Tests\TestCase;

class CommonTaxPresetsTest extends TestCase
{
    public function test_presets_map_to_valid_groups(): void
    {
        foreach (CommonTaxPresets::all() as $preset) {
            $this->assertArrayHasKey($preset['group'], CommonTaxPresets::GROUPS);
            $this->assertIsInt($preset['category']);
        }
        $this->assertArrayHasKey('stamp_3_6', CommonTaxPresets::options());
    }

    public function test_net_from_lines_sums_with_discount(): void
    {
        $net = CommonTaxPresets::netFromLines([
            ['qty' => 2, 'price_per_item' => 100, 'discount' => 0],   // 200
            ['qty' => 1, 'price_per_item' => 100, 'discount' => 10],  // 90
        ]);

        $this->assertSame(290.0, $net);
    }

    public function test_net_from_lines_applies_header_discount(): void
    {
        // 200 line-net − 10% header discount = 180 (matches the filed totalNet base).
        $net = CommonTaxPresets::netFromLines(
            [['qty' => 2, 'price_per_item' => 100, 'discount' => 0]],
            10,
        );

        $this->assertSame(180.0, $net);
    }

    public function test_percentage_preset_computes_amount_from_net(): void
    {
        $stamp = CommonTaxPresets::find('stamp_3_6');
        $this->assertSame('stamp_duty', $stamp['group']);
        $this->assertSame(3, $stamp['category']);
        $this->assertSame(['stamp_duty_amount', 'stamp_duty_category'], CommonTaxPresets::columnsFor($stamp));

        // 3.6% of 1000 = 36.00
        $this->assertSame(36.0, CommonTaxPresets::amountFor($stamp, 1000.0));
    }

    public function test_flat_preset_returns_null_amount(): void
    {
        $accommodation = CommonTaxPresets::find('accommodation');
        $this->assertNull($accommodation['rate']);
        $this->assertNull(CommonTaxPresets::amountFor($accommodation, 1000.0));
    }
}
