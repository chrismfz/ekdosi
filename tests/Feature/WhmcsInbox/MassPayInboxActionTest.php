<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two mass-pay row actions («Ενοποίηση σε ένα» / «Ανάλυση σε επιμέρους»)
 * are wired to MassPayConsolidator and gated to a held mass-pay row.
 */
class MassPayInboxActionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private PendingWhmcsInvoice $massPay;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);

        $this->tenant = Company::create([
            'name' => 'Act OE', 'slug' => 'act-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);

        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Γ.Λαουνάρος', 'afm' => '997890734']);
        $this->massPay = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 32310, 'whmcs_userid' => 979,
            'customer_id' => $customer->id, 'status' => PendingWhmcsInvoice::STATUS_HELD,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'payload' => [
                'invoiceid' => 32310, 'userid' => 979, 'total' => '647.70', 'taxrate' => '24.000', 'status' => 'Paid',
                'items' => ['item' => [
                    ['type' => 'Invoice', 'relid' => 32280, 'description' => 'Αρ. Λογαριασμού #32280', 'amount' => '23.56', 'taxed' => '0'],
                    ['type' => 'Invoice', 'relid' => 32263, 'description' => 'Αρ. Λογαριασμού #32263', 'amount' => '82.26', 'taxed' => '0'],
                    ['type' => 'Invoice', 'relid' => 32256, 'description' => 'Αρ. Λογαριασμού #32256', 'amount' => '541.88', 'taxed' => '0'],
                ]],
            ],
        ]);

        // Fake WHMCS fetcher → the child GetInvoice payloads (real shape, total=0).
        $this->app->instance(WhmcsInvoiceFetcher::class, new class extends WhmcsInvoiceFetcher
        {
            public function __construct() {}

            public function for(Company $tenant): ?callable
            {
                $c = fn (int $id, string $type, string $desc, string $net): array => [
                    'invoiceid' => $id, 'userid' => 979, 'total' => '0.00', 'taxrate' => '24.000', 'status' => 'Paid',
                    'items' => ['item' => [['type' => $type, 'description' => $desc, 'amount' => $net, 'taxed' => '1']]],
                ];
                $map = [
                    32256 => $c(32256, 'Hosting', 'Supermicro AMD Server', '437.00'),
                    32263 => $c(32263, 'Hosting', 'Semi Dedicated 8C', '66.34'),
                    32280 => $c(32280, 'Domain', 'Ανανέωση Domain', '19.00'),
                ];

                return fn (int $id): ?array => $map[$id] ?? null;
            }
        });
    }

    public function test_consolidate_action_folds_the_masspay_into_one_pending_row(): void
    {
        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'held')
            ->callTableAction('consolidate_masspay', $this->massPay)
            ->assertHasNoTableActionErrors();

        $fresh = $this->massPay->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $fresh->status);
        $this->assertSame('647.70', $fresh->payload['total']);
        $this->assertEqualsCanonicalizing([32280, 32263, 32256], $fresh->payload['ekdosi_consolidated_children']);
    }

    public function test_explode_action_resolves_the_masspay_and_stages_children(): void
    {
        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'held')
            ->callTableAction('explode_masspay', $this->massPay)
            ->assertHasNoTableActionErrors();

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->massPay->fresh()->status);
        $this->assertSame(3, PendingWhmcsInvoice::where('company_id', $this->tenant->id)
            ->where('masspay_parent_id', $this->massPay->id)->count());
    }
}
