<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Inbox polish: «Συγχρονισμός τώρα» and «Αρχειοθέτηση» are one click (no confirm
 * modal); the bulk actions sit directly in the toolbar (no dropdown); the bulk
 * archive has a bulk undo; the optional archive note is added afterwards.
 */
class WhmcsInboxOneClickTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'One', 'slug' => 'one-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'O', 'email' => 'o-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);
        Filament::setTenant($this->company);
    }

    private function row(string $status, ?string $reason = null): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->company->id, 'whmcs_invoice_id' => random_int(700000, 799999),
            'payload' => ['total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => $status, 'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'rejected_reason' => $reason,
        ]);
    }

    public function test_sync_and_archive_need_no_confirmation_and_bulk_actions_are_ungrouped(): void
    {
        $row = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $lw = Livewire::test(ListWhmcsInbox::class);
        $table = $lw->instance()->getTable();

        $this->assertFalse($table->getAction('sync_now')->isConfirmationRequired());
        $this->assertFalse($table->getAction('reject')->record($row)->isConfirmationRequired());
        $this->assertFalse($table->getBulkAction('archive_selected')->isConfirmationRequired());
        foreach ($table->getToolbarActions() as $action) {
            $this->assertNotInstanceOf(BulkActionGroup::class, $action);
        }
    }

    public function test_archive_keeps_an_existing_note_and_the_note_can_be_added_after(): void
    {
        $row = $this->row(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, 'παλιά');

        Livewire::test(ListWhmcsInbox::class)->callTableAction('reject', $row);
        $this->assertSame('παλιά', $row->fresh()->rejected_reason);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'rejected')
            ->callTableAction('archive_note', $row, data: ['rejected_reason' => 'δικά μας'])
            ->assertHasNoTableActionErrors();
        $this->assertSame('δικά μας', $row->fresh()->rejected_reason);
    }

    public function test_bulk_restage_undoes_a_bulk_archive(): void
    {
        $a = $this->row(PendingWhmcsInvoice::STATUS_REJECTED);
        $b = $this->row(PendingWhmcsInvoice::STATUS_REJECTED);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'rejected')
            ->assertTableBulkActionVisible('re_stage_selected')
            ->callTableBulkAction('re_stage_selected', [$a->getKey(), $b->getKey()]);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $a->fresh()->status);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $b->fresh()->status);
    }
}
