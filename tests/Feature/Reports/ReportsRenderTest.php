<?php

namespace Tests\Feature\Reports;

use App\Filament\Pages\Reports;
use App\Filament\Reports\Widgets\ProjectionChart;
use App\Filament\Reports\Widgets\ReceiptsByMonthChart;
use App\Filament\Reports\Widgets\ReportKpis;
use App\Filament\Reports\Widgets\RevenueByMonthChart;
use App\Filament\Reports\Widgets\RevenueByYearChart;
use App\Filament\Reports\Widgets\SeasonalCurveChart;
use App\Filament\Reports\Widgets\SeasonalityHeatmap;
use App\Filament\Reports\Widgets\VatByRateQuarterTable;
use App\Filament\Reports\Widgets\YearVsYearChart;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Render smoke tests for the Reports dashboard. Widgets can't be eyeballed
 * in this sandbox (no browser), so we boot each one through Livewire to
 * catch blade / Filament wiring breakage — especially the custom-view
 * SeasonalityHeatmap, which a unit test on DashboardMetrics can't cover.
 */
class ReportsRenderTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-05-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'T', 'slug' => 't-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1,
        ]);

        // A couple of years of data so every widget has something to draw.
        foreach (['2024-03-10', '2025-07-10', '2026-02-10'] as $i => $date) {
            Invoice::create([
                'company_id' => $this->tenant->id, 'invcode' => 'ΤΠΥ'.$i, 'code' => $i + 1,
                'invoice_type_id' => $type->id, 'issued_at' => $date.' 10:00:00',
                'net_total' => 100 * ($i + 1), 'gross_total' => 124 * ($i + 1),
                'local_status' => 'active',   // MON-5: issued
            ]);
        }

        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_page_renders(): void
    {
        Livewire::test(Reports::class)->assertOk();
    }

    public function test_every_widget_renders(): void
    {
        $widgets = [
            ReportKpis::class,
            RevenueByMonthChart::class,
            ReceiptsByMonthChart::class,
            YearVsYearChart::class,
            RevenueByYearChart::class,
            VatByRateQuarterTable::class,
            SeasonalCurveChart::class,
            ProjectionChart::class,
            SeasonalityHeatmap::class,
        ];

        foreach ($widgets as $widget) {
            Livewire::test($widget)->assertOk();
        }
    }

    public function test_heatmap_renders_the_value_table(): void
    {
        // The 2026 cell (net 100) must appear in the colour-scaled table.
        Livewire::test(SeasonalityHeatmap::class)
            ->assertOk()
            ->assertSee('2026')
            ->assertSee('Θερμικός χάρτης');
    }
}
