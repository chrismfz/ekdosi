<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * CFM-style status tabs on the WHMCS inbox. The default «Ανοιχτά» tab shows
 * pending_review + held TOGETHER — the fix for a held row hiding by default
 * (the old status filter defaulted to pending_review only, so a held invoice
 * got lost). Each tab carries a live count.
 */
class WhmcsInboxTabsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function boot(): void
    {
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'Tabs', 'slug' => 'tabs-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);
        Filament::setTenant($this->company);
    }

    private function row(string $status, int $whmcsId): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->company->id,
            'whmcs_invoice_id' => $whmcsId,
            'payload' => ['date' => '2026-09-01', 'total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => $status,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);
    }

    public function test_default_open_tab_shows_pending_and_held_but_not_filed(): void
    {
        $this->boot();
        $pending = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $held = $this->row(PendingWhmcsInvoice::STATUS_HELD, 2);
        $filed = $this->row(PendingWhmcsInvoice::STATUS_FILED, 3);

        // No activeTab set → the default (first) tab «Ανοιχτά» applies.
        Livewire::test(ListWhmcsInbox::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$pending, $held])
            ->assertCanNotSeeTableRecords([$filed]);
    }

    public function test_held_tab_shows_only_held(): void
    {
        $this->boot();
        $pending = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $held = $this->row(PendingWhmcsInvoice::STATUS_HELD, 2);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'held')
            ->loadTable()
            ->assertCanSeeTableRecords([$held])
            ->assertCanNotSeeTableRecords([$pending]);
    }

    public function test_all_tab_shows_every_status(): void
    {
        $this->boot();
        $pending = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $filed = $this->row(PendingWhmcsInvoice::STATUS_FILED, 2);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'all')
            ->loadTable()
            ->assertCanSeeTableRecords([$pending, $filed]);
    }

    public function test_open_tab_badge_counts_pending_plus_held(): void
    {
        $this->boot();
        $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $this->row(PendingWhmcsInvoice::STATUS_HELD, 2);
        $this->row(PendingWhmcsInvoice::STATUS_HELD, 3);
        $this->row(PendingWhmcsInvoice::STATUS_FILED, 4); // not counted in «Ανοιχτά»

        $tabs = Livewire::test(ListWhmcsInbox::class)->instance()->getTabs();

        $this->assertEquals(3, $tabs['open']->getBadge());  // 1 pending + 2 held
        $this->assertEquals(1, $tabs['filed']->getBadge());
        $this->assertEquals(4, $tabs['all']->getBadge());   // total (CFM-style)
    }

    public function test_nav_badge_counts_pending_plus_held(): void
    {
        $this->boot();
        $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $this->row(PendingWhmcsInvoice::STATUS_HELD, 2);
        $this->row(PendingWhmcsInvoice::STATUS_FILED, 3); // not a «waiting» row

        // The nav badge «Εισερχόμενα N» must include held — the other half of why a
        // held row got lost (it was invisible in the count as well as the list).
        $this->assertSame('2', WhmcsInboxResource::getNavigationBadge());
    }

    public function test_tab_and_nav_badges_are_scoped_to_the_current_tenant(): void
    {
        $this->boot(); // tenant A
        $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 1);
        $this->row(PendingWhmcsInvoice::STATUS_HELD, 2);

        // A DIFFERENT tenant with 5 pending rows must NOT inflate tenant A's badges.
        $other = Company::create([
            'name' => 'Other', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        foreach (range(1, 5) as $i) {
            PendingWhmcsInvoice::create([
                'company_id' => $other->id,
                'whmcs_invoice_id' => 100 + $i,
                'payload' => ['total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
                'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
                'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            ]);
        }

        // Still acting as tenant A.
        $tabs = Livewire::test(ListWhmcsInbox::class)->instance()->getTabs();
        $this->assertEquals(2, $tabs['open']->getBadge(), 'only A: 1 pending + 1 held');
        $this->assertEquals(2, $tabs['all']->getBadge(), 'total is A-only, not A+B');
        $this->assertSame('2', WhmcsInboxResource::getNavigationBadge());
    }
}
