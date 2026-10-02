<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MYD-025: a tenant is deleted only through EditCompany's «Οριστική διαγραφή»
 * (evidence warning + two acknowledgements + log line). The Companies list must
 * not offer a bulk delete that skips all of that — even to a super_admin.
 */
class CompaniesNoBulkDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_companies_list_offers_no_bulk_delete(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Admin', 'email' => 'a-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant(Company::create([
            'name' => 'Host', 'slug' => 'host-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none',
        ]));
        $other = Company::create(['name' => 'Other OE', 'slug' => 'other-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);

        $page = Livewire::test(ListCompanies::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$other])
            ->assertActionDoesNotExist(TestAction::make('delete')->table()->bulk());

        // Not just «no action named delete»: no bulk action at all, so a renamed
        // DeleteBulkAction can't sneak the bypass back in.
        $this->assertSame([], $page->instance()->getTable()->getFlatBulkActions());
    }
}
