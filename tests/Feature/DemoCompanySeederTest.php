<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoCompanySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_self_contained_demo_company(): void
    {
        // SET-1: the demo tenant is opt-in (prod-safe). Enable it for this test.
        config(['ekdosi.seed_demo' => true]);
        $this->seed(DemoCompanySeeder::class);

        $demo = Company::where('slug', 'demo')->firstOrFail();
        $this->assertSame('off', $demo->mydata_mode);
        $this->assertSame(3, Invoice::where('company_id', $demo->id)->count());
        $this->assertSame(2, DeliveryNote::where('company_id', $demo->id)->count());

        // The withholding invoice computed a non-zero amount from the carried rate.
        $wh = Invoice::where('company_id', $demo->id)->whereNotNull('withhold_rate')->first();
        $this->assertNotNull($wh);
        $this->assertGreaterThan(0, (float) $wh->withhold_amount);

        // The product-linked-fee invoice (ΑΠΥ with «Διανυκτέρευση») has a fee.
        $feeInv = Invoice::where('company_id', $demo->id)->where('fees_amount', '>', 0)->first();
        $this->assertNotNull($feeInv, 'product-linked fee should have produced fees_amount');

        // Idempotent.
        $this->seed(DemoCompanySeeder::class);
        $this->assertSame(1, Company::where('slug', 'demo')->count());
    }

    public function test_it_is_a_no_op_without_the_opt_in(): void
    {
        // SET-1: a direct `db:seed --class=DemoCompanySeeder` must ALSO respect the
        // opt-in — no demo tenant (and no super_admin escalation) on a real host.
        config(['ekdosi.seed_demo' => false]);
        $this->seed(DemoCompanySeeder::class);

        $this->assertSame(0, Company::count());
    }
}
