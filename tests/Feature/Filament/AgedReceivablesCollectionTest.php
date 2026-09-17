<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AgedReceivables;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Accounting\AgedReceivablesReport;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * #5 dunning Φάση A: the «Εργασία είσπραξης» row action on «Ηλικίωση οφειλών»
 * records the per-customer collection state, and the report surfaces it. Record-
 * keeping only — no notifications/escalation.
 */
class AgedReceivablesCollectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $debtor;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

        $this->tenant = Company::create([
            'name' => 'AR', 'slug' => 'ar-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $type = InvoiceType::create([
            'company_id' => $this->tenant->id, 'code' => 'ΤΠΥ', 'name' => 'ΤΠΥ', 'invcount' => 1,
        ]);
        $creditTerm = PaymentMethod::create([
            'company_id' => $this->tenant->id, 'description' => 'Επί πιστώσει', 'due_days' => 30,
        ]);
        $this->debtor = Customer::create(['company_id' => $this->tenant->id, 'name' => 'ΒΑΚΑΣ ΑΕ']);
        Invoice::create([
            'company_id' => $this->tenant->id, 'customer_id' => $this->debtor->id,
            'invoice_type_id' => $type->id, 'payment_method_id' => $creditTerm->id,
            'invcode' => 'ΤΠΥ1', 'code' => 1, 'issued_at' => '2026-03-01 10:00:00',
            'net_total' => 124, 'gross_total' => 124, 'payable_total' => 124, 'local_status' => 'active',
        ]);

        $this->operator = User::create(['name' => 'Εισπράκτορας', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->operator->id);

        Gate::before(fn () => true);
        $this->actingAs($this->operator);
        Filament::setTenant($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_collection_action_records_state_and_stamps_contact(): void
    {
        Livewire::test(AgedReceivables::class)
            ->callAction('collection', data: [
                'collection_assigned_to' => $this->operator->id,
                'collection_next_step_at' => '2026-06-20',
                'collection_next_step_note' => 'Τηλέφωνο για διακανονισμό',
                'collection_note' => 'Ζήτησε δόσεις',
                'log_contact_today' => true,
            ], arguments: ['customer' => $this->debtor->id])
            ->assertHasNoActionErrors();

        $this->debtor->refresh();
        $this->assertSame($this->operator->id, $this->debtor->collection_assigned_to);
        $this->assertSame('2026-06-20', $this->debtor->collection_next_step_at->format('Y-m-d'));
        $this->assertSame('Τηλέφωνο για διακανονισμό', $this->debtor->collection_next_step_note);
        $this->assertSame('Ζήτησε δόσεις', $this->debtor->collection_note);
        $this->assertSame('2026-06-15', $this->debtor->collection_last_contact_at->format('Y-m-d')); // stamped today
    }

    public function test_contact_not_stamped_when_toggle_off(): void
    {
        Livewire::test(AgedReceivables::class)
            ->callAction('collection', data: [
                'collection_next_step_note' => 'Email υπενθύμιση',
                'log_contact_today' => false,
            ], arguments: ['customer' => $this->debtor->id]);

        $this->debtor->refresh();
        $this->assertNull($this->debtor->collection_last_contact_at);
        $this->assertSame('Email υπενθύμιση', $this->debtor->collection_next_step_note);
    }

    public function test_report_row_surfaces_the_collection_state(): void
    {
        $this->debtor->forceFill([
            'collection_assigned_to' => $this->operator->id,
            'collection_next_step_at' => '2026-06-20',
            'collection_last_contact_at' => '2026-06-10',
        ])->save();

        $row = app(AgedReceivablesReport::class)->build($this->tenant)->rows[0];

        $this->assertSame('Εισπράκτορας', $row->collectionAssignee);
        $this->assertSame('20/06/2026', $row->collectionNextStepAt);
        $this->assertSame('10/06/2026', $row->collectionLastContactAt);
    }

    public function test_foreign_tenant_assignee_id_is_rejected(): void
    {
        // A user NOT in this tenant (no company_user pivot row). Assigning the debt
        // to them must be blocked so their display name can't leak into the
        // «Ανάθεση» column. Two layers: the Select validates against its tenant-only
        // options (this error) AND the action re-checks membership at the write.
        $outsider = User::create(['name' => 'Ξένος Χρήστης', 'email' => 'x-'.uniqid().'@t.local', 'password' => bcrypt('x')]);

        Livewire::test(AgedReceivables::class)
            ->callAction('collection', data: [
                'collection_assigned_to' => $outsider->id,
            ], arguments: ['customer' => $this->debtor->id])
            ->assertHasActionErrors(['collection_assigned_to']);

        $this->debtor->refresh();
        $this->assertNull($this->debtor->collection_assigned_to, 'a non-tenant user must not be assignable');
    }

    public function test_action_cannot_write_another_tenants_customer(): void
    {
        $other = Company::create([
            'name' => 'Other', 'slug' => 'other-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
        $victim = Customer::create(['company_id' => $other->id, 'name' => 'Ξένος']);

        Livewire::test(AgedReceivables::class)
            ->callAction('collection', data: [
                'collection_note' => 'δεν πρέπει να γραφτεί',
            ], arguments: ['customer' => $victim->id]);

        $victim->refresh();
        $this->assertNull($victim->collection_note, 'a foreign-tenant customer must not be touched');
    }
}
