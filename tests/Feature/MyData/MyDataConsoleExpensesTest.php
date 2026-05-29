<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsoleExpenses;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Page wiring for the expenses console (E4): both directions, the inbound
 * orphan rendering, and the import action gating. The live fetch hits AADE,
 * so we inject a serialized result (the shape serialize() produces) rather
 * than calling AADE.
 */
class MyDataConsoleExpensesTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenantUser(string $provider = 'gr-mydata', string $mode = 'sandbox'): Company
    {
        $tenant = Company::create([
            'name' => 'Exp console',
            'slug' => 'expcon-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => $provider,
            'mydata_mode' => $mode,
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function fakeResult(): array
    {
        $orphan = [
            'mark' => '400012434052701', 'uid' => null, 'expenseId' => null, 'invcode' => null,
            'issuedAt' => '15/01/2026', 'counterpartName' => 'ΑΛΦΑΝΕΤ ΑΕ', 'gross' => 174.0,
            'localState' => null, 'localStatus' => null, 'aadeState' => 'VALID',
            'cancelledByMark' => null, 'problem' => 'Στο myDATA, χωρίς τοπική εγγραφή.', 'url' => null,
        ];

        return [
            'from' => '01/01/2026', 'to' => '31/01/2026',
            'aadeTotal' => 1, 'localTotal' => 0, 'discrepancyCount' => 1,
            'matched' => [], 'stateMismatch' => [],
            'missingAtAade' => [], 'missingLocally' => [$orphan], 'duplicateLocal' => [],
        ];
    }

    public function test_console_exposes_both_directions(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsoleExpenses::class)
            ->assertOk()
            ->assertActionExists('reconcile')
            ->assertActionExists('find_orphans');
    }

    public function test_inbound_view_highlights_orphan_expenses_and_offers_import(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsoleExpenses::class)
            ->set('ran', true)
            ->set('resultMode', 'inbound')
            ->set('fromLabel', '01/01/2026')
            ->set('toLabel', '31/01/2026')
            ->set('result', $this->fakeResult())
            ->assertSee('Αδέσποτα έξοδα')
            ->assertSee('400012434052701')      // the orphan MARK
            ->assertSee('ΑΛΦΑΝΕΤ ΑΕ')           // supplier name column
            ->assertActionVisible('import_orphans');
    }

    public function test_import_action_hidden_without_orphans(): void
    {
        $this->bootTenantUser();

        $empty = $this->fakeResult();
        $empty['missingLocally'] = [];

        Livewire::test(MyDataConsoleExpenses::class)
            ->set('ran', true)
            ->set('resultMode', 'inbound')
            ->set('result', $empty)
            ->assertActionHidden('import_orphans');
    }

    public function test_non_mydata_tenant_cannot_access(): void
    {
        $this->bootTenantUser(provider: 'none', mode: 'off');

        $this->assertFalse(MyDataConsoleExpenses::canAccess());
    }
}
