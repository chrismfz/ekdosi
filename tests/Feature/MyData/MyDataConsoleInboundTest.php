<?php

namespace Tests\Feature\MyData;

use App\Filament\Pages\MyDataConsole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The myDATA console gains a second direction: "Αδέσποτα από myDATA" —
 * the same RequestTransmittedDocs fetch read the other way, surfacing
 * docs myDATA holds for our AFM with no local ekdosi record.
 *
 * The live fetch hits AADE, so these tests exercise the page wiring +
 * the inbound rendering by injecting a serialized result (the shape
 * MyDataConsole::serialize produces) rather than calling AADE.
 */
class MyDataConsoleInboundTest extends TestCase
{
    use RefreshDatabase;

    private function bootTenantUser(): Company
    {
        $tenant = Company::create([
            'name' => 'myDATA Test',
            'slug' => 'md-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
        ]);

        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@example.test',
            'password' => bcrypt('x'),
        ]);

        Gate::before(fn () => true);
        $this->actingAs($user);
        Filament::setTenant($tenant);

        return $tenant;
    }

    /** @return array<string, mixed> */
    private function fakeResult(): array
    {
        // An income orphan (a real sale filed elsewhere) — the actionable one.
        $orphanIncome = [
            'mark' => '400099999999999', 'uid' => null, 'invoiceId' => null, 'invcode' => null,
            'issuedAt' => '02/04/2026', 'counterpartName' => 'e-τιμολόγιο πελάτης', 'gross' => 124.0,
            'localState' => null, 'localStatus' => null, 'aadeState' => 'VALID',
            'cancelledByMark' => null, 'problem' => 'Στο myDATA, χωρίς τοπική εγγραφή.', 'url' => null,
            'invoiceType' => '1.1', 'invoiceTypeLabel' => 'Τιμολόγιο Πώλησης', 'bucket' => 'income',
        ];
        // A payroll entry (17.1) — must NOT be lumped in as a missed sale.
        $orphanPayroll = [
            'mark' => '400088888888888', 'uid' => null, 'invoiceId' => null, 'invcode' => null,
            'issuedAt' => '30/04/2026', 'counterpartName' => null, 'gross' => 5311.43,
            'localState' => null, 'localStatus' => null, 'aadeState' => 'VALID',
            'cancelledByMark' => null, 'problem' => 'Στο myDATA, χωρίς τοπική εγγραφή.', 'url' => null,
            'invoiceType' => '17.1', 'invoiceTypeLabel' => 'Μισθοδοσία', 'bucket' => 'other',
        ];
        $linked = [
            'mark' => '400011111111111', 'uid' => null, 'invoiceId' => 7, 'invcode' => 'ΤΠΥ7',
            'issuedAt' => '03/04/2026', 'counterpartName' => 'Πελάτης Α', 'gross' => 62.0,
            'localState' => 'VALID', 'localStatus' => 'active', 'aadeState' => 'VALID',
            'cancelledByMark' => null, 'problem' => null, 'url' => 'https://example.test/invoice/7',
            'invoiceType' => '1.1', 'invoiceTypeLabel' => 'Τιμολόγιο Πώλησης', 'bucket' => 'income',
        ];

        return [
            'from' => '01/04/2026', 'to' => '29/05/2026',
            'aadeTotal' => 3, 'localTotal' => 1, 'discrepancyCount' => 2,
            'matched' => [$linked], 'stateMismatch' => [],
            'missingAtAade' => [], 'missingLocally' => [$orphanIncome, $orphanPayroll], 'duplicateLocal' => [],
        ];
    }

    public function test_console_exposes_both_directions(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsole::class)
            ->assertOk()
            ->assertActionExists('reconcile')
            ->assertActionExists('find_orphans');
    }

    public function test_inbound_view_highlights_orphans(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsole::class)
            ->set('ran', true)
            ->set('resultMode', 'inbound')
            ->set('result', $this->fakeResult())
            // Income orphans get the actionable "πωλήσεων" heading + their MARK.
            ->assertSee('Αδέσποτα πωλήσεων')
            ->assertSee('400099999999999')
            // Payroll (17.1) is routed to the informational "λοιπές" bucket,
            // NOT the missed-sales list — and shows its type so it reads right.
            ->assertSee('Λοιπές δικές σου εγγραφές')
            ->assertSee('400088888888888')
            ->assertSee('Μισθοδοσία')
            ->assertSee('Συνδεδεμένα με τοπικό παραστατικό');
    }

    public function test_compare_view_points_to_orphans_without_duplicating_the_table(): void
    {
        $this->bootTenantUser();

        Livewire::test(MyDataConsole::class)
            ->set('ran', true)
            ->set('resultMode', 'compare')
            ->set('result', $this->fakeResult())
            // Compare view surfaces a slim pointer (count + reference), not a
            // second full orphans table. Headlines income orphans.
            ->assertSee('αδέσποτα πωλήσεων')
            ->assertSee('Αδέσποτα από myDATA')
            ->assertSee('Ασυμφωνίες');
    }
}
