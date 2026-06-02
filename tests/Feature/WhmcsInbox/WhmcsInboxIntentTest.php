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
 * A+B: the inbox reads the WHMCS client custom fields off the staged payload
 * (resolved via the tenant's whmcs_custom_field_map) so the operator sees the
 * billing intent — τιμολόγιο vs απόδειξη, ΑΦΜ, ΔΟΥ, δραστηριότητα — without
 * opening the invoice.
 */
class WhmcsInboxIntentTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $map): Company
    {
        return Company::create([
            'name' => 'Intent Test',
            'slug' => 'wi-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'whmcs_custom_field_map' => $map,
        ]);
    }

    private function row(Company $tenant, array $customFields): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => random_int(1, 99999),
            'payload' => ['customfields' => $customFields],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_reads_intent_fields_from_payload_via_map(): void
    {
        $tenant = $this->tenant([
            'wantsinvoice' => 10, 'vatno' => 13, 'taxoffice' => 14, 'occupation' => 15,
        ]);

        $row = $this->row($tenant, [
            ['id' => 10, 'name' => 'Θα ήθελα τιμολόγιο', 'value' => 'on'],
            ['id' => 13, 'name' => 'ΑΦΜ', 'value' => 'EL123456789'],
            ['id' => 14, 'name' => 'ΔΟΥ', 'value' => 'Α ΑΘΗΝΩΝ'],
            ['id' => 15, 'name' => 'Δραστηριότητα', 'value' => 'Υπηρεσίες IT'],
        ]);

        $this->assertTrue($row->wantsInvoice());
        $this->assertSame('123456789', $row->whmcsAfm());   // EL prefix stripped
        $this->assertSame('Α ΑΘΗΝΩΝ', $row->whmcsTaxOffice());
        $this->assertSame('Υπηρεσίες IT', $row->whmcsActivity());
    }

    public function test_whmcs_client_name_prefers_company_then_person(): void
    {
        $tenant = $this->tenant([]);

        $withCompany = $this->row($tenant, []);
        $withCompany->update(['payload' => ['companyname' => 'ACME OE', 'firstname' => 'Γιάννης', 'lastname' => 'Παπά']]);
        $this->assertSame('ACME OE', $withCompany->whmcsClientName());

        $person = $this->row($tenant, []);
        $person->update(['payload' => ['companyname' => '', 'firstname' => 'Γιάννης', 'lastname' => 'Παπά']]);
        $this->assertSame('Γιάννης Παπά', $person->whmcsClientName());
    }

    public function test_wants_invoice_false_when_unchecked(): void
    {
        $tenant = $this->tenant(['wantsinvoice' => 10]);
        $row = $this->row($tenant, [['id' => 10, 'name' => 'Θα ήθελα τιμολόγιο', 'value' => '']]);

        $this->assertFalse($row->wantsInvoice());
    }

    public function test_inbox_list_renders_with_whmcs_client_name(): void
    {
        // The «Πρόθεση» column was removed (ΑΦΜ-only matching + file-time type
        // decision); the intent helpers are still covered at the model level
        // above. Here we just assert the list renders the WHMCS client name.
        $tenant = $this->tenant(['wantsinvoice' => 10, 'vatno' => 13]);
        $row = $this->row($tenant, [
            ['id' => 10, 'value' => 'on'],
            ['id' => 13, 'value' => '123456789'],
        ]);
        $row->update(['payload' => array_merge($row->payload, [
            'companyname' => 'ACME OE', 'date' => '2026-04-01', 'total' => '100.00', 'currencycode' => 'EUR',
        ])]);

        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@e.test', 'password' => bcrypt('x')]);
        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        Livewire::test(ListWhmcsInbox::class)
            ->assertOk()
            ->assertSee('ACME OE');      // WHMCS client name column
    }

    public function test_needs_afm_when_wants_invoice_but_no_afm_anywhere(): void
    {
        $tenant = $this->tenant(['wantsinvoice' => 10]);

        // Wants invoice, no ΑΦΜ in WHMCS, no linked customer → needs ΑΦΜ.
        $row = $this->row($tenant, [['id' => 10, 'value' => 'on']]);
        $this->assertTrue($row->needsAfm());

        // Same but with a WHMCS ΑΦΜ present → fine.
        $tenant2 = $this->tenant(['wantsinvoice' => 10, 'vatno' => 13]);
        $ok = $this->row($tenant2, [
            ['id' => 10, 'value' => 'on'],
            ['id' => 13, 'value' => '123456789'],
        ]);
        $this->assertFalse($ok->needsAfm());

        // Doesn't want an invoice → never flagged, even without ΑΦΜ.
        $receipt = $this->row($tenant, [['id' => 10, 'value' => '']]);
        $this->assertFalse($receipt->needsAfm());
    }

    public function test_intent_is_null_when_role_unmapped(): void
    {
        // No wantsinvoice mapping → intent unknown (operator decides).
        $tenant = $this->tenant(['vatno' => 13]);
        $row = $this->row($tenant, [['id' => 13, 'name' => 'ΑΦΜ', 'value' => '123456789']]);

        $this->assertNull($row->wantsInvoice());
        $this->assertSame('123456789', $row->whmcsAfm());
        $this->assertNull($row->whmcsTaxOffice());
    }
}
