<?php

namespace Tests\Feature\EInvoice;

use App\Filament\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Resources\Invoices\Pages\EditInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use App\Models\VatCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Combined ΤΔΑ — Slice 3d: the ΤΔΑ becomes operator-issuable.
 *
 * Covers the two data/UX wirings that make a ΤΔΑ pickable and correctly flagged:
 *   - the legacy normaliser migration flips a pre-3d `ΤΔΑ`-coded invoice_type to
 *     `is_delivery_note=true` (an ETL'd row, or a seeder run before 3d);
 *   - the invoice form pre-sets `is_delivery_note` when a ΤΔΑ series is picked, so
 *     the operator lands on the movement sub-form instead of a mislabelled plain 1.1.
 *
 * (The seeder's ΤΔΑ row itself is asserted in MyDataLookupSeederTest; the combined
 * payload in CombinedTdaPayloadTest.)
 */
class CombinedTdaSeedFormTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../../database/migrations/2026_09_21_000006_normalise_tda_invoice_type_flag.php';

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'ΤΔΑ tenant', 'slug' => 'tda3d-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '800561849',
        ]);
    }

    // ---- legacy normaliser migration --------------------------------------

    public function test_normaliser_flips_a_pre_flag_tda_type_and_is_idempotent(): void
    {
        // unique(company_id, code) forbids two 'ΤΔΑ' rows per tenant, so each
        // scenario lives in its own tenant (the normaliser is tenant-agnostic).
        $t4 = $this->tenant();

        // A pre-3d ΤΔΑ with NO type → flip flag + set 1.1. (+ a ΤΙΜ in the same
        // tenant: different code, so it coexists and must stay untouched.)
        $blank = InvoiceType::create([
            'company_id' => $t4->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ', 'invcount' => 1,
            'mydata_type' => null, 'is_delivery_note' => false,
        ]);
        $tim = InvoiceType::create([
            'company_id' => $t4->id, 'code' => 'ΤΙΜ', 'name' => 'ΤΙΜ', 'invcount' => 1,
            'mydata_type' => '1.1', 'is_delivery_note' => false,
        ]);
        // A pre-3d ΤΔΑ already typed 1.1 → flip flag, keep 1.1.
        $typed = InvoiceType::create([
            'company_id' => $this->tenant()->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ B', 'invcount' => 1,
            'mydata_type' => '1.1', 'is_delivery_note' => false,
        ]);
        // A 'ΤΔΑ'-coded series an operator RECLASSIFIED to a credit type → left alone.
        $reclassified = InvoiceType::create([
            'company_id' => $this->tenant()->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ credit', 'invcount' => 1,
            'mydata_type' => '5.1', 'is_delivery_note' => false,
        ]);
        // Already flagged → untouched (idempotent target).
        $already = InvoiceType::create([
            'company_id' => $this->tenant()->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ ok', 'invcount' => 1,
            'mydata_type' => '1.1', 'is_delivery_note' => true,
        ]);

        (require self::MIGRATION)->up();

        $this->assertTrue((bool) $blank->fresh()->is_delivery_note);
        $this->assertSame('1.1', $blank->fresh()->mydata_type);

        $this->assertTrue((bool) $typed->fresh()->is_delivery_note);
        $this->assertSame('1.1', $typed->fresh()->mydata_type);

        // Reclassified series: type + flag both untouched (its §8.1 type is intent).
        $this->assertFalse((bool) $reclassified->fresh()->is_delivery_note);
        $this->assertSame('5.1', $reclassified->fresh()->mydata_type);

        $this->assertTrue((bool) $already->fresh()->is_delivery_note);
        $this->assertFalse((bool) $tim->fresh()->is_delivery_note);

        // Idempotent: a second run changes nothing (no row still matches the filter).
        (require self::MIGRATION)->up();
        $this->assertFalse((bool) $reclassified->fresh()->is_delivery_note);
        $this->assertFalse((bool) $tim->fresh()->is_delivery_note);
    }

    // ---- the invoice form pre-sets is_delivery_note from the type ----------

    public function test_picking_a_tda_type_pre_sets_the_delivery_note_flag(): void
    {
        $tenant = $this->tenant();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        $tda = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΔΑ', 'name' => 'Τιμολόγιο–Δελτίο Αποστολής',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true, 'show_on_menu' => true,
        ]);
        $plain = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο Πώλησης',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => false, 'show_on_menu' => true,
        ]);

        // Picking the ΤΔΑ series reveals the movement section by pre-setting the flag.
        Livewire::test(CreateInvoice::class, ['tenant' => $tenant->slug])
            ->fillForm(['invoice_type_id' => $tda->id])
            ->assertFormSet(['is_delivery_note' => true]);

        // Picking a plain series clears it (a ΤΔΑ→ΤΙΜ switch leaves no stale header).
        Livewire::test(CreateInvoice::class, ['tenant' => $tenant->slug])
            ->fillForm(['invoice_type_id' => $tda->id])
            ->fillForm(['invoice_type_id' => $plain->id])
            ->assertFormSet(['is_delivery_note' => false]);
    }

    public function test_a_plain_invoice_saves_without_the_hidden_movement_fields(): void
    {
        // THE regression guard: the movement fields are ->required() but live inside a
        // Section gated ->visible(is_delivery_note). A plain (non-ΤΔΑ) invoice must save
        // with none of them filled — if the hidden required fields were validated, ALL
        // normal invoicing would break (a P0). Prove a plain invoice creates cleanly.
        $tenant = $this->tenant();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        $plain = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΙΜ', 'name' => 'Τιμολόγιο Πώλησης',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => false, 'show_on_menu' => true,
        ]);
        VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525']);

        Livewire::test(CreateInvoice::class, ['tenant' => $tenant->slug])
            ->fillForm([
                'invoice_type_id' => $plain->id,
                'customer_id' => $customer->id,
                'issued_at' => now(),
                'lines' => [
                    ['product_descr' => 'Υπηρεσία', 'qty' => 1, 'price_per_item' => 100, 'discount' => 0, 'vat_percent' => '24.00'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $invoice = Invoice::where('company_id', $tenant->id)->where('invoice_type_id', $plain->id)->firstOrFail();
        $this->assertFalse((bool) $invoice->is_delivery_note);
        $this->assertNull($invoice->move_purpose);
        $this->assertNull($invoice->vehicle_number);
    }

    public function test_toggling_the_flag_off_on_a_draft_clears_the_movement_columns(): void
    {
        // 3d-a review P2: turning «Είναι και Δελτίο Αποστολής» OFF on a draft hides the
        // movement fields (not dehydrated), so their stale values would survive. The
        // EditInvoice mutate hook clears them so a now-plain 1.1 carries no orphan header.
        $tenant = $this->tenant();
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@example.test', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant($tenant);

        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'ΤΔΑ', 'name' => 'ΤΔΑ',
            'invcount' => 1, 'mydata_type' => '1.1', 'is_delivery_note' => true, 'show_on_menu' => true,
        ]);
        VatCategory::create(['company_id' => $tenant->id, 'description' => '24%', 'rate' => 24, 'is_default' => true]);
        $customer = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης', 'afm' => '997073525']);

        $invoice = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'ΤΔΑ9', 'code' => 9,
            'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'issued_at' => now(), 'local_status' => 'draft',
            'company_name' => 'Πελάτης', 'vat_no' => '997073525',
            'is_delivery_note' => true, 'without_digital_transport_tracking' => true,
            'move_purpose' => 1, 'vehicle_number' => 'ΑΒΓ1234', 'transport_type' => 1,
            'loading_street' => 'Φόρτωση 5', 'loading_postcode' => '11111', 'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παράδοση 9', 'delivery_postcode' => '22222', 'delivery_city' => 'Θεσσαλονίκη',
        ]);
        $invoice->lines()->create([
            'company_id' => $tenant->id, 'product_descr' => 'X', 'qty' => 1,
            'price_per_item' => 100, 'discount' => 0, 'vat_percent' => 24,
        ]);

        Livewire::test(EditInvoice::class, ['record' => $invoice->id, 'tenant' => $tenant->slug])
            ->fillForm(['is_delivery_note' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $fresh = $invoice->fresh();
        $this->assertFalse((bool) $fresh->is_delivery_note);
        $this->assertNull($fresh->move_purpose);
        $this->assertNull($fresh->vehicle_number);
        $this->assertNull($fresh->transport_type);
        $this->assertNull($fresh->loading_street);
        $this->assertNull($fresh->delivery_street);
        $this->assertFalse((bool) $fresh->without_digital_transport_tracking);
    }
}
