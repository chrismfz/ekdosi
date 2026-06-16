<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsole;
use App\Models\Company;
use App\Services\MyData\MyDataConsoleRefresh;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The «Ανανέωση όλων» orchestrator (MyDataConsoleRefresh): four sequential AADE
 * pulls that each seed their tab's cache, with per-step isolation so one failure
 * doesn't abort the rest.
 */
class MyDataConsoleRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Refresh OE', 'slug' => 'ref-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'afm' => '801280908', 'mydata_aade_id_sandbox' => 'U', 'mydata_subscription_key_sandbox' => 'K',
        ]);
    }

    public function test_every_step_is_isolated_and_all_four_are_reported(): void
    {
        $tenant = $this->tenant();

        // Empty mock → every AADE call throws → every step fails, but the loop
        // completes and still reports all four (no step aborts the rest).
        $steps = (new MyDataConsoleRefresh(new MockHandler))
            ->refreshAll($tenant, now()->startOfQuarter(), now());

        $this->assertCount(4, $steps);
        $this->assertSame(
            ['Πωλήσεις', 'Έξοδα', 'Επισκόπηση Ε3', 'Εικόνα ΦΠΑ'],
            array_map(fn ($s) => $s->label, $steps),
        );
        foreach ($steps as $step) {
            $this->assertFalse($step->ok(), "{$step->label} should have failed on the empty mock");
        }
    }

    public function test_a_successful_step_seeds_that_tabs_cache(): void
    {
        $tenant = $this->tenant();

        // One empty-but-valid TransmittedDocs page → the Πωλήσεις step succeeds and
        // writes the MyDataConsole snapshot cache; later steps get the empty queue.
        $steps = (new MyDataConsoleRefresh(new MockHandler([
            new Response(200, [], <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<RequestedDoc xmlns="http://www.aade.gr/myDATA/invoice/v1.0">
    <invoicesDoc/>
</RequestedDoc>
XML),
        ])))->refreshAll($tenant, now()->startOfQuarter(), now());

        $this->assertSame('Πωλήσεις', $steps[0]->label);
        $this->assertTrue($steps[0]->ok(), 'the sales step should succeed');
        $this->assertNotNull(MyDataConsole::lastFetchAt($tenant->id), 'the sales tab cache was seeded');
    }
}
