<?php

namespace Tests\Feature\Delivery;

use App\Filament\Resources\DeliveryNotes\Pages\CreateDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Pages\ViewDeliveryNote;
use App\Filament\Resources\DeliveryNotes\Schemas\DeliveryNoteForm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DeliveryMark;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Delivery\DeliveryNoteSubmitter;
use App\Support\MyData\DeliveryGuidance;
use Filament\Facades\Filament;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filament resource + issue-flow coverage for Παραστατικά Διακίνησης (D2 part 3).
 *
 * The numbering + draft-create path is exercised through the real Livewire
 * CreateDeliveryNote page (locks in InvoiceNumberer wiring + the scenario-driven
 * move_purpose + lines). Required-field validation is asserted on the same page.
 *
 * The «Έκδοση» action is NOT driven through the Livewire action here: wiring the
 * Filament action to the submitter's Guzzle MockHandler seam means swapping the
 * container binding for DeliveryNoteSubmitter (the action resolves it via
 * app(..., ['tenant' => ...])). Instead we (a) assert the action exists +
 * draft-only visibility on the Livewire page, and (b) drive the submitter
 * directly with the MockHandler — the exact same call the action makes — to lock
 * in the draft→VALID flip + delivery_marks row. This keeps the network seam in
 * one place (mirrors DeliveryNoteSubmitterTest) while still proving the action's
 * gate.
 */
class DeliveryNoteResourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $deliveryType;

    private Customer $recipient;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Delivery resource test',
            'slug' => 'deliv-res-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'sandbox',
            'afm' => '800561849',
            'address' => 'Εκδότη 1',
            'city' => 'Αθήνα',
            'postcode' => '11111',
            'mydata_aade_id_sandbox' => 'TESTUSER',
            'mydata_subscription_key_sandbox' => 'TESTKEY',
        ]);

        $this->deliveryType = InvoiceType::create([
            'company_id' => $this->tenant->id,
            'code' => 'ΔΑΠ',
            'name' => 'Δελτίο Αποστολής',
            'invcount' => 1,
            'mydata_type' => '9.3',
        ]);

        $this->recipient = Customer::create([
            'company_id' => $this->tenant->id,
            'name' => 'Παραλήπτης ΑΕ',
            'afm' => '123456789',
            'address1' => 'Παραλήπτη 5',
            'city' => 'Θεσσαλονίκη',
            'postcode' => '54321',
        ]);

        $this->user = User::factory()->create();
        // Bypass policy/permission gates — the resource's canAccess +
        // action authorize() ride on real Shield permissions that only
        // exist after shield:generate; the proven Filament-test idiom is to
        // let the gate through and assert the screen/behaviour (mirrors
        // InvoiceQuoteFormRenderTest).
        Gate::before(fn () => true);
        $this->actingAs($this->user);

        // Drive every Filament call inside this tenant's context (tenancy
        // sets company_id on create — mirrors the panel).
        Filament::setTenant($this->tenant);
    }

    public function test_create_page_allocates_aa_and_persists_draft_with_scenario_and_lines(): void
    {
        Livewire::test(CreateDeliveryNote::class)
            ->fillForm([
                // The scenario picker is a UI helper that fills move_purpose;
                // set move_purpose directly here (the afterStateUpdated runs in
                // the browser). The scenario→move_purpose mapping is unit-tested
                // in DeliveryGuidanceTest; here we assert the form persists the
                // value the picker would set.
                'move_purpose' => DeliveryGuidance::scenario('sale')['move_purpose'],
                'delivery_type_id' => $this->deliveryType->id,
                'issued_at' => now(),
                'recipient_name' => 'Παραλήπτης ΑΕ',
                'recipient_afm' => '123456789',
                'customer_id' => $this->recipient->id,
                'loading_street' => 'Εκδότη 1',
                'loading_postcode' => '11111',
                'loading_city' => 'Αθήνα',
                'delivery_street' => 'Παραλήπτη 5',
                'delivery_postcode' => '54321',
                'delivery_city' => 'Θεσσαλονίκη',
                'transport_type' => 4,           // ΦΙΧ
                'vehicle_number' => 'ΙΑΒ1234',
                'dispatch_at' => now()->addHour(),
                'lines' => [
                    ['product_descr' => 'Κιβώτια', 'qty' => 3, 'measurement_unit' => 1],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $note = DeliveryNote::query()->where('company_id', $this->tenant->id)->latest('id')->first();
        $this->assertNotNull($note);
        // ΑΑ allocated from the type counter (started at 1).
        $this->assertSame(1, (int) $note->code);
        $this->assertSame('ΔΑΠ1', $note->invcode);
        $this->assertSame('draft', $note->local_status);
        $this->assertSame('9.3', $note->mydata_type);  // snapshotted from the type
        $this->assertSame(1, (int) $note->move_purpose); // sale → Πώληση
        $this->assertCount(1, $note->lines);
        $this->assertSame('Κιβώτια', $note->lines->first()->product_descr);

        // The type counter advanced for the next allocation.
        $this->assertSame(2, (int) $this->deliveryType->fresh()->invcount);
    }

    public function test_required_fields_fail_validation(): void
    {
        Livewire::test(CreateDeliveryNote::class)
            ->fillForm([
                'move_purpose' => 8,
                'delivery_type_id' => $this->deliveryType->id,
                'issued_at' => now(),
                // Missing: vehicle_number, transport_type, and all mandatory
                // addresses. (dispatch_at has a now() default — it can't be
                // blanked through the form, so it's not asserted here.)
                'loading_street' => '',
                'loading_postcode' => '',
                'loading_city' => '',
                'delivery_street' => '',
                'delivery_postcode' => '',
                'delivery_city' => '',
                'vehicle_number' => '',
                'lines' => [
                    ['product_descr' => 'X', 'qty' => 1, 'measurement_unit' => 1],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'transport_type',
                'vehicle_number',
                'loading_street',
                'loading_postcode',
                'loading_city',
                'delivery_street',
                'delivery_postcode',
                'delivery_city',
            ]);

        $this->assertSame(0, DeliveryNote::query()->where('company_id', $this->tenant->id)->count());
    }

    public function test_issue_action_uses_provider_label_for_provider_tenant(): void
    {
        $this->tenant->forceFill([
            'einvoice_provider' => 'gr-provider',
            'einvoice_provider_key' => 'invosign',
            'einvoice_provider_mode' => 'sandbox',
            'mydata_mode' => 'off',
        ])->save();
        Filament::setTenant($this->tenant->fresh());

        $draft = $this->makeDraft();

        Livewire::test(ViewDeliveryNote::class, ['record' => $draft->getKey()])
            ->assertActionVisible('issue')
            ->assertSee('Έκδοση μέσω Παρόχου');
    }

    public function test_issue_action_exists_and_is_draft_only(): void
    {
        $draft = $this->makeDraft();

        Livewire::test(ViewDeliveryNote::class, ['record' => $draft->getKey()])
            ->assertActionVisible('issue');

        // Simulate an already-filed note → action hidden.
        $draft->forceFill([
            'mydata_state' => 'VALID',
            'mydata_mark' => '480301204040191',
            'local_status' => 'active',
        ])->save();

        Livewire::test(ViewDeliveryNote::class, ['record' => $draft->fresh()->getKey()])
            ->assertActionHidden('issue');
    }

    public function test_submit_flips_draft_to_valid_and_writes_delivery_mark(): void
    {
        $draft = $this->makeDraft();

        // Same call the «Έκδοση» action makes, with the network seam mocked
        // (mirrors DeliveryNoteSubmitterTest's successResponseXml()).
        $mock = new MockHandler([
            new GuzzleResponse(200, [], $this->successResponseXml()),
        ]);

        $mark = (new DeliveryNoteSubmitter($this->tenant, $mock))->submit($draft);

        $this->assertInstanceOf(DeliveryMark::class, $mark);
        $this->assertSame('480301204040191', $mark->mark);

        $fresh = $draft->fresh();
        $this->assertSame('VALID', $fresh->mydata_state);
        $this->assertSame('480301204040191', $fresh->mydata_mark);
        $this->assertSame('active', $fresh->local_status);
        $this->assertSame('registered', $fresh->delivery_state);

        $this->assertSame(1, DeliveryMark::query()->where('delivery_note_id', $draft->id)->count());
    }

    public function test_recipient_search_unions_customers_and_suppliers(): void
    {
        Supplier::create([
            'company_id' => $this->tenant->id,
            'name' => 'Datacenter ΕΠΕ',
            'afm' => '999888777',
        ]);

        $results = DeliveryNoteForm::searchRecipients('Π');
        $this->assertArrayHasKey('c:'.$this->recipient->id, $results);

        $supplierResults = DeliveryNoteForm::searchRecipients('Datacenter');
        $supplier = Supplier::query()->where('company_id', $this->tenant->id)->first();
        $this->assertArrayHasKey('s:'.$supplier->id, $supplierResults);

        // A supplier pick snapshots afm+name but leaves customer_id null.
        $resolved = DeliveryNoteForm::resolveRecipient('s:'.$supplier->id);
        $this->assertNull($resolved['customer_id']);
        $this->assertSame('999888777', $resolved['afm']);
        $this->assertSame('Datacenter ΕΠΕ', $resolved['name']);

        // A customer pick sets customer_id.
        $resolvedC = DeliveryNoteForm::resolveRecipient('c:'.$this->recipient->id);
        $this->assertSame($this->recipient->id, $resolvedC['customer_id']);
    }

    private function makeDraft(): DeliveryNote
    {
        $note = DeliveryNote::create([
            'company_id' => $this->tenant->id,
            'invcode' => 'ΔΑΠ1',
            'code' => 1,
            'delivery_type_id' => $this->deliveryType->id,
            'customer_id' => $this->recipient->id,
            'issued_at' => now(),
            'mydata_type' => '9.3',
            'move_purpose' => 8,
            'dispatch_at' => now()->addHour(),
            'vehicle_number' => 'ΙΑΒ1234',
            'transport_type' => 4,
            'third_party_collection' => false,
            'loading_street' => 'Εκδότη 1',
            'loading_postcode' => '11111',
            'loading_city' => 'Αθήνα',
            'delivery_street' => 'Παραλήπτη 5',
            'delivery_postcode' => '54321',
            'delivery_city' => 'Θεσσαλονίκη',
            'recipient_name' => 'Παραλήπτης ΑΕ',
            'recipient_afm' => '123456789',
            'local_status' => 'draft',
        ]);

        $note->lines()->create([
            'company_id' => $this->tenant->id,
            'qty' => 3,
            'measurement_unit' => 1,
            'product_descr' => 'Κιβώτια',
        ]);

        return $note->fresh('lines');
    }

    private function successResponseXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">
    <response>
        <index>1</index>
        <invoiceUid>6F825B9B74717280D1F9A38252D0B65604C6D162</invoiceUid>
        <invoiceMark>480301204040191</invoiceMark>
        <qrUrl>https://mydataapidev.aade.gr/TimologioQR/QRInfo?q=testqr</qrUrl>
        <statusCode>Success</statusCode>
    </response>
</ResponseDoc>
XML;
    }
}
