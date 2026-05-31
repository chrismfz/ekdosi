<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reproduces "I navigate away and the fetched result is gone": a fresh mount of
 * the console must rehydrate the last fetch from the cache (RemembersLastFetch),
 * without hitting AADE.
 */
class MyDataConsoleCacheRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Company
    {
        $tenant = Company::create([
            'name' => 'md', 'slug' => 'md-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    private function fakeResult(): array
    {
        return [
            'from' => '01/04/2026', 'to' => '29/05/2026',
            'aadeTotal' => 1, 'localTotal' => 0, 'discrepancyCount' => 1,
            'matched' => [], 'stateMismatch' => [], 'missingAtAade' => [], 'duplicateLocal' => [],
            'missingLocally' => [[
                'mark' => '400099999999999', 'uid' => null, 'invoiceId' => null, 'invcode' => null,
                'issuedAt' => '02/04/2026', 'counterpartName' => 'x', 'gross' => 124.0,
                'localState' => null, 'localStatus' => null, 'aadeState' => 'VALID',
                'cancelledByMark' => null, 'problem' => 'orphan', 'url' => null,
                'invoiceType' => '1.1', 'invoiceTypeLabel' => 'Τιμολόγιο Πώλησης', 'bucket' => 'income',
            ]],
        ];
    }

    public function test_fresh_mount_restores_the_last_fetch_from_cache(): void
    {
        $tenant = $this->boot();

        // Mimic a successful fetch having been cached (what rememberFetch writes).
        Cache::put('mydata-fetch:MyDataConsole:'.$tenant->getKey(), [
            'state' => [
                'result' => $this->fakeResult(),
                'resultMode' => 'inbound',
                'fromLabel' => '01/04/2026', 'toLabel' => '29/05/2026',
                'windowFrom' => '2026-04-01', 'windowTo' => '2026-05-29',
                'ran' => true,
            ],
            'at' => now()->subMinutes(10)->toIso8601String(),
        ], now()->addHours(12));

        // A brand-new page mount (= navigate back) must show the data again,
        // WITHOUT any AADE call.
        Livewire::test(MyDataConsole::class)
            ->assertSet('ran', true)
            ->assertSet('resultMode', 'inbound')
            ->assertSee('400099999999999');
    }
}
