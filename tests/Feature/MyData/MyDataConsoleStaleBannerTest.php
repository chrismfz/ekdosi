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
 * The «παλιά δεδομένα — ανανέωση» banner: when the cached console fetch is older
 * than the staleness threshold, the page flags it so the operator knows the
 * snapshot may be out of date (whether it was last refreshed manually or by the
 * mydata:refresh-console scheduled task).
 */
class MyDataConsoleStaleBannerTest extends TestCase
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

    private function seedFetch(Company $tenant, string $at): void
    {
        Cache::put('mydata-fetch:MyDataConsole:'.$tenant->getKey(), [
            'state' => [
                'result' => [
                    'from' => '01/04/2026', 'to' => '29/05/2026',
                    'aadeTotal' => 0, 'localTotal' => 0, 'discrepancyCount' => 0,
                    'matched' => [], 'stateMismatch' => [], 'missingAtAade' => [],
                    'duplicateLocal' => [], 'missingLocally' => [],
                ],
                'resultMode' => 'both',
                'fromLabel' => '01/04/2026', 'toLabel' => '29/05/2026',
                'windowFrom' => '2026-04-01', 'windowTo' => '2026-05-29',
                'ran' => true,
            ],
            'at' => $at,
        ], now()->addHours(12));
    }

    public function test_recent_fetch_is_not_stale(): void
    {
        $tenant = $this->boot();
        $this->seedFetch($tenant, now()->subMinutes(20)->toIso8601String());

        $this->assertFalse((new MyDataConsole)->fetchIsStale());

        Livewire::test(MyDataConsole::class)
            ->assertSee('Αποθηκευμένο αποτέλεσμα')
            ->assertDontSee('πιθανώς παλιά');
    }

    public function test_old_fetch_is_stale_and_shows_the_banner(): void
    {
        $tenant = $this->boot();
        $this->seedFetch($tenant, now()->subHours(8)->toIso8601String());

        $this->assertTrue((new MyDataConsole)->fetchIsStale());

        Livewire::test(MyDataConsole::class)
            ->assertSee('πιθανώς παλιά');
    }
}
