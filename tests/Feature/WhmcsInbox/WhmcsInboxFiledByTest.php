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
 * «Καταχωρημένα»: which rows whmcs:auto-issue filed vs an operator. The signal is
 * the AUTO_ISSUE_MARKER in the frozen notes — NOT filed_by_user_id IS NULL, which
 * a deleted operator (FK ON DELETE SET NULL) would fake.
 */
class WhmcsInboxFiledByTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'FiledBy', 'slug' => 'fb-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $this->operator = User::create(['name' => 'Χάρης', 'email' => 'h-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->operator->companies()->attach($this->company->id);
        $this->actingAs($this->operator);
        Filament::setTenant($this->company);
    }

    private function filedRow(int $whmcsId, ?int $userId, string $notes): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->company->id,
            'whmcs_invoice_id' => $whmcsId,
            'payload' => ['date' => '2026-09-01', 'total' => 10, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_by_user_id' => $userId,
            'notes' => $notes,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);
    }

    public function test_filed_rows_show_who_issued_them_and_filter_by_how(): void
    {
        $auto = $this->filedRow(1, null, 'Filed at AADE as invoice #ΤΠΥ1 (MARK 1). Αυτόματη έκδοση (άμεση τιμολόγηση) — '.PendingWhmcsInvoice::AUTO_ISSUE_MARKER.'.');
        $manual = $this->filedRow(2, $this->operator->id, 'Εκδόθηκε & υποβλήθηκε στο myDATA ως ΤΠΥ2 (MARK 2).');
        // A manual row whose operator was deleted: filed_by is NULL, but it was NOT automatic.
        $orphan = $this->filedRow(3, null, 'Εκδόθηκε & υποβλήθηκε στο myDATA ως ΤΠΥ3 (MARK 3).');

        $this->assertTrue($auto->wasAutoIssued());
        $this->assertFalse($manual->wasAutoIssued());
        $this->assertFalse($orphan->wasAutoIssued());

        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'filed')
            ->loadTable()
            ->assertTableColumnStateSet('filed_by', '🤖 Αυτόματα', $auto)
            ->assertTableColumnStateSet('filed_by', 'Χάρης', $manual)
            ->assertTableColumnStateSet('filed_by', 'Χειριστής', $orphan)
            ->filterTable('issued_how', 'auto')
            ->assertCanSeeTableRecords([$auto])
            ->assertCanNotSeeTableRecords([$manual, $orphan])
            ->filterTable('issued_how', 'manual')
            ->assertCanSeeTableRecords([$manual, $orphan])
            ->assertCanNotSeeTableRecords([$auto]);
    }

    public function test_column_and_filter_live_only_on_tabs_that_hold_filed_rows(): void
    {
        // The default «Ανοιχτά» tab never holds filed rows: no blank column, and a
        // hidden filter is not applied — a leftover selection can't empty the inbox.
        Livewire::test(ListWhmcsInbox::class)
            ->loadTable()
            ->assertTableColumnHidden('filed_by')
            ->assertTableFilterHidden('issued_how');

        foreach (['filed', 'all'] as $tab) {
            Livewire::test(ListWhmcsInbox::class)
                ->set('activeTab', $tab)
                ->loadTable()
                ->assertTableColumnVisible('filed_by')
                ->assertTableFilterVisible('issued_how');
        }
    }
}
