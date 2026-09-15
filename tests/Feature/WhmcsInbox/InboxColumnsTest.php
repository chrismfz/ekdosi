<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Pages\ListWhmcsInbox;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * INBOX-COLS: the WHMCS inbox table was compacted (11 visible columns → 6) by
 * stacking related data in one cell (source + date under the WHMCS #, the paid
 * status under the amount), so the table finally fits on one screen. A filed row
 * links to the ekdosi παραστατικό it produced (invoice code → view). This locks
 * the stacked rendering + the filed→invoice link.
 */
class InboxColumnsTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Company
    {
        Gate::before(fn () => true);
        $company = Company::create([
            'name' => 'Inbox Cols', 'slug' => 'ic-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $user = User::create(['name' => 'A', 'email' => 'a-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($company->id);
        $this->actingAs($user);
        Filament::setTenant($company);

        return $company;
    }

    public function test_unpaid_pending_row_stacks_date_and_paid_status(): void
    {
        $company = $this->boot();
        PendingWhmcsInvoice::create([
            'company_id' => $company->id,
            'whmcs_invoice_id' => 31345,
            'payload' => ['date' => '2026-04-17', 'total' => 245.52, 'currencycode' => 'EUR', 'status' => 'Unpaid'],
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);

        Livewire::test(ListWhmcsInbox::class)
            ->assertSuccessful()
            ->assertSee('#31345')       // WHMCS # (main)
            ->assertSee('2026-04-17')   // invoice date folded into the # column description
            ->assertSee('245,52 EUR')   // amount (main of the merged Ποσό column)
            ->assertSee('Απλήρωτο')     // paid status folded into the Ποσό description
            // …coloured amber via an HtmlString span (green «Πληρωμένο» / amber
            // «Απλήρωτο»), rendered UNESCAPED because a column description passes
            // through e() which lets an Htmlable through. Asserting the raw opening
            // tag (not just the class substring) is what proves it's unescaped: an
            // escaped HtmlString would render «&lt;span class=&quot;…» and fail this.
            ->assertSee('<span class="text-warning-600', false);
    }

    public function test_filed_row_links_to_the_ekdosi_invoice_by_code(): void
    {
        $company = $this->boot();
        $type = InvoiceType::create(['company_id' => $company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $invoice = Invoice::create([
            'company_id' => $company->id, 'invoice_type_id' => $type->id,
            'code' => 6663, 'invcode' => 'TPY6663', 'issued_at' => now(),
            'local_status' => 'active', 'gross_total' => 81.84, 'net_total' => 66.0,
        ]);
        PendingWhmcsInvoice::create([
            'company_id' => $company->id,
            'whmcs_invoice_id' => 32211,
            'payload' => ['date' => '2026-09-01', 'total' => 81.84, 'currencycode' => 'EUR', 'status' => 'Paid'],
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'invoice_id' => $invoice->id,
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
        ]);

        // The default «Ανοιχτά» tab shows προς-έλεγχο + σε-αναμονή; switch to the
        // «Καταχωρημένα» tab to see filed rows (status is now driven by tabs).
        Livewire::test(ListWhmcsInbox::class)
            ->set('activeTab', 'filed')
            ->assertSuccessful()
            ->assertSee('TPY6663'); // filed → linked ekdosi invoice code (in the Κατάσταση description)
    }
}
