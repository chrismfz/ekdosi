<?php

namespace Tests\Feature\Invoice;

use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A πρόχειρο («ΠΡΟΣ-…») is a sandbox: it never consumed a ΑΑ, so the operator can
 * delete it — from the VIEW page too (where they land), not only from Edit. And
 * deleting a WHMCS-inbox draft hands the WHMCS invoice back to the inbox instead
 * of leaving its row stuck at «Προσχέδιο» pointing at a trashed document.
 */
class DeleteDraftTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private InvoiceType $type;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(fn () => true);
        $this->company = Company::create([
            'name' => 'Del OE', 'slug' => 'del-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name' => 'C', 'afm' => '997890734']);
        $this->type = InvoiceType::create(['company_id' => $this->company->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $user = User::create(['name' => 'U', 'email' => 'u-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->company->id);
        $this->actingAs($user);
        Filament::setTenant($this->company);
    }

    private function invoice(array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'company_id' => $this->company->id, 'invoice_type_id' => $this->type->id,
            'customer_id' => $this->customer->id, 'issued_at' => now(), 'local_status' => 'draft',
        ], $attrs));
    }

    private function pending(string $status, ?int $invoiceId = null): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->company->id, 'whmcs_invoice_id' => random_int(700000, 799999),
            'payload' => ['total' => 10], 'status' => $status,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM, 'invoice_id' => $invoiceId,
        ]);
    }

    public function test_a_draft_can_be_deleted_from_the_view_page(): void
    {
        $draft = $this->invoice();

        Livewire::test(ViewInvoice::class, ['record' => $draft->getRouteKey()])
            ->assertActionVisible('delete_draft')
            ->callAction('delete_draft');

        $this->assertSoftDeleted($draft);
    }

    public function test_delete_is_hidden_on_an_issued_or_offered_document(): void
    {
        $active = $this->invoice(['local_status' => 'active', 'code' => 7, 'invcode' => 'TPY7']);
        Livewire::test(ViewInvoice::class, ['record' => $active->getRouteKey()])
            ->assertActionHidden('delete_draft');

        $offered = $this->invoice(['offered_at' => now()]);
        $this->assertFalse($offered->isDeletableDraft());
    }

    public function test_a_draft_cancelled_before_numbering_can_be_deleted(): void
    {
        $cancelled = $this->invoice(['local_status' => 'cancelled']);
        $this->assertNull($cancelled->code);

        Livewire::test(ViewInvoice::class, ['record' => $cancelled->getRouteKey()])
            ->assertActionVisible('delete_draft')
            ->callAction('delete_draft');

        $this->assertSoftDeleted($cancelled);
    }

    public function test_a_cancelled_document_that_got_a_number_is_never_deletable(): void
    {
        $numbered = $this->invoice(['local_status' => 'cancelled', 'code' => 8, 'invcode' => 'TPY8']);
        $this->assertFalse($numbered->isDeletableDraft(), 'a ΑΑ was consumed → deleting leaves a gap');

        Livewire::test(ViewInvoice::class, ['record' => $numbered->getRouteKey()])
            ->assertActionHidden('delete_draft');

        $marked = $this->invoice(['local_status' => 'cancelled']);
        $marked->forceFill(['mydata_mark' => '400001234567890'])->save();
        $this->assertFalse($marked->isDeletableDraft(), 'anything AADE knows about stays');

        $inDoubt = $this->invoice();
        $inDoubt->forceFill(['mydata_pending_since' => now()])->save();
        $this->assertFalse($inDoubt->isDeletableDraft(), 'an in-doubt submission may exist at AADE');
    }

    public function test_deleting_an_inbox_draft_returns_the_row_to_review(): void
    {
        $pending = $this->pending(PendingWhmcsInvoice::STATUS_DRAFTED);
        $draft = $this->invoice(['whmcs_pending_id' => $pending->id]);
        $pending->update(['invoice_id' => $draft->id]);

        $draft->delete();

        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->status);
        $this->assertNull($pending->invoice_id);
    }

    public function test_a_split_row_keeps_its_state_while_another_part_survives(): void
    {
        $pending = $this->pending(PendingWhmcsInvoice::STATUS_SPLIT);
        $a = $this->invoice(['whmcs_pending_id' => $pending->id]);
        $b = $this->invoice(['whmcs_pending_id' => $pending->id]);

        $a->delete();
        $this->assertSame(PendingWhmcsInvoice::STATUS_SPLIT, $pending->fresh()->status);

        $b->delete();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_restoring_a_deleted_inbox_draft_reclaims_its_row(): void
    {
        $pending = $this->pending(PendingWhmcsInvoice::STATUS_DRAFTED);
        $pending->update(['notes' => 'αρχική σημείωση']);
        $draft = $this->invoice(['whmcs_pending_id' => $pending->id]);
        $pending->update(['invoice_id' => $draft->id]);

        $draft->delete();
        $this->assertStringContainsString('αρχική σημείωση', (string) $pending->fresh()->notes, 'release appends, never replaces');

        $draft->restore();
        $pending->refresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_DRAFTED, $pending->status);
        $this->assertSame($draft->id, $pending->invoice_id);
    }
}
