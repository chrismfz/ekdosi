<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Models\VatCategory;
use App\Services\WhmcsInbox\WhmcsInvoiceSplitter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Διαχωρισμός σε προσχέδια» from the inbox list (live test of 2026-10-27): it failed with «Customer belongs to a
 * different tenant» for every row with lines of the account's own, the list having loaded the row's customer without
 * its company; a third party known by its ΑΦΜ only showed as «—»; no document type was pre-selected.
 */
class WhmcsInboxSplitFromListTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private Customer $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create(['name' => 'T', 'slug' => 'splist-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Bank', 'due_days' => 0]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο', 'invcount' => 0,
            'payment_method_id' => $pm->id]);
        $this->tenant->update(['whmcs_default_invoice_type_id' => $this->type->id]);
        $this->account = Customer::create(['company_id' => $this->tenant->id, 'name' => 'NEXON', 'afm' => '801280908']);
    }

    private function row(): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 8, 'whmcs_userid' => 3, 'customer_id' => $this->account->id,
            'payload' => ['invoiceid' => 8, 'userid' => 3, 'status' => 'Paid', 'total' => '16.20', 'subtotal' => '15.00', 'tax' => '1.20',
                'taxrate' => '24.00', 'currencycode' => 'EUR', 'date' => '2026-10-27', 'items' => ['item' => [
                    ['id' => 1, 'description' => 'agent', 'amount' => '10.00', 'taxed' => '0'],
                    ['id' => 2, 'description' => 'Δοκιμή split', 'amount' => '5.00', 'taxed' => '1'],
                ]]],
            'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI, 'status' => PendingWhmcsInvoice::STATUS_HELD,
            'third_party_resolution' => ['multi_party' => true, 'lines' => [
                // A third party known by its ΑΦΜ only: MyCS sends no name, Ekdosi fills it in from the registry.
                ['item_id' => 1, 'routed' => true, 'is_receipt' => false, 'contact' => ['id' => 4, 'company_name' => '', 'gr_vatno' => '099206170']],
                ['item_id' => 2, 'routed' => false, 'is_receipt' => false, 'contact' => null],
            ]],
        ]);
    }

    private function operator(): void
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    public function test_a_row_with_the_accounts_own_lines_splits_from_the_list(): void
    {
        $this->operator();
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '0%', 'rate' => 0.00, 'is_default' => false]);
        $row = $this->row();

        Livewire::test(ListWhmcsInbox::class)
            ->callTableAction('split_third_party', $row, data: ['invoice_type_id' => $this->type->id])
            ->assertNotNotified('Ο διαχωρισμός απέτυχε');

        $this->assertSame(2, Invoice::query()->where('company_id', $this->tenant->id)->count());
        $this->assertSame(PendingWhmcsInvoice::STATUS_SPLIT, $row->fresh()->status);
    }

    public function test_the_accounts_group_is_its_customer_read_whole(): void
    {
        $row = PendingWhmcsInvoice::query()->with('customer:id,name,afm')->find($this->row()->id); // as a list loads it

        $own = collect(app(WhmcsInvoiceSplitter::class)->planGroups($this->tenant, $row))->firstWhere('key', 'reseller');

        $this->assertSame($this->tenant->id, $own['customer']->company_id);
    }

    public function test_a_third_party_known_by_its_afm_only_is_named_by_it(): void
    {
        $groups = collect(app(WhmcsInvoiceSplitter::class)->planGroups($this->tenant, $this->row()));

        $this->assertSame('ΑΦΜ 099206170', $groups->firstWhere('key', 'contact:4')['label']);
    }

    public function test_the_split_pre_selects_the_tenants_document_type(): void
    {
        $this->operator();

        Livewire::test(ListWhmcsInbox::class)
            ->mountTableAction('split_third_party', $this->row())
            ->assertTableActionDataSet(['invoice_type_id' => $this->type->id]);
    }

    /** Review of #716: an unpaid one pre-selects the «επί πιστώσει» type, as «Δημιουργία Παραστατικού» does. */
    public function test_an_unpaid_split_pre_selects_the_unpaid_type(): void
    {
        $this->operator();
        $unpaid = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPYC', 'name' => 'Επί πιστώσει', 'invcount' => 0]);
        $this->tenant->update(['whmcs_default_unpaid_type_id' => $unpaid->id]);
        $row = $this->row();
        $row->update(['payload' => ['status' => 'Unpaid'] + $row->payload]);

        Livewire::test(ListWhmcsInbox::class)
            ->mountTableAction('split_third_party', $row->fresh())
            ->assertTableActionDataSet(['invoice_type_id' => $unpaid->id]);
    }

    /** Review of #716: another tenant's customer on the row is neither used nor named. */
    public function test_another_tenants_customer_is_neither_used_nor_named(): void
    {
        $other = Company::create(['name' => 'O', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off']);
        $foreign = Customer::create(['company_id' => $other->id, 'name' => 'Other tenant SA', 'afm' => '094014201']);
        $row = $this->row();
        $row->update(['customer_id' => $foreign->id]);

        $own = collect(app(WhmcsInvoiceSplitter::class)->planGroups($this->tenant, $row->fresh()))->firstWhere('key', 'reseller');

        $this->assertNull($own['customer']);
        $this->assertStringNotContainsString('Other tenant', $own['label']);
    }

    /** Review of #716: a name of spaces is no name; the ΑΦΜ is shown as the registry key. */
    public function test_a_blank_name_falls_back_to_the_afm(): void
    {
        $row = $this->row();
        $resolution = $row->third_party_resolution;
        $resolution['lines'][0]['contact']['company_name'] = '   ';
        $resolution['lines'][0]['contact']['gr_vatno'] = 'EL099206170';
        $row->update(['third_party_resolution' => $resolution]);

        $groups = collect(app(WhmcsInvoiceSplitter::class)->planGroups($this->tenant, $row->fresh()));

        $this->assertSame('ΑΦΜ 099206170', $groups->firstWhere('key', 'contact:4')['label']);
    }
}
