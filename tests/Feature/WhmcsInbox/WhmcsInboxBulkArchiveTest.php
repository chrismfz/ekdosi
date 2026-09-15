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
 * Bulk «Αρχειοθέτηση επιλεγμένων»: archive many inbox rows at once instead of
 * one-by-one. Archives only still-actionable rows (προς έλεγχο / σε αναμονή) to
 * the internal `rejected` state with one optional shared note; filed / drafted /
 * split / resolved / already-archived rows are skipped and counted. Unlike bulk
 * delete, archive is audit-frozen — the rows don't return on the next WHMCS sync.
 */
class WhmcsInboxBulkArchiveTest extends TestCase
{
    use RefreshDatabase;

    private static int $nextWhmcsId = 820000;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Arch Bulk', 'slug' => 'wab-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function row(Company $t, string $status, ?string $mark = null): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $t->id,
            'whmcs_invoice_id' => self::$nextWhmcsId++,
            'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => $status,
            'mydata_mark' => $mark,
        ]);
    }

    private function actingOnInbox(Company $t): void
    {
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($t);
    }

    public function test_bulk_archive_sets_rejected_on_actionable_rows_and_skips_the_rest(): void
    {
        $t = $this->tenant();
        $pending = $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $held = $this->row($t, PendingWhmcsInvoice::STATUS_HELD);
        // Skipped: a filed row (legal WHMCS↔MARK link) and one already archived
        // that carries an existing note the shared bulk note must NOT clobber.
        $filed = $this->row($t, PendingWhmcsInvoice::STATUS_FILED, mark: '400001234567890');
        $already = $this->row($t, PendingWhmcsInvoice::STATUS_REJECTED);
        $already->update(['rejected_reason' => 'παλιά σημείωση']);

        $this->actingOnInbox($t);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'all')
            ->callTableBulkAction('archive_selected', [
                $pending->getKey(), $held->getKey(), $filed->getKey(), $already->getKey(),
            ], data: ['rejected_reason' => 'δικά μας']);

        // Archived with the shared note.
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $pending->refresh()->status);
        $this->assertSame('δικά μας', $pending->rejected_reason);
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $held->refresh()->status);
        $this->assertSame('δικά μας', $held->rejected_reason);

        // Untouched: filed stays filed; the already-archived row keeps its own note.
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $filed->refresh()->status);
        $already->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $already->status);
        $this->assertSame('παλιά σημείωση', $already->rejected_reason);
    }

    public function test_archive_is_operator_level_while_bulk_delete_stays_admin_only(): void
    {
        $t = $this->tenant();
        $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        // An operator: may archive (Update:PendingWhmcsInvoice) but is NOT admin
        // (no DeleteAny) — the whole reason archive sits next to delete. Deny only
        // the admin delete ability, allow the rest so the list still renders.
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        Gate::before(fn ($u, string $ability): ?bool => in_array($ability, ['deleteAny', 'DeleteAny:PendingWhmcsInvoice'], true) ? false : true);
        $this->actingAs($user);
        Filament::setTenant($t);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'all')
            ->assertTableBulkActionVisible('archive_selected')
            ->assertTableBulkActionHidden('delete_selected');
    }

    public function test_bulk_archive_note_is_optional(): void
    {
        $t = $this->tenant();
        $pending = $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        $this->actingOnInbox($t);

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'all')
            ->callTableBulkAction('archive_selected', [$pending->getKey()]);

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $pending->status);
        $this->assertNull($pending->rejected_reason);
    }
}
