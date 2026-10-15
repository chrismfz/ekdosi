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
 * WHMCS reports `total` = subtotal + tax − applied credit, so a credit-paid
 * invoice read «0,00» in the inbox although it is worth 20,46. Every display of
 * the WHMCS amount goes through PendingWhmcsInvoice::documentTotalOf().
 */
class WhmcsInboxDocumentTotalTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->tenant = Company::create([
            'name' => 'Inbox', 'slug' => 'inbox-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function creditPaidRow(): PendingWhmcsInvoice
    {
        // The real WHMCS #32467 shape (feed ≥ 0.54.0): paid fully from credit.
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => 32467,
            'payload' => [
                'invoiceid' => 32467, 'status' => 'Paid', 'currencycode' => 'EUR',
                'subtotal' => '16.50', 'tax' => '3.96', 'taxrate' => '24.000',
                'total' => '0.00', 'credit' => '20.46', 'taxtype' => 'Exclusive',
                'items' => ['item' => [['description' => 'Ανανέωση Domain - example.com', 'amount' => '16.50', 'taxed' => 1]]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_document_total_adds_back_the_applied_credit(): void
    {
        $this->assertSame(20.46, PendingWhmcsInvoice::documentTotalOf(['total' => '0.00', 'credit' => '20.46']));
        $this->assertSame(13.64 + 18.60, PendingWhmcsInvoice::documentTotalOf(['total' => '13.64', 'credit' => '18.60']));
        // Older plugin (no `credit`): unchanged behaviour.
        $this->assertSame(23.56, PendingWhmcsInvoice::documentTotalOf(['total' => '23.56']));

        $row = $this->creditPaidRow();
        $this->assertSame(20.46, $row->whmcsDocumentTotal());
        $this->assertSame(20.46, $row->whmcsCreditApplied());
    }

    public function test_inbox_list_shows_the_document_value_not_zero(): void
    {
        $this->creditPaidRow();

        Livewire::test(ListWhmcsInbox::class)->loadTable()
            ->assertSee('20,46')
            ->assertDontSee('0,00 EUR');
    }

    public function test_invoice_view_shows_the_value_and_the_credit(): void
    {
        $html = view('filament.whmcs-inbox.invoice-view', ['r' => $this->creditPaidRow()])->render();

        $this->assertStringContainsString('20,46 EUR', $html);
        $this->assertStringContainsString('από πίστωση 20,46 EUR', $html);
        $this->assertStringContainsString('Εξοφλήθηκε από πίστωση', $html);
    }

    public function test_partial_credit_labels_the_rest_as_paid_or_owed_by_status(): void
    {
        $row = $this->creditPaidRow();
        $row->payload = array_merge($row->payload, ['total' => '13.64', 'credit' => '18.60', 'status' => 'Paid']);
        $row->save();

        $paid = view('filament.whmcs-inbox.invoice-view', ['r' => $row->fresh()])->render();
        $this->assertStringContainsString('32,24 EUR', $paid);
        $this->assertStringContainsString('Πληρώθηκε (εκτός πίστωσης)', $paid);
        $this->assertStringNotContainsString('Υπόλοιπο προς πληρωμή', $paid);

        $row->payload = array_merge($row->payload, ['status' => 'Unpaid']);
        $row->save();
        $unpaid = view('filament.whmcs-inbox.invoice-view', ['r' => $row->fresh()])->render();
        $this->assertStringContainsString('Υπόλοιπο προς πληρωμή', $unpaid);
    }

    public function test_old_plugin_payload_without_credit_renders_as_before(): void
    {
        $row = $this->creditPaidRow();
        $payload = $row->payload;
        unset($payload['credit']);
        $payload['total'] = '20.46';
        $row->payload = $payload;
        $row->save();

        $html = view('filament.whmcs-inbox.invoice-view', ['r' => $row->fresh()])->render();
        $this->assertStringContainsString('20,46 EUR', $html);
        $this->assertStringNotContainsString('πίστωση', $html);
    }
}
