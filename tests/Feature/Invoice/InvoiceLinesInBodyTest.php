<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\InvoicePaymentsRelationManager;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Invoice LINES now render in the page BODY (InvoiceInfolist «Γραμμές» section,
 * between the header cards and the totals) so the view reads like the printed
 * παραστατικό — moved off the bottom «Lines» tab. Dropping that relation manager
 * also promotes «Πληρωμές» to the first tab.
 */
class InvoiceLinesInBodyTest extends TestCase
{
    use RefreshDatabase;

    private function boot(Company $tenant): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);
    }

    public function test_lines_render_in_the_page_body_with_their_values(): void
    {
        $tenant = Company::create([
            'name' => 'L', 'slug' => 'l-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $this->boot($tenant);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => 1, 'invcode' => 'TPY100',
            'issued_at' => now(), 'local_status' => 'active',
        ]);
        InvoiceLine::create([
            'company_id' => $tenant->id, 'invoice_id' => $invoice->id, 'product_descr' => 'ΓΡΑΜΜΗ ΔΟΚΙΜΗΣ XYZ',
            'metric_unit' => 'τεμ', 'qty' => 2, 'price_per_item' => 50, 'vat_percent' => 24,
            'net_price' => 100, 'gross_price' => 124, 'notes' => 'ΣΗΜΕΙΩΣΗ ΓΡΑΜΜΗΣ ΤΕΣΤ',
        ]);

        Livewire::test(ViewInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->assertSee('Γραμμές')               // the body section heading
            ->assertSee('ΓΡΑΜΜΗ ΔΟΚΙΜΗΣ XYZ')    // the line renders in the body
            ->assertSee('ΣΗΜΕΙΩΣΗ ΓΡΑΜΜΗΣ ΤΕΣΤ') // the per-line note renders under the description (like the PDF)
            // …and it renders as HTML, not double-escaped text: the note markup must
            // reach the DOM as a real <div> (needs `->html()` on the entry). Asserting
            // the note text alone would pass even if `->html()` were dropped and the
            // markup escaped to `&lt;div…&gt;`, so guard the raw markup explicitly.
            ->assertSee('<div style="margin-top:.15rem', false)
            ->assertSee('E3 (ΑΑΔΕ)');            // the E3 column renders (its state/tooltip closures run)
    }

    public function test_lines_render_in_deterministic_id_order(): void
    {
        $tenant = Company::create([
            'name' => 'O', 'slug' => 'o-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        $this->boot($tenant);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);
        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invoice_type_id' => $type->id, 'code' => 1, 'invcode' => 'TPY100',
            'issued_at' => now(), 'local_status' => 'active',
        ]);
        foreach (['ΑΛΦΑ', 'ΒΗΤΑ', 'ΓΑΜΑ'] as $descr) {
            InvoiceLine::create([
                'company_id' => $tenant->id, 'invoice_id' => $invoice->id, 'product_descr' => $descr,
                'qty' => 1, 'price_per_item' => 10, 'vat_percent' => 24, 'net_price' => 10, 'gross_price' => 12.4,
            ]);
        }

        // The relation is ordered by id → stable insertion order (ΑΛΦΑ → ΒΗΤΑ → ΓΑΜΑ).
        $this->assertSame(['ΑΛΦΑ', 'ΒΗΤΑ', 'ΓΑΜΑ'], $invoice->fresh()->lines->pluck('product_descr')->all());
    }

    public function test_payments_is_the_first_tab_and_the_lines_tab_is_gone(): void
    {
        $relations = InvoiceResource::getRelations();

        $this->assertSame(InvoicePaymentsRelationManager::class, $relations[0], '«Πληρωμές» must be the first tab');
        $this->assertNotContains(
            'App\\Filament\\Resources\\Invoices\\RelationManagers\\LinesRelationManager',
            $relations,
            'the invoice «Lines» relation manager must no longer be a tab',
        );
    }
}
