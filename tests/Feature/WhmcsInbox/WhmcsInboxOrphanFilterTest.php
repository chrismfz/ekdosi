<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: removing a table filter that no longer exists must not 500.
 *
 * The PR that moved the inbox status filter into CFM-style tabs deleted the
 * `status` SelectFilter. A browser tab left open across that deploy (or a
 * bookmarked `?filters[status]=…` URL) still carried the `status` key in the
 * URL-bound `tableFilters` state; when Livewire then asked to clear it, Filament
 * called `->getResetState()` on the (now null) filter and threw
 * "Call to a member function getResetState() on null"
 * (vendor/filament/tables/src/Concerns/HasFilters.php:81), spamming error mail.
 *
 * BaseListRecords::removeTableFilter() guards this for every list page.
 */
class WhmcsInboxOrphanFilterTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function boot(): void
    {
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'Orphan', 'slug' => 'orph-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);
        Filament::setTenant($this->company);
    }

    public function test_removing_an_orphaned_status_filter_does_not_crash(): void
    {
        $this->boot();

        // Stale state a pre-deploy tab / bookmarked URL would carry: a `status`
        // filter that the table no longer defines (status is tabs now).
        $component = Livewire::test(ListWhmcsInbox::class)
            ->set('tableFilters', ['status' => ['value' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]])
            // Exactly the call the frontend made in the reported 500: ('status', null, false).
            ->call('removeTableFilter', 'status')
            ->assertOk();

        // The orphaned key is dropped, so it can't keep re-triggering.
        $this->assertArrayNotHasKey('status', $component->get('tableFilters') ?? []);
    }

    public function test_removing_a_real_existing_filter_still_works(): void
    {
        $this->boot();

        // The guard must only short-circuit unknown filters — a filter the table
        // still defines (`immediate` is a real SelectFilter on the inbox) has to go
        // through the normal parent removal path unchanged.
        $component = Livewire::test(ListWhmcsInbox::class)
            ->set('tableFilters', ['immediate' => ['value' => 'yes']])
            ->call('removeTableFilter', 'immediate')
            ->assertOk();

        // Prove the parent path actually ran (reset the value) — not that the
        // override silently swallowed a real filter and left 'yes' in place.
        $this->assertNotSame('yes', $component->get('tableFilters')['immediate']['value'] ?? null);
    }
}
