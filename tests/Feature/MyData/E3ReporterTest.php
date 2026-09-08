<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataE3Overview;
use App\Models\Company;
use App\Services\MyData\E3Reporter;
use Carbon\Carbon;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * E7 — Ε3 overview. A Guzzle MockHandler feeds canned RequestE3Info XML through
 * firebed; verifies per-(type,category) aggregation, pagination, empty-window
 * safety, and the page wiring/gating.
 */
class E3ReporterTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'E3 test',
            'slug' => 'e3-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '801280908',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);
    }

    public function test_aggregates_per_type_and_category_across_pages(): void
    {
        $report = (new E3Reporter($this->tenant, new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ])))->report(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));

        // 4 entries scanned across both pages; 2 distinct (type,category) rows.
        $this->assertSame(4, $report->docCount);
        $this->assertCount(2, $report->rows);

        $byKey = collect($report->rows)->keyBy(fn ($r) => $r->classType.'|'.$r->classCategory);

        // E3_585_001 / category2_3 appears 3× → 10 + 10 + 5 = 25.
        $this->assertSame(25.00, $byKey['E3_585_001|category2_3']->value);
        $this->assertSame(3, $byKey['E3_585_001|category2_3']->count);

        // E3_585_002 / category2_4 once → 7.50.
        $this->assertSame(7.50, $byKey['E3_585_002|category2_4']->value);

        $this->assertSame(32.50, $report->total);
    }

    public function test_empty_window_is_safe(): void
    {
        $report = (new E3Reporter($this->tenant, new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0"/>
XML),
        ])))->report(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->docCount);
        $this->assertSame(0.0, $report->total);
    }

    public function test_page_gates_and_renders_result(): void
    {
        $user = \App\Models\User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        $this->assertTrue(MyDataE3Overview::canAccess());

        MyDataE3Overview::$testHandler = new MockHandler([
            new Response(200, [], $this->pageOne()),
            new Response(200, [], $this->pageTwo()),
        ]);

        try {
            Livewire::test(MyDataE3Overview::class)
                ->assertActionExists('fetch')
                ->callAction('fetch', data: ['from' => '2026-01-01', 'to' => '2026-03-31'])
                ->assertHasNoErrors()
                ->assertSee('E3_585_001');
        } finally {
            MyDataE3Overview::$testHandler = null;
        }
    }

    private function pageOne(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <continuationToken><nextPartitionKey>PK1</nextPartitionKey><nextRowKey>RK1</nextRowKey></continuationToken>
    <E3Info>
        <V_Afm>801280908</V_Afm><V_Mark>400000000000001</V_Mark><IssueDate>2026-01-18T00:00:00</IssueDate>
        <V_Class_Category>category2_3</V_Class_Category><V_Class_Type>E3_585_001</V_Class_Type><V_Class_Value>10.00</V_Class_Value>
    </E3Info>
    <E3Info>
        <V_Afm>801280908</V_Afm><V_Mark>400000000000002</V_Mark><IssueDate>2026-02-11T00:00:00</IssueDate>
        <V_Class_Category>category2_3</V_Class_Category><V_Class_Type>E3_585_001</V_Class_Type><V_Class_Value>10.00</V_Class_Value>
    </E3Info>
</RequestedE3Info>
XML;
    }

    private function pageTwo(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedE3Info xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <E3Info>
        <V_Afm>801280908</V_Afm><V_Mark>400000000000003</V_Mark><IssueDate>2026-02-13T00:00:00</IssueDate>
        <V_Class_Category>category2_3</V_Class_Category><V_Class_Type>E3_585_001</V_Class_Type><V_Class_Value>5.00</V_Class_Value>
    </E3Info>
    <E3Info>
        <V_Afm>801280908</V_Afm><V_Mark>400000000000004</V_Mark><IssueDate>2026-02-16T00:00:00</IssueDate>
        <V_Class_Category>category2_4</V_Class_Category><V_Class_Type>E3_585_002</V_Class_Type><V_Class_Value>7.50</V_Class_Value>
    </E3Info>
</RequestedE3Info>
XML;
    }
}
