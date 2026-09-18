<?php

namespace Tests\Feature\Customers;

use App\DTOs\AadeRegistryRecord;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Services\AadeRegistryLookup;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The «Άντληση από ΑΑΔΕ» button on the customer form is diff-aware: it fills
 * EMPTY fields silently, and when an ALREADY-filled field differs from the
 * registry it chains into a per-field picker (ResolvesAadeFormConflicts) instead
 * of silently ignoring the change (the old fill-only-empty behaviour that
 * skipped a moved customer's new address without a word).
 */
class AadeFetchConflictTest extends TestCase
{
    use RefreshDatabase;

    private function boot(): Company
    {
        $tenant = Company::create([
            'name' => 'Fetch Test',
            'slug' => 'fetch-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
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

    /**
     * Bind a fake GSIS lookup returning $record for any AFM, so the button runs
     * without touching the SOAP endpoint or needing stored credentials.
     */
    private function fakeAade(AadeRegistryRecord $record): void
    {
        $this->app->bind(AadeRegistryLookup::class, function ($app, array $params) use ($record) {
            return new class($params['tenant'], $record) extends AadeRegistryLookup
            {
                public function __construct(Company $tenant, private AadeRegistryRecord $rec)
                {
                    parent::__construct($tenant);
                }

                public function findByAfm(string $afm, bool $bypassCache = false): AadeRegistryRecord
                {
                    return $this->rec;
                }
            };
        });
    }

    private function record(array $overrides = []): AadeRegistryRecord
    {
        return new AadeRegistryRecord(
            afm: $overrides['afm'] ?? '094454249',
            name: $overrides['name'] ?? 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ',
            doy: $overrides['doy'] ?? 'ΦΑΕ ΑΘΗΝΩΝ',
            doyCode: '1159',
            active: $overrides['active'] ?? true,
            statusDescr: $overrides['statusDescr'] ?? 'ΕΝΕΡΓΟΣ ΑΦΜ',
            address: $overrides['address'] ?? 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5',
            city: $overrides['city'] ?? 'ΑΘΗΝΑ',
            postcode: $overrides['postcode'] ?? '11111',
            activities: $overrides['activities'] ?? [
                ['code' => '62010000', 'description' => 'Υπηρεσίες', 'kind' => 'ΚΥΡΙΑ'],
            ],
        );
    }

    public function test_fetch_fills_empty_fields_without_opening_a_modal(): void
    {
        $this->boot();
        $this->fakeAade($this->record());

        Livewire::test(CreateCustomer::class)
            ->fillForm(['afm' => '094454249', 'name' => ''])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            ->assertActionNotMounted('resolveAadeConflicts')
            ->assertFormSet([
                'name' => 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ',
                'tax_office' => 'ΦΑΕ ΑΘΗΝΩΝ',
                'address1' => 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5',
                'city' => 'ΑΘΗΝΑ',
                'postcode' => '11111',
                'kad_primary' => '62010000',
                'occupation' => 'Υπηρεσίες',
                'country_code' => 'GR',
            ]);
    }

    public function test_fetch_opens_the_picker_for_a_changed_field_and_applies_the_chosen_one(): void
    {
        $this->boot();
        $this->fakeAade($this->record());

        Livewire::test(CreateCustomer::class)
            // Empty name (a fill) + an OLD address that differs from AADE (a conflict).
            ->fillForm(['afm' => '094454249', 'name' => '', 'address1' => 'ΠΑΛΙΑ ΔΙΕΥΘΥΝΣΗ 1'])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            // Empty field filled immediately…
            ->assertFormSet(['name' => 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ'])
            // …but the typed address is NOT clobbered — it waits in the picker.
            ->assertFormSet(['address1' => 'ΠΑΛΙΑ ΔΙΕΥΘΥΝΣΗ 1'])
            ->assertActionMounted('resolveAadeConflicts')
            ->setActionData(['fields' => ['address1']])
            ->callMountedAction()
            ->assertFormSet(['address1' => 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5']);
    }

    public function test_unchosen_conflict_keeps_the_typed_value(): void
    {
        $this->boot();
        $this->fakeAade($this->record());

        Livewire::test(CreateCustomer::class)
            ->fillForm(['afm' => '094454249', 'address1' => 'ΠΑΛΙΑ ΔΙΕΥΘΥΝΣΗ 1'])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            ->assertActionMounted('resolveAadeConflicts')
            // Submit with nothing ticked → the typed value survives.
            ->setActionData(['fields' => []])
            ->callMountedAction()
            ->assertFormSet(['address1' => 'ΠΑΛΙΑ ΔΙΕΥΘΥΝΣΗ 1']);
    }

    public function test_fetch_is_a_noop_when_everything_already_matches(): void
    {
        $this->boot();
        $this->fakeAade($this->record());

        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'afm' => '094454249',
                'name' => 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ',
                'tax_office' => 'ΦΑΕ ΑΘΗΝΩΝ',
                'address1' => 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5',
                'city' => 'ΑΘΗΝΑ',
                'postcode' => '11111',
                'kad_primary' => '62010000',
                'occupation' => 'Υπηρεσίες',
                'country_code' => 'GR',
            ])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            ->assertActionNotMounted('resolveAadeConflicts')
            ->assertFormSet(['address1' => 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5']);
    }

    public function test_fetch_without_gsis_credentials_notifies_and_opens_no_modal(): void
    {
        // No fakeAade() → the real lookup throws AadeCredentialsInvalid (no creds),
        // which AadeFormFill::lookup catches into a notification + null. No SOAP.
        $this->boot();

        Livewire::test(CreateCustomer::class)
            ->fillForm(['afm' => '094454249', 'name' => 'Ο,ΤΙ ΕΓΡΑΨΑ'])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            ->assertActionNotMounted('resolveAadeConflicts')
            ->assertFormSet(['name' => 'Ο,ΤΙ ΕΓΡΑΨΑ']);
    }

    public function test_edit_page_fetch_opens_the_picker_for_a_moved_customer(): void
    {
        $tenant = $this->boot();
        $this->fakeAade($this->record());

        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'ΕΠΙΣΗΜΗ ΕΠΩΝΥΜΙΑ ΑΕ',
            'afm' => '094454249',
            'address1' => 'ΠΑΛΙΑ ΔΙΕΥΘΥΝΣΗ 1',
        ]);

        Livewire::test(EditCustomer::class, ['record' => $customer->getRouteKey()])
            ->callFormComponentAction('afm', 'fetch_customer_from_aade')
            ->assertActionMounted('resolveAadeConflicts')
            ->setActionData(['fields' => ['address1']])
            ->callMountedAction()
            ->assertFormSet(['address1' => 'ΝΕΑ ΔΙΕΥΘΥΝΣΗ 5']);
    }
}
