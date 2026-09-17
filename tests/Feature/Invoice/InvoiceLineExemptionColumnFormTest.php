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
 * Regression (sibling of the «Σημείωση» fix): the §8.3 «Αιτία απαλλαγής ΦΠΑ»
 * (`vat_exemption_category`) is `required` on a 0% line (AADE [217]) but had NO
 * column in the lines table-repeater, so — like the note before it — it was
 * dropped (Repeater renders one cell per column, in order). The auto-suggest
 * (`VatExemptionGuidance::recommendForType`) filled it only for types 2.2/1.2/1.3;
 * on a domestic/other type (1.1/2.1/2.3 → null) a 0% line demanded a reason the
 * operator had no field to enter — an invisible-required dead end. An «Αιτία 0%»
 * column was added; the Select renders on a 0% line so it is enterable by hand.
 */
class InvoiceLineExemptionColumnFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_lines_table_exposes_the_exemption_column_and_the_field_renders_for_a_zero_rate_line(): void
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
        // 2.1 is exactly a type recommendForType() returns null for — the case that
        // used to strand the operator with no way to supply the required reason.
        InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1',
        ]);

        Livewire::test(CreateInvoice::class)
            // The 10th column header renders (the table now has a slot for the reason).
            ->assertSee('Αιτία 0%')
            // …but the default line is 24%, so the §8.3 Select stays hidden (empty
            // cell) — the column never clutters a normal, VAT-bearing line.
            ->assertDontSee('Αιτία απαλλαγής ΦΠΑ (§8.3)')
            // Put a line at 0% and the §8.3 Select must render: its label is in the
            // DOM only when the field is NOT hidden AND sits within the column count.
            // A dropped child (the old bug) produces no field and no label.
            ->fillForm([
                'lines' => [
                    [
                        'product_descr' => 'ΔΩΡΕΑΝ ΓΡΑΜΜΗ', 'qty' => 1,
                        'price_per_item' => 100, 'discount' => 0, 'vat_percent' => '0.00',
                    ],
                ],
            ])
            ->assertSee('Αιτία απαλλαγής ΦΠΑ (§8.3)')
            // The «ποια αιτία, πότε» guidance rides a hover tooltip (hint icon), not a
            // helperText — so it stays available without inflating the narrow 0% cell.
            ->assertSee('Υποχρεωτικό για 0%');
    }
}
