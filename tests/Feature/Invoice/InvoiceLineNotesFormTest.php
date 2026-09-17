<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression: the invoice lines table («Γραμμές») is a Filament table-repeater,
 * which renders ONE cell per declared column and DROPS any schema child beyond the
 * last column. With 8 columns but `notes` as the 10th child, «Σημείωση γραμμής»
 * was never rendered — the operator could not enter a per-line note at all. A
 * «Σημείωση» column was added (with `notes` moved within the column count) so it is
 * enterable; it prints on the PDF and shows on the invoice view.
 */
class InvoiceLineNotesFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_create_form_lines_table_has_a_note_column(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        $tenant = Company::create([
            'name' => 'N', 'slug' => 'n-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
        Filament::setTenant($tenant);
        InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);

        Livewire::test(CreateInvoice::class)
            ->assertSee('Σημείωση')          // the column header renders in the lines table
            // AND the notes FIELD actually maps into a cell — its label «Σημείωση γραμμής»
            // is only in the DOM when the field renders (a child dropped beyond the last
            // column, the old bug, produces no field and no label).
            ->assertSee('Σημείωση γραμμής');
    }
}
