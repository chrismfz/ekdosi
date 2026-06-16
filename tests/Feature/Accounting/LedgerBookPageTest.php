<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\LedgerBook;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Βιβλίο Εσόδων-Εξόδων page renders (self-styled blade, no build dependency)
 * and surfaces the myDATA ΜΑΡΚ + κατάσταση columns added in #6.
 */
class LedgerBookPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_mark_and_status_columns(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $tenant = Company::create([
            'name' => 'Ledger OE', 'slug' => 'ledger-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        Filament::setTenant($tenant);

        Livewire::test(LedgerBook::class)
            ->assertSuccessful()
            ->assertSee('Ημερολόγιο')
            ->assertSee('ΜΑΡΚ')
            ->assertSee('Σύνολα περιόδου');
    }
}
