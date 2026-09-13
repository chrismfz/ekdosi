<?php

namespace Tests\Feature\Delivery;

use App\Filament\Resources\InboundDeliveryNotes\Pages\ListInboundDeliveryNotes;
use App\Filament\Resources\InboundDeliveryNotes\Pages\ViewInboundDeliveryNote;
use App\Models\Company;
use App\Models\InboundDeliveryNote;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Slice 4b — «Εισερχόμενα Διακίνησης» resource: the list renders staged rows and
 * the view surfaces the recipient actions gated by state. AADE-touching actions
 * (reject/refresh) are asserted for VISIBILITY only — driving them through the
 * page would hit real AADE (the page resolves the service without a mock);
 * their behaviour is covered by InboundDeliveryServiceTest. The local-only
 * «Παραλήφθηκε» IS driven end-to-end (no network).
 */
class InboundDeliveryNoteResourceTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Company::create([
            'name' => 'Inbound res', 'slug' => 'inbound-res-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'afm' => '801280908',
        ]);

        // Bypass Shield gates (only real after shield:generate) — the proven
        // Filament-test idiom (mirrors DeliveryNoteResourceTest).
        Gate::before(fn () => true);
        $this->actingAs(User::factory()->create());
        Filament::setTenant($this->tenant);
    }

    private function row(array $overrides = []): InboundDeliveryNote
    {
        return InboundDeliveryNote::create(array_merge([
            'company_id' => $this->tenant->id,
            'mydata_mark' => '480301204040191',
            'issuer_afm' => '111111111', 'issuer_name' => 'ΜΕΤΑΦΟΡΙΚΗ ΑΕ',
            'invoice_type' => '9.3', 'local_state' => InboundDeliveryNote::STATE_NEW,
            'payload' => [],
        ], $overrides));
    }

    public function test_list_renders_and_shows_staged_rows(): void
    {
        $row = $this->row();

        Livewire::test(ListInboundDeliveryNotes::class)
            ->assertSuccessful()
            ->assertActionVisible('fetch_inbound')
            ->assertCanSeeTableRecords([$row]);
    }

    public function test_view_shows_recipient_actions_for_a_new_row(): void
    {
        $row = $this->row();

        Livewire::test(ViewInboundDeliveryNote::class, ['record' => $row->getKey()])
            ->assertSuccessful()
            ->assertActionVisible('reject')
            ->assertActionVisible('refresh_status')
            ->assertActionVisible('acknowledge');
    }

    public function test_view_hides_reject_and_acknowledge_on_a_terminal_row(): void
    {
        $row = $this->row(['local_state' => InboundDeliveryNote::STATE_REJECTED]);

        Livewire::test(ViewInboundDeliveryNote::class, ['record' => $row->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('reject')
            ->assertActionHidden('acknowledge')
            // Refresh stays available — the operator can still re-check AADE.
            ->assertActionVisible('refresh_status');
    }

    public function test_acknowledge_action_is_driven_end_to_end(): void
    {
        $row = $this->row();

        Livewire::test(ViewInboundDeliveryNote::class, ['record' => $row->getKey()])
            ->callAction('acknowledge');

        $this->assertSame(InboundDeliveryNote::STATE_ACKNOWLEDGED, $row->fresh()->local_state);
    }
}
