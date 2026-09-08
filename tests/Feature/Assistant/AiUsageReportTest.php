<?php

namespace Tests\Feature\Assistant;

use App\Filament\Pages\AiUsage;
use App\Models\AiUsageLog;
use App\Models\Company;
use App\Models\User;
use App\Services\Assistant\AiUsageReport;
use App\Services\Assistant\Tools\AiUsageTool;
use App\Services\TenantRoleProvisioner;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 2c (ε): the AI usage/cost report. The load-bearing property is that it is
 * CROSS-TENANT (drops CompanyScope) yet the page is super_admin-only.
 */
class AiUsageReportTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $slug, ?int $cap = null): Company
    {
        return Company::create([
            'name' => strtoupper($slug), 'slug' => $slug.'-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'ai_monthly_token_cap' => $cap,
        ]);
    }

    private function logRow(Company $c, ?User $u, int $in, int $out, float $cost, ?CarbonImmutable $when = null): void
    {
        $row = AiUsageLog::create([
            'company_id' => $c->id,
            'user_id' => $u?->id,
            'model' => 'claude-sonnet-4-6',
            'input_tokens' => $in,
            'output_tokens' => $out,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
            'cost_estimate' => $cost,
        ]);
        if ($when !== null) {
            $row->forceFill(['created_at' => $when])->save();
        }
    }

    public function test_report_aggregates_across_all_tenants_even_with_a_tenant_bound(): void
    {
        config(['ekdosi.ai.global_monthly_token_cap' => 0]); // isolate the per-company cap
        $a = $this->company('alpha', cap: 1000);
        $b = $this->company('beta');
        $ua = User::create(['name' => 'Alice', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $ub = User::create(['name' => 'Bob', 'email' => 'b-'.uniqid().'@t.local', 'password' => bcrypt('x')]);

        // Current month
        $this->logRow($a, $ua, 500, 300, 0.10);   // alpha billable 800 → 80% of cap 1000 → warn
        $this->logRow($b, $ub, 100, 50, 0.02);
        // Last month (must NOT count toward the current-month view)
        $this->logRow($a, $ua, 9_000, 9_000, 5.00, CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDays(2));

        // Bind the panel to ONE tenant — the report must still see the other.
        $ua->companies()->attach($a->id);
        $this->actingAs($ua);
        Filament::setTenant($a);

        $report = app(AiUsageReport::class)->forMonth(CarbonImmutable::now()->format('Y-m'));

        $this->assertCount(2, $report['companies'], 'cross-tenant: both companies present despite a bound tenant');
        $byName = collect($report['companies'])->keyBy('name');
        $this->assertSame(800, $byName['ALPHA']['billable']);
        $this->assertSame(150, $byName['BETA']['billable']);
        $this->assertSame(1000, $byName['ALPHA']['cap']);
        $this->assertEqualsWithDelta(0.80, $byName['ALPHA']['pct'], 0.0001);
        $this->assertSame('warn', $byName['ALPHA']['status']);
        $this->assertNull($byName['BETA']['cap'], 'no per-company cap and global cap disabled → uncapped');

        // Totals are current-month only (last-month 18k excluded).
        $this->assertSame(950, $report['totals']['billable']);
        $this->assertEqualsWithDelta(0.12, $report['totals']['cost'], 0.0001);
        $this->assertSame(2, $report['totals']['requests']);
    }

    public function test_month_filter_scopes_to_the_selected_month(): void
    {
        $a = $this->company('alpha');
        $last = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
        $this->logRow($a, null, 1_000, 1_000, 1.00, $last);

        $prev = app(AiUsageReport::class)->forMonth($last->format('Y-m'));
        $this->assertSame(2_000, $prev['totals']['billable']);

        $now = app(AiUsageReport::class)->forMonth(CarbonImmutable::now()->format('Y-m'));
        $this->assertSame(0, $now['totals']['billable']);
    }

    public function test_month_window_is_half_open_no_boundary_double_count(): void
    {
        $a = $this->company('alpha');
        $month = CarbonImmutable::now()->startOfMonth();
        // A row at 00:00:00 of the NEXT month must belong to next month only.
        $this->logRow($a, null, 10, 10, 0.01, $month->addMonth());
        // A row at 00:00:00 of THIS month belongs to this month.
        $this->logRow($a, null, 5, 5, 0.01, $month);

        $this->assertSame(10, app(AiUsageReport::class)->forMonth($month->format('Y-m'))['totals']['billable']);
        $this->assertSame(20, app(AiUsageReport::class)->forMonth($month->addMonth()->format('Y-m'))['totals']['billable']);
    }

    public function test_trend_returns_a_row_per_month_newest_last(): void
    {
        $a = $this->company('alpha');
        $this->logRow($a, null, 100, 100, 0.05);
        $report = app(AiUsageReport::class)->forMonth(CarbonImmutable::now()->format('Y-m'), trendMonths: 6);

        $this->assertCount(6, $report['trend']);
        $this->assertSame(CarbonImmutable::now()->format('Y-m'), end($report['trend'])['month']);
        $this->assertSame(200, end($report['trend'])['billable']);
    }

    public function test_page_renders_with_data_and_switches_month(): void
    {
        $a = $this->company('alpha');
        $admin = User::create(['name' => 'Root', 'email' => 'r-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $admin->companies()->attach($a->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($admin, $a);
        $this->actingAs($admin);
        Filament::setTenant($a);

        $this->logRow($a, $admin, 400, 100, 0.07);

        Livewire::test(AiUsage::class)
            ->assertSuccessful()
            ->assertSee('Χρήση & κόστος AI')
            ->assertSee('ALPHA')
            ->assertSee('Μηνιαία τάση')
            ->set('month', CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m'))
            ->assertSee('Καμία χρήση AI για τον μήνα.');
    }

    public function test_page_is_super_admin_only(): void
    {
        $c = $this->company('alpha');
        $plain = User::create(['name' => 'Plain', 'email' => 'p-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $plain->companies()->attach($c->id);
        $this->actingAs($plain);
        Filament::setTenant($c);
        $this->assertFalse(AiUsage::canAccess(), 'a non-super-admin must not reach the cross-tenant cost page');

        $admin = User::create(['name' => 'Root', 'email' => 'r-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $admin->companies()->attach($c->id);
        app(TenantRoleProvisioner::class)->assignSuperAdmin($admin, $c);
        $this->actingAs($admin);
        Filament::setTenant($c);
        $this->assertTrue(AiUsage::canAccess(), 'a system super_admin reaches it');
    }

    public function test_for_tenant_scopes_to_one_company_with_cap_and_per_user(): void
    {
        config(['ekdosi.ai.global_monthly_token_cap' => 0]);
        $a = $this->company('alpha', cap: 1000);
        $b = $this->company('beta');
        $ua = User::create(['name' => 'Alice', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);

        $this->logRow($a, $ua, 500, 300, 0.10);   // alpha billable 800 → 80% of 1000
        $this->logRow($b, null, 9_000, 9_000, 5.00); // beta — must NOT appear in alpha's report

        $res = app(AiUsageReport::class)->forTenant($a, CarbonImmutable::now()->format('Y-m'));

        $this->assertSame(800, $res['tokens']['billable']);
        $this->assertSame(1000, $res['cap']);
        $this->assertEqualsWithDelta(0.80, $res['pct_of_cap'], 0.0001);
        $this->assertSame('warn', $res['status']);
        $this->assertEqualsWithDelta(0.10, $res['cost_usd'], 0.0001);
        $this->assertSame('Alice', $res['by_user'][0]['name']);
        $this->assertSame(800, $res['by_user'][0]['billable']);
    }

    public function test_for_tenant_does_not_apply_the_cap_to_a_past_month(): void
    {
        config(['ekdosi.ai.global_monthly_token_cap' => 0]);
        $a = $this->company('alpha', cap: 1000);
        $last = CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth()->addDays(3);
        $this->logRow($a, null, 2_000, 1_000, 1.00, $last); // 3000 > cap 1000, but a CLOSED month

        $res = app(AiUsageReport::class)->forTenant($a, $last->format('Y-m'));

        $this->assertSame(3_000, $res['tokens']['billable']);
        $this->assertNull($res['cap'], 'a closed month must not carry the current cap');
        $this->assertNull($res['pct_of_cap']);
        $this->assertSame('ok', $res['status']); // never a false «blocked» for an old month
    }

    public function test_ai_usage_tool_defaults_to_current_month_for_the_ambient_tenant(): void
    {
        $a = $this->company('alpha');
        $this->logRow($a, null, 100, 50, 0.02);

        $res = (new AiUsageTool)->run($a, []);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $res['month']);
        $this->assertSame(150, $res['tokens']['billable']);
        $this->assertSame(1, $res['requests']);
    }
}
