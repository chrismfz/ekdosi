<?php

namespace Tests\Feature\Assistant;

use App\Livewire\AssistantWidget;
use App\Mail\CustomerStatementMail;
use App\Models\AiPendingAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Assistant\AiActionExecutor;
use App\Services\Assistant\ToolRegistry;
use App\Services\Assistant\Tools\CreateReminderTool;
use App\Services\Assistant\Tools\RecordPaymentTool;
use App\Services\Assistant\Tools\SendCustomerStatementTool;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class AssistantWriteActionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ekdosi.ai.enabled' => true, 'services.anthropic.key' => 'sk-test']);
        $this->tenant = Company::create([
            'name' => 'Write OE', 'slug' => 'wr-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'ai_assistant_enabled' => true,
        ]);
        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    private function customer(string $name, ?string $email = 'c@example.com'): Customer
    {
        return Customer::create([
            'company_id' => $this->tenant->id, 'name' => $name,
            'afm' => (string) random_int(100000000, 999999999), 'email' => $email,
        ]);
    }

    public function test_send_statement_tool_stages_a_pending_action_without_sending(): void
    {
        Mail::fake();
        $c = $this->customer('Παπαδόπουλος ΑΕ');

        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Παπαδ']);

        $this->assertTrue($res['proposed']);
        $this->assertContains('c@example.com', $res['recipients']);
        Mail::assertNothingSent(); // PREPARED, not sent.

        $action = AiPendingAction::find($res['action_id']);
        $this->assertSame(AiPendingAction::TYPE_SEND_STATEMENT, $action->type);
        $this->assertSame(AiPendingAction::STATUS_PENDING, $action->status);
        $this->assertSame($c->id, $action->customer_id);
        $this->assertSame(['c@example.com'], $action->payload['recipients']);
    }

    public function test_send_statement_includes_contact_emails(): void
    {
        $c = $this->customer('Με Επαφές');
        CustomerContact::create([
            'company_id' => $this->tenant->id, 'customer_id' => $c->id,
            'name' => 'Λογιστήριο', 'email' => 'acc@example.com',
        ]);

        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Επαφές']);

        $this->assertEqualsCanonicalizing(['c@example.com', 'acc@example.com'], $res['recipients']);
    }

    public function test_send_statement_refuses_when_customer_has_no_email(): void
    {
        $this->customer('Χωρίς Email', email: null);

        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Χωρίς']);

        $this->assertArrayHasKey('error', $res);
        $this->assertArrayNotHasKey('action_id', $res);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_send_statement_is_ambiguous_for_multiple_matches(): void
    {
        $this->customer('Αλφα ΑΕ');
        $this->customer('Αλφα ΟΕ');

        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Αλφα']);

        $this->assertTrue($res['ambiguous']);
        $this->assertSame(0, AiPendingAction::count());
    }

    public function test_confirm_send_statement_sends_the_mail(): void
    {
        Gate::before(fn () => true); // operator may View:Customer
        Mail::fake();
        $c = $this->customer('Σταλμένος');
        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Σταλμ']);
        $action = AiPendingAction::find($res['action_id']);

        $out = app(AiActionExecutor::class)->confirm($action, $this->user);

        Mail::assertSent(CustomerStatementMail::class);
        $this->assertStringContainsString('Στάλθηκε', $out);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
    }

    public function test_create_reminder_tool_stages_pending_then_confirm_arms_it(): void
    {
        $res = (new CreateReminderTool)->run($this->tenant, ['note' => 'Πάρε τηλέφωνο', 'remind_at' => '2099-01-01 09:00']);

        $this->assertTrue($res['proposed']);
        $action = AiPendingAction::find($res['action_id']);
        $this->assertSame(AiPendingAction::TYPE_REMINDER, $action->type);
        $this->assertSame(AiPendingAction::STATUS_PENDING, $action->status);
        $this->assertNull($action->delivered_at);

        NotificationFacade::fake();
        app(AiActionExecutor::class)->confirm($action, $this->user);

        // Future reminder → confirmed but NOT yet delivered.
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
        $this->assertNull($action->fresh()->delivered_at);
    }

    public function test_confirm_reminder_in_the_past_delivers_immediately(): void
    {
        $res = (new CreateReminderTool)->run($this->tenant, ['note' => 'Άμεσο']);
        $action = AiPendingAction::find($res['action_id']);
        // Force it due.
        $action->forceFill(['remind_at' => now()->subMinute()])->save();

        app(AiActionExecutor::class)->confirm($action, $this->user);

        $this->assertNotNull($action->fresh()->delivered_at);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->user->id]);
    }

    public function test_dispatch_reminders_command_delivers_due_confirmed_reminders(): void
    {
        // Due, confirmed, undelivered → delivered.
        $due = AiPendingAction::create([
            'company_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'type' => AiPendingAction::TYPE_REMINDER, 'status' => AiPendingAction::STATUS_CONFIRMED,
            'summary' => 'Ώριμη', 'payload' => ['note' => 'Ώριμη'], 'remind_at' => now()->subMinute(),
        ]);
        // Future, confirmed → left alone.
        $future = AiPendingAction::create([
            'company_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'type' => AiPendingAction::TYPE_REMINDER, 'status' => AiPendingAction::STATUS_CONFIRMED,
            'summary' => 'Μέλλον', 'payload' => ['note' => 'Μέλλον'], 'remind_at' => now()->addDay(),
        ]);

        $this->artisan('ai:dispatch-reminders')->assertSuccessful();

        $this->assertNotNull($due->fresh()->delivered_at);
        $this->assertNull($future->fresh()->delivered_at);
    }

    public function test_cancel_marks_cancelled_and_runs_no_side_effect(): void
    {
        Mail::fake();
        $this->customer('Ακυρωμένος');
        $res = (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Ακυρ']);
        $action = AiPendingAction::find($res['action_id']);

        app(AiActionExecutor::class)->cancel($action);

        $this->assertSame(AiPendingAction::STATUS_CANCELLED, $action->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_registry_exposes_the_two_write_tools(): void
    {
        Gate::before(fn () => true); // operator has the read permissions
        $names = array_column((new ToolRegistry)->definitionsFor($this->user), 'name');
        $this->assertContains('send_customer_statement', $names);
        $this->assertContains('create_reminder', $names);
        $this->assertContains('record_payment', $names);
        $this->assertCount(16, $names);
    }

    public function test_widget_confirm_flow_executes_the_action(): void
    {
        Gate::before(fn () => true);
        Mail::fake();
        $this->customer('Από Widget');
        $action = AiPendingAction::find(
            (new SendCustomerStatementTool)->run($this->tenant, ['customer' => 'Widget'])['action_id'],
        );

        Livewire::test(AssistantWidget::class)
            ->set('open', true)
            ->assertSeeText($action->summary)              // pending card surfaced
            ->call('confirmAssistantAction', $action->id)
            ->assertSeeText('Στάλθηκε');                    // result in transcript

        Mail::assertSent(CustomerStatementMail::class);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
    }

    public function test_operator_cannot_confirm_another_users_action(): void
    {
        Gate::before(fn () => true);
        Mail::fake();
        $this->customer('Ξένος');
        // Stage an action owned by a DIFFERENT user in the same tenant.
        $other = User::create(['name' => 'Other', 'email' => 'o-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $action = AiPendingAction::create([
            'company_id' => $this->tenant->id, 'user_id' => $other->id,
            'type' => AiPendingAction::TYPE_REMINDER, 'status' => AiPendingAction::STATUS_PENDING,
            'summary' => 'Ξένη υπενθύμιση', 'payload' => ['note' => 'x'], 'remind_at' => now()->addDay(),
        ]);

        // The current operator's confirm must be a no-op (scoped to user+tenant).
        Livewire::test(AssistantWidget::class)
            ->set('open', true)
            ->assertDontSeeText('Ξένη υπενθύμιση')
            ->call('confirmAssistantAction', $action->id);

        $this->assertSame(AiPendingAction::STATUS_PENDING, $action->fresh()->status);
    }

    public function test_send_statement_tool_hidden_without_customer_permission(): void
    {
        // No Gate::before allow-all here → 'View:Customer' resolves to denied.
        $names = array_column((new ToolRegistry)->definitionsFor($this->user), 'name');

        $this->assertNotContains('send_customer_statement', $names);
        // Reminder has no permission gate → still offered.
        $this->assertContains('create_reminder', $names);
    }

    /** An active, credit-term invoice with an open balance for allocation tests. */
    private function creditInvoice(Customer $c, float $gross): Invoice
    {
        $method = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Πίστωση', 'due_days' => 30]);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1, 'mydata_type' => '2.1']);

        return Invoice::create([
            'company_id' => $this->tenant->id, 'invcode' => 'I'.uniqid(), 'code' => random_int(1, 99999),
            'invoice_type_id' => $type->id, 'payment_method_id' => $method->id, 'customer_id' => $c->id,
            'issued_at' => now()->subDay(), 'local_status' => 'active',
            'net_total' => round($gross / 1.24, 2), 'gross_total' => $gross, 'header_discount_percent' => 0,
        ]);
    }

    public function test_record_payment_stages_then_confirm_allocates_a_real_payment(): void
    {
        Gate::before(fn () => true); // operator has Create:Payment
        $c = $this->customer('Οφειλέτης ΑΕ', email: null);
        $inv = $this->creditInvoice($c, 100);

        // Stage — nothing recorded yet.
        $res = (new RecordPaymentTool)->run($this->tenant, ['customer' => 'Οφειλέτης', 'amount' => 100]);
        $this->assertTrue($res['proposed']);
        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());

        $action = AiPendingAction::find($res['action_id']);
        $this->assertSame(AiPendingAction::TYPE_RECORD_PAYMENT, $action->type);
        $this->assertSame($c->id, $action->customer_id);
        $this->assertEqualsWithDelta(100.0, (float) $action->payload['amount'], 0.01);

        // Confirm — the Payment is created and allocated onto the open invoice.
        $line = app(AiActionExecutor::class)->confirm($action, $this->user);

        $this->assertStringContainsString('Καταχωρήθηκε είσπραξη', $line);
        $this->assertStringContainsString((string) $inv->invcode, $line);
        $this->assertSame(AiPendingAction::STATUS_CONFIRMED, $action->fresh()->status);
        $this->assertEqualsWithDelta(100.0, (float) Payment::where('invoice_id', $inv->id)->sum('amount'), 0.01);
        $this->assertEqualsWithDelta(0.0, (float) $inv->fresh()->balanceData()->balance, 0.01);

        // Audit back-reference: the confirmed action stores the allocation reference,
        // which every created Payment carries.
        $ref = $action->fresh()->payload['payment_reference'] ?? null;
        $this->assertNotNull($ref);
        $this->assertSame(1, Payment::where('reference', $ref)->count());

        // Double-confirm must NOT create a second payment (atomic claim).
        $again = app(AiActionExecutor::class)->confirm($action->fresh(), $this->user);
        $this->assertSame('Η ενέργεια δεν εκκρεμεί πλέον.', $again);
        $this->assertEqualsWithDelta(100.0, (float) Payment::where('invoice_id', $inv->id)->sum('amount'), 0.01);
    }

    public function test_record_payment_reads_a_greek_ddmmyyyy_date_unambiguously(): void
    {
        Gate::before(fn () => true);
        $this->customer('Ημερομηνία', email: null);

        // 06/09/2026 = 6 Sep (Greek), NOT 9 Jun (US) — Carbon::parse would misread it.
        $res = (new RecordPaymentTool)->run($this->tenant, ['customer' => 'Ημερομηνία', 'amount' => 10, 'date' => '06/09/2026']);

        $this->assertSame('2026-09-06', $res['date']);
    }

    public function test_record_payment_refuses_an_ambiguous_customer(): void
    {
        Gate::before(fn () => true);
        $this->customer('Παπαδόπουλος Α', email: null);
        $this->customer('Παπαδόπουλος Β', email: null);

        $res = (new RecordPaymentTool)->run($this->tenant, ['customer' => 'Παπαδόπουλος', 'amount' => 50]);

        $this->assertArrayHasKey('error', $res);
        $this->assertSame(0, AiPendingAction::where('company_id', $this->tenant->id)->count());
    }

    public function test_record_payment_confirm_rechecks_the_money_permission(): void
    {
        // NO Gate::before allow-all — so the real policy denies Create:Payment to a
        // roleless stranger. Staging bypasses the gate (we call run() directly);
        // the confirm re-check is what must refuse.
        $c = $this->customer('Οφειλέτης', email: null);
        $res = (new RecordPaymentTool)->run($this->tenant, ['customer' => 'Οφειλέτης', 'amount' => 20]);
        $action = AiPendingAction::find($res['action_id']);

        // A user WITHOUT Create:Payment must be refused at confirm (defence in depth).
        $stranger = User::create(['name' => 'NoPerm', 'email' => 'np-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $line = app(AiActionExecutor::class)->confirm($action, $stranger);

        $this->assertSame('Δεν έχετε πρόσβαση.', $line);
        $this->assertSame(AiPendingAction::STATUS_FAILED, $action->fresh()->status);
        $this->assertSame(0, Payment::where('company_id', $this->tenant->id)->count());
    }
}
