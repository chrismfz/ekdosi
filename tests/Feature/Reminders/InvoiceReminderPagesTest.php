<?php

namespace Tests\Feature\Reminders;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\InvoiceReminders\InvoiceReminderResource;
use App\Filament\Resources\InvoiceReminders\Pages\ListInvoiceReminders;
use App\Mail\InvoiceReminderMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReminder;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\RecomputeInvoiceTotals;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/** The «Υπενθυμίσεις» page and the settings section, end to end. */
class InvoiceReminderPagesTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Gate::before(fn () => true);

        $this->tenant = $this->company('Εταιρεία Α');
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
    }

    private function company(string $name): Company
    {
        return Company::create([
            'name' => $name, 'slug' => 'c-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none',
            'reminders_enabled' => true, 'reminders_mode' => 'review', 'reminders_since' => '2020-01-01',
            'reminder_first_days' => 3, 'reminder_attach_pdf' => false,
        ]);
    }

    private function awaitingReminder(Company $company): InvoiceReminder
    {
        $type = InvoiceType::create(['company_id' => $company->id, 'code' => 'T'.uniqid(), 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $pm = PaymentMethod::create(['company_id' => $company->id, 'description' => '30', 'due_days' => 30]);
        $customer = Customer::create(['company_id' => $company->id, 'name' => 'Πελάτης', 'email' => 'c@example.gr']);
        $inv = Invoice::create([
            'company_id' => $company->id, 'invoice_type_id' => $type->id, 'customer_id' => $customer->id,
            'payment_method_id' => $pm->id, 'code' => 1, 'invcode' => 'ΤΠΥ'.uniqid(), 'issued_at' => now()->subDays(40), 'local_status' => 'active',
        ]);
        InvoiceLine::create(['company_id' => $company->id, 'invoice_id' => $inv->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($inv);

        return InvoiceReminder::create([
            'company_id' => $company->id, 'invoice_id' => $inv->id, 'customer_id' => $customer->id,
            'stage' => 'first', 'auto_stage' => 'first', 'document_kind' => 'invoice',
            'due_date' => now()->subDays(10)->toDateString(), 'days_overdue' => 10, 'balance' => 124,
            'status' => InvoiceReminder::STATUS_AWAITING, 'trigger' => 'auto',
        ]);
    }

    public function test_the_page_lists_only_this_tenants_reminders_and_opens_on_the_awaiting_tab(): void
    {
        $mine = $this->awaitingReminder($this->tenant);
        $theirs = $this->awaitingReminder($this->company('Εταιρεία Β'));

        Livewire::test(ListInvoiceReminders::class)
            ->assertSet('activeTab', 'awaiting')
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs]);
    }

    public function test_send_and_skip(): void
    {
        $send = $this->awaitingReminder($this->tenant);
        $skip = $this->awaitingReminder($this->tenant);   // another document

        Livewire::test(ListInvoiceReminders::class)
            ->callTableAction('send', $send)
            ->callTableAction('skip', $skip)
            ->assertHasNoTableActionErrors();

        $this->assertSame(InvoiceReminder::STATUS_SENT, $send->fresh()->status);
        Mail::assertSent(InvoiceReminderMail::class, 1);
        $this->assertSame(InvoiceReminder::STATUS_SKIPPED, $skip->fresh()->status);
        $this->assertStringContainsString('Op', $skip->fresh()->reason);
    }

    public function test_a_sent_reminder_cannot_be_queued_again(): void
    {
        $row = $this->awaitingReminder($this->tenant);
        $row->forceFill(['status' => InvoiceReminder::STATUS_SENT])->save();

        $this->assertFalse(InvoiceReminderResource::queue($row));
        $this->assertSame(InvoiceReminder::STATUS_SENT, $row->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_run_now_records_todays_reminders(): void
    {
        $row = $this->awaitingReminder($this->tenant);
        $row->delete();   // leave only the unpaid invoice behind

        Livewire::test(ListInvoiceReminders::class)
            ->callAction('run_now')
            ->assertNotified();

        $this->assertSame(1, InvoiceReminder::where('company_id', $this->tenant->id)->count());
    }

    public function test_settings_save_the_reminder_knobs_and_templates(): void
    {
        Livewire::test(CompanySettings::class)
            ->set('data.reminders_mode', 'auto')
            ->set('data.reminder_first_days', 5)
            ->set('data.reminder_final_days', null)
            ->set('data.reminder_templates.first.subject', 'Οφειλή {invoice_code}')
            ->call('save')
            ->assertHasNoErrors();

        $company = $this->tenant->fresh();
        $this->assertSame('auto', $company->reminders_mode);
        $this->assertSame(5, $company->reminder_first_days);
        $this->assertNull($company->reminder_final_days);
        $this->assertSame('Οφειλή {invoice_code}', $company->reminder_templates['first']['subject']);
    }

    public function test_the_after_due_stages_must_escalate(): void
    {
        Livewire::test(CompanySettings::class)
            ->set('data.reminder_first_days', 5)
            ->set('data.reminder_second_days', 5)
            ->set('data.reminder_final_days', 2)
            ->call('save')
            ->assertHasErrors(['data.reminder_second_days', 'data.reminder_final_days']);

        Livewire::test(CompanySettings::class)
            ->set('data.reminder_first_days', 5)
            ->set('data.reminder_second_days', null)   // a switched-off stage is skipped over
            ->set('data.reminder_final_days', 6)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(6, $this->tenant->fresh()->reminder_final_days);
    }
}
