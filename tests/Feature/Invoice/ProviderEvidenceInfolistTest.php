<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PROV-003 (c): the provider evidence — licence / UID / authentication code +
 * the verification URL — is shown on the invoice PAGE (myDATA / Πάροχος section),
 * not only on the PDF, and resolved from the frozen snapshot so screen == print.
 */
class ProviderEvidenceInfolistTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $provider = 'gr-provider'): Company
    {
        return Company::create([
            'name' => 'Prov', 'slug' => 'ev-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => $provider,
            'einvoice_provider_key' => $provider === 'gr-provider' ? 'invosign' : '',
            'einvoice_provider_mode' => $provider === 'gr-provider' ? 'production' : 'off',
            'mydata_mode' => $provider === 'gr-mydata' ? 'sandbox' : 'off',
            'afm' => '800561849',
        ]);
    }

    private function providerInvoice(Company $tenant): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id,
            'code' => 1, 'invcode' => 'TPY100', 'issued_at' => now(), 'local_status' => 'active',
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400001964594701', 'mydata_url' => 'https://invosign.gr/viewinvoice.php?afm=EL800561849&uid=ABC'])->save();
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400001964594701', 'mydata_action' => 'PROVIDER_INSERT',
            'provider_key' => 'invosign',
            'provider_identity' => [
                'key' => 'invosign', 'commercial_name' => 'iNVO Sign', 'legal_name' => 'GV Solutions',
                'site' => 'invosign.gr', 'aade_code' => '030', 'licence_no' => 'LIC_AT_ISSUE_V1',
            ],
            'authentication_code' => 'B65CE2D6DD465A80', 'uid' => '43FB8F2A65E58B5B',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        return $invoice;
    }

    private function boot(Company $tenant): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);
    }

    public function test_invoice_page_shows_the_provider_evidence_from_the_snapshot(): void
    {
        $tenant = $this->tenant();
        $this->boot($tenant);
        $invoice = $this->providerInvoice($tenant);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertSee('iNVO Sign')            // provider name (snapshot)
            ->assertSee('LIC_AT_ISSUE_V1')      // Αριθμός Αδειοδότησης (snapshot)
            ->assertSee('43FB8F2A65E58B5B')     // Αναγνωριστικό (UID)
            ->assertSee('B65CE2D6DD465A80');    // Υπογραφή (authentication code)
    }

    public function test_direct_mydata_invoice_shows_no_provider_evidence(): void
    {
        $tenant = $this->tenant('gr-mydata');
        $this->boot($tenant);

        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ',
            'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id,
            'code' => 1, 'invcode' => 'TPY100', 'issued_at' => now(), 'local_status' => 'active',
        ]);
        $invoice->forceFill(['mydata_state' => 'VALID', 'mydata_mark' => '400009999999999'])->save();
        MyDataMark::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id,
            'mark' => '400009999999999', 'mydata_action' => 'INSERT',
            'mark_date' => now()->toDateString(), 'mark_time' => now()->toTimeString(),
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertDontSee('Αριθμός Αδειοδότησης');
    }
}
