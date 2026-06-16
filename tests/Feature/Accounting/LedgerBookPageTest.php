<?php

namespace Tests\Feature\Accounting;

use App\Filament\Pages\LedgerBook;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Βιβλίο Εσόδων-Εξόδων page renders (self-styled blade, no build dependency)
 * and surfaces the myDATA ΜΑΡΚ + κατάσταση columns added in #6.
 */
class LedgerBookPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_renders_with_mark_and_status_columns(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        $tenant = Company::create([
            'name' => 'Ledger OE', 'slug' => 'ledger-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        Filament::setTenant($tenant);

        // A populated, filed income row IN the default period (current month), so
        // the journal actually renders a ΜΑΡΚ value + the VALID status badge.
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'code' => 'TPY', 'name' => 'Τιμολόγιο', 'invcount' => 1,
            'mydata_type' => '2.1', 'mydata_income_class_category' => 'category1_3',
        ]);
        $cust = Customer::create(['company_id' => $tenant->id, 'name' => 'Πελάτης ΑΕ']);
        $inv = Invoice::create([
            'company_id' => $tenant->id, 'invcode' => 'TPY1', 'code' => 1,
            'invoice_type_id' => $type->id, 'customer_id' => $cust->id,
            'issued_at' => now(), 'local_status' => 'active',
            'net_total' => 100, 'gross_total' => 124, 'header_discount_percent' => 0,
        ]);
        $inv->forceFill(['mydata_mark' => '400000000000123', 'mydata_state' => 'VALID'])->saveQuietly();

        Livewire::test(LedgerBook::class)
            ->assertSuccessful()
            ->assertSee('Ημερολόγιο')
            ->assertSee('ΜΑΡΚ')
            ->assertSee('Σύνολα περιόδου')
            // The populated row surfaces its myDATA ΜΑΡΚ + status badge.
            ->assertSee('400000000000123')
            ->assertSee('VALID')
            // Λογιστική όψη: Έσοδα/Έξοδα columns + the footing summary line.
            ->assertSee('Καθαρό αποτέλεσμα')
            ->assertSee('Σύνολα');
    }

    public function test_period_preset_drives_the_window_and_manual_edit_is_custom(): void
    {
        Gate::before(fn () => true);
        $this->actingAs(User::create([
            'name' => 'Op', 'email' => 'op-'.uniqid().'@test.local', 'password' => bcrypt('x'),
        ]));
        Filament::setTenant(Company::create([
            'name' => 'L OE', 'slug' => 'l-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]));

        Livewire::test(LedgerBook::class)
            ->assertSet('period', 'this_month')
            ->assertSet('from', now()->startOfMonth()->toDateString())
            ->assertSet('to', now()->endOfMonth()->toDateString())
            // Pick a preset → window recomputes.
            ->set('period', 'prev_year')
            ->assertSet('from', now()->subYear()->startOfYear()->toDateString())
            ->assertSet('to', now()->subYear()->endOfYear()->toDateString())
            // A manual date edit flips the preset to «Προσαρμογή».
            ->set('from', '2026-02-01')
            ->assertSet('period', 'custom');
    }
}
