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
 * Inbox cleanup: hard-delete disposable rows (test / internal / accidental
 * pushes) via bulk select, with a guard that NEVER removes a filed/drafted row
 * or one carrying a MARK (legal WHMCS↔MARK link / would orphan a draft).
 */
class WhmcsInboxDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'Del Test', 'slug' => 'wid-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function row(Company $t, string $status, ?string $mark = null): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $t->id,
            'whmcs_invoice_id' => random_int(1, 999999),
            'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => $status,
            'mydata_mark' => $mark,
        ]);
    }

    private function actingOnInbox(Company $t): void
    {
        $user = User::create(['name' => 'Admin', 'email' => 'a-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($t);
    }

    public function test_bulk_delete_removes_disposable_but_skips_protected(): void
    {
        $t = $this->tenant();
        $pending = $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);
        $held = $this->row($t, PendingWhmcsInvoice::STATUS_HELD);
        $rejected = $this->row($t, PendingWhmcsInvoice::STATUS_REJECTED);
        // Protected: a filed row carrying a MARK must never be deleted.
        $filed = $this->row($t, PendingWhmcsInvoice::STATUS_FILED, mark: '400001234567890');

        $this->actingOnInbox($t);

        // Clear the default «Προς έλεγχο» filter so all statuses are selectable.
        Livewire::test(ListWhmcsInbox::class)
            ->filterTable('status', null)
            ->callTableBulkAction('delete_selected', [
                $pending->getKey(), $held->getKey(), $rejected->getKey(), $filed->getKey(),
            ]);

        $this->assertModelMissing($pending);
        $this->assertModelMissing($held);
        $this->assertModelMissing($rejected);
        $this->assertModelExists($filed);   // legal record preserved
    }

    public function test_isDeletable_excludes_rows_with_a_linked_invoice_or_mark(): void
    {
        $t = $this->tenant();

        // A pending row with a MARK (defensive) is NOT deletable.
        $withMark = $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW, mark: '400000000000001');
        // drafted/split rows are excluded by the status allowlist (they carry a
        // draft invoice via whmcs_pending_id that a delete would orphan).
        $drafted = $this->row($t, PendingWhmcsInvoice::STATUS_DRAFTED);
        $split = $this->row($t, PendingWhmcsInvoice::STATUS_SPLIT);

        $this->actingOnInbox($t);

        Livewire::test(ListWhmcsInbox::class)
            ->filterTable('status', null)
            ->callTableBulkAction('delete_selected', [
                $withMark->getKey(), $drafted->getKey(), $split->getKey(),
            ]);

        $this->assertModelExists($withMark);
        $this->assertModelExists($drafted);
        $this->assertModelExists($split);
    }

    public function test_delete_is_denied_without_the_delete_permission(): void
    {
        $t = $this->tenant();
        $row = $this->row($t, PendingWhmcsInvoice::STATUS_PENDING_REVIEW);

        // A plain user WITHOUT Delete:PendingWhmcsInvoice — the policy (which the
        // action's ->authorize('delete') consults) must deny. Operators get only
        // View/Update on this resource (CLAUDE.md role map), so this mirrors
        // "an operator can't delete"; only company_admin/super_admin can.
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]);

        $this->assertFalse(Gate::forUser($user)->allows('delete', $row));
        $this->assertFalse(Gate::forUser($user)->allows('deleteAny', PendingWhmcsInvoice::class));
    }
}
