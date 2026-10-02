<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Filament\Resources\WhmcsInbox\Tables\WhmcsInboxTable;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use App\Support\Settings\SystemSettings;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The inbox header reminder for άμεση τιμολόγηση auto-issue: hidden while the
 * tenant toggle is off; green only when whmcs:auto-issue would ACTUALLY run
 * (scheduler flag + default invoice type + WHMCS creds); amber with the reason
 * when armed but not running — the «I think it issues, it doesn't» trap.
 */
class WhmcsInboxAutoIssueIndicatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'Auto', 'slug' => 'auto-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
            'whmcs_api_url' => 'https://whmcs.example', 'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function type(string $code): InvoiceType
    {
        return InvoiceType::create(['company_id' => $this->tenant->id, 'code' => $code, 'name' => $code, 'invcount' => 1, 'mydata_type' => '2.1']);
    }

    private function scheduler(bool $on): void
    {
        app(SystemSettings::class)->set('schedule.whmcs_auto_issue_enabled', $on, 'bool');
    }

    public function test_hidden_while_the_tenant_toggle_is_off(): void
    {
        $this->assertNull(WhmcsInboxTable::autoIssueState($this->tenant));

        Livewire::test(ListWhmcsInbox::class)->loadTable()
            ->assertTableActionHidden('auto_issue_status');
    }

    public function test_armed_but_not_running_says_why(): void
    {
        $this->scheduler(false);
        $this->tenant->update(['whmcs_auto_issue_immediate' => true]);

        $state = WhmcsInboxTable::autoIssueState($this->tenant->fresh());
        $this->assertFalse($state['running']);
        $this->assertStringContainsString('Χρονοπρογραμματιστή', $state['summary']);
        $this->assertStringContainsString('τύπος τιμολογίου', $state['summary']);

        Livewire::test(ListWhmcsInbox::class)->loadTable()
            ->assertTableActionVisible('auto_issue_status')
            ->assertTableActionHasLabel('auto_issue_status', 'Άμεση τιμολόγηση: ON — δεν τρέχει');
    }

    public function test_green_when_it_actually_runs_and_says_invoices_only_without_a_receipt_type(): void
    {
        $this->scheduler(true);
        $this->tenant->update(['whmcs_auto_issue_immediate' => true, 'whmcs_default_invoice_type_id' => $this->type('TPY')->id]);

        $state = WhmcsInboxTable::autoIssueState($this->tenant->fresh());
        $this->assertTrue($state['running']);
        $this->assertStringContainsString('μόνο τιμολόγια', $state['summary']);

        Livewire::test(ListWhmcsInbox::class)->loadTable()
            ->assertTableActionHasLabel('auto_issue_status', 'Άμεση τιμολόγηση: ενεργή');

        $this->tenant->update(['whmcs_default_receipt_type_id' => $this->type('ALP')->id]);
        $this->assertStringContainsString('τιμολόγια και αποδείξεις', WhmcsInboxTable::autoIssueState($this->tenant->fresh())['summary']);
    }

    public function test_without_whmcs_credentials_it_is_not_running_like_the_command(): void
    {
        // whmcs:auto-issue skips a tenant without full WHMCS API creds — the badge
        // must not turn green for it.
        $this->scheduler(true);
        $this->tenant->update([
            'whmcs_auto_issue_immediate' => true,
            'whmcs_default_invoice_type_id' => $this->type('TPY')->id,
            'whmcs_api_secret' => null,
        ]);

        $state = WhmcsInboxTable::autoIssueState($this->tenant->fresh());
        $this->assertFalse($state['running']);
        $this->assertStringContainsString('σύνδεση WHMCS', $state['summary']);
    }
}
