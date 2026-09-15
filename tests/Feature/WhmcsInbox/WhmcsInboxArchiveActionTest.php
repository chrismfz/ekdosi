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
 * «Αρχειοθέτηση» is a labels-only rename of the former «Απόρριψη» action: the UI
 * strings changed but the internal contract must NOT. The action name stays
 * `reject`, the note column stays `rejected_reason`, and the persisted status
 * stays STATUS_REJECTED ('rejected') — so the ingestor's audit-freeze / re-stage
 * logic (which keys off the constant, not the label) keeps working unchanged.
 */
class WhmcsInboxArchiveActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private function boot(): void
    {
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'Arch', 'slug' => 'arch-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);
        Filament::setTenant($this->company);
    }

    private function row(string $status): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->company->id,
            'whmcs_invoice_id' => random_int(700000, 799999),
            'payload' => ['total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => $status,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);
    }

    public function test_archiving_a_pending_row_sets_rejected_status_and_stores_the_note(): void
    {
        $this->boot();
        $row = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('reject', $row, data: ['rejected_reason' => 'δική μας υπηρεσία'])
            ->assertHasNoTableActionErrors();

        $row->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $row->status);
        $this->assertSame('δική μας υπηρεσία', $row->rejected_reason);
    }

    public function test_archiving_a_held_row_is_available_and_sets_rejected_status(): void
    {
        $this->boot();
        $held = $this->row(PendingWhmcsInvoice::STATUS_HELD);

        // Visible AND the write path actually runs for a HELD row (the action is
        // enabled for both pending_review and held) — not just pending_review.
        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'held')
            ->assertTableActionVisible('reject', $held)
            ->callTableAction('reject', $held, data: ['rejected_reason' => 'φίλου'])
            ->assertHasNoTableActionErrors();

        $held->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $held->status);
        $this->assertSame('φίλου', $held->rejected_reason);
    }
}
