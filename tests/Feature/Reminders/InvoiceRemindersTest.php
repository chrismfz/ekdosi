<?php

namespace Tests\Feature\Reminders;

use App\Mail\InvoiceReminderMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReminder;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Reminders\ReminderMessage;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderRunner;
use App\Services\Reminders\ReminderSender;
use App\Services\Reminders\ReminderSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvoiceRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $credit30;

    private PaymentMethod $cash;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Company::create([
            'name' => 'Rem ΑΕ', 'slug' => 'rem-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
            'reminders_enabled' => true, 'reminders_mode' => 'review', 'reminders_since' => '2020-01-01',
            'reminder_pre_due_days' => 3, 'reminder_first_days' => 3, 'reminder_second_days' => 10, 'reminder_final_days' => 20,
            'reminder_min_balance' => 1, 'reminder_attach_pdf' => false,
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $this->credit30 = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $this->cash = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => 'Μετρητά', 'due_days' => 0]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Πελάτης Α', 'email' => 'pelatis@example.gr']);
    }

    /** A €124 document: issued (or offered) $daysAgo days ago. */
    private function invoice(int $daysAgo, ?PaymentMethod $pm = null, string $status = 'active', bool $offered = false, array $extra = []): Invoice
    {
        static $n = 0;
        $n++;
        $inv = Invoice::create([
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id, 'customer_id' => $this->customer->id,
            'payment_method_id' => ($pm ?? $this->credit30)->id, 'code' => $n, 'invcode' => 'TPY'.$n,
            'issued_at' => now()->subDays($daysAgo), 'local_status' => $status,
        ] + $extra);
        if ($offered) {
            $inv->forceFill(['offered_at' => now()->subDays($daysAgo)])->save();
        }
        InvoiceLine::create(['company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($inv);

        return $inv->fresh();
    }

    private function plannedStages(): array
    {
        return collect(app(ReminderPlanner::class)->plan($this->tenant->fresh(), CarbonImmutable::today()))
            ->mapWithKeys(fn (array $p) => [$p['invoice']->invcode => $p['stage']])
            ->all();
    }

    /* ───────────── stages ───────────── */

    public function test_stage_selection_is_whmcs_like_and_catches_up_with_one_reminder(): void
    {
        $stages = ['pre_due' => -3, 'first' => 3, 'second' => 10, 'final' => 20];

        $this->assertSame('pre_due', ReminderPlanner::stageFor($stages, -2, []));
        $this->assertNull(ReminderPlanner::stageFor($stages, -5, []), 'too early');
        $this->assertNull(ReminderPlanner::stageFor($stages, 1, []), 'between due and the 1st: nothing (pre-due is before due only)');
        $this->assertSame('first', ReminderPlanner::stageFor($stages, 3, ['pre_due']));
        $this->assertNull(ReminderPlanner::stageFor($stages, 5, ['first']), 'already had the 1st');
        $this->assertSame('final', ReminderPlanner::stageFor($stages, 25, []), 'after downtime: only the highest reached, not all three');
        $this->assertNull(ReminderPlanner::stageFor($stages, 12, ['final']), 'never step back to the 2nd');
    }

    public function test_only_our_unpaid_credit_term_invoices_and_offered_proformas_are_planned(): void
    {
        $due = $this->invoice(34);                                    // due 4 days ago → 1st
        $this->invoice(34, $this->cash);                               // cash terms — settled at issue
        $this->invoice(34, extra: ['legacy_id' => 900]);              // ETL import
        $whmcs = $this->invoice(34);
        $whmcs->forceFill(['whmcs_invoice_id' => 555])->save();        // WHMCS runs its own reminders
        $this->invoice(34, status: 'cancelled');
        $proforma = $this->invoice(5, $this->cash, 'draft', offered: true);   // due at offer → 1st (5 ≥ 3)
        $this->invoice(5, $this->cash, 'draft');                       // plain draft — the customer never saw it
        $paid = $this->invoice(34);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $paid->id,
            'kind' => 'payment', 'amount' => 124, 'pay_date' => now()]);

        $this->assertSame([$due->invcode => 'first', $proforma->invcode => 'first'], $this->plannedStages());
    }

    public function test_opted_out_customers_missing_emails_and_old_debts_are_left_alone(): void
    {
        $this->invoice(34);
        $this->customer->update(['reminders_enabled' => false]);
        $this->assertSame([], $this->plannedStages());

        $this->customer->update(['reminders_enabled' => true, 'email' => null]);
        $this->assertSame([], $this->plannedStages());

        $this->customer->update(['email' => 'pelatis@example.gr']);
        $this->tenant->update(['reminders_since' => now()->toDateString()]);   // switched on today
        $this->assertSame([], $this->plannedStages(), 'an invoice due before «reminders_since» never gets one');
    }

    /* ───────────── the daily run ───────────── */

    public function test_review_mode_records_awaiting_rows_once_and_rings_the_bell(): void
    {
        Gate::before(fn () => true);
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $inv = $this->invoice(34);

        $first = app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());
        $again = app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $again['created'], 'the same stage is never recorded twice');
        $row = InvoiceReminder::where('invoice_id', $inv->id)->sole();
        $this->assertSame(InvoiceReminder::STATUS_AWAITING, $row->status);
        $this->assertSame('first', $row->auto_stage);
        $this->assertSame('124.00', (string) $row->balance);
        $this->assertSame(1, $user->notifications()->count());
        Mail::assertNothingSent();
    }

    public function test_auto_mode_sends_straight_away(): void
    {
        $this->tenant->update(['reminders_mode' => 'auto']);
        $inv = $this->invoice(34);

        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $row = InvoiceReminder::where('invoice_id', $inv->id)->sole();
        $this->assertSame(InvoiceReminder::STATUS_SENT, $row->status);
        $this->assertSame('pelatis@example.gr', $row->recipient);
        $this->assertNotNull($row->sent_at);
        Mail::assertSent(InvoiceReminderMail::class, fn (InvoiceReminderMail $m) => $m->hasTo('pelatis@example.gr')
            && str_contains($m->subjectLine, $inv->invcode));
        $this->assertSame(now()->toDateString(), $this->customer->fresh()->collection_last_contact_at?->toDateString());
    }

    public function test_a_reminder_for_a_document_paid_in_the_meantime_is_cancelled_not_sent(): void
    {
        $inv = $this->invoice(34);
        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());
        $row = InvoiceReminder::where('invoice_id', $inv->id)->sole();

        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => $inv->id,
            'kind' => 'payment', 'amount' => 124, 'pay_date' => now()]);
        app(ReminderSender::class)->send($row->id);

        $row->refresh();
        $this->assertSame(InvoiceReminder::STATUS_CANCELLED, $row->status);
        $this->assertSame('Εξοφλήθηκε.', $row->reason);
        Mail::assertNothingSent();
    }

    public function test_a_reminder_is_claimed_so_it_can_never_go_out_twice(): void
    {
        $inv = $this->invoice(34);
        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());
        $row = InvoiceReminder::where('invoice_id', $inv->id)->sole();

        app(ReminderSender::class)->send($row->id);
        app(ReminderSender::class)->send($row->id);

        Mail::assertSentCount(1);
        $this->assertSame(InvoiceReminder::STATUS_SENT, $row->fresh()->status);
    }

    public function test_the_daily_run_cancels_waiting_reminders_that_no_longer_qualify(): void
    {
        $inv = $this->invoice(34);
        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());
        $this->customer->update(['reminders_enabled' => false]);

        $r = app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $this->assertSame(1, $r['cancelled']);
        $this->assertSame(InvoiceReminder::STATUS_CANCELLED, InvoiceReminder::where('invoice_id', $inv->id)->sole()->status);
    }

    public function test_the_command_skips_tenants_without_reminders_and_supports_dry_run(): void
    {
        $this->invoice(34);
        $other = Company::create(['name' => 'Off', 'slug' => 'off-'.uniqid(), 'country_code' => 'GR', 'einvoice_provider' => 'none']);

        $this->artisan('invoices:send-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('θα καταγράφονταν 1')
            ->doesntExpectOutputToContain($other->slug)
            ->assertSuccessful();
        $this->assertSame(0, InvoiceReminder::count(), 'dry-run records nothing');
    }

    /* ───────────── the message ───────────── */

    public function test_the_message_fills_placeholders_in_the_customers_language_and_honours_overrides(): void
    {
        $inv = $this->invoice(34)->load(['customer', 'company', 'paymentMethod']);
        $due = ReminderPlanner::dueDateOf($inv);
        $settings = ReminderSettings::for($this->tenant->fresh());

        $el = app(ReminderMessage::class)->build($inv, 'second', $due, 12, 124.0, $settings, 'el');
        $this->assertStringContainsString('2η υπενθύμιση', $el['subject']);
        $this->assertStringContainsString('εδώ και 12 ημέρες', $el['bodyText']);
        $this->assertStringContainsString('124,00 €', $el['bodyText']);
        $this->assertStringContainsString($due->format('d/m/Y'), $el['bodyText']);
        $this->assertStringNotContainsString('{pay_section}', $el['bodyText']);
        $this->assertStringNotContainsString('online', $el['bodyText'], 'no gateway → no payment sentence');

        $en = app(ReminderMessage::class)->build($inv, 'final', $due, 25, 124.0, $settings, 'en');
        $this->assertStringContainsString('Final reminder', $en['subject']);

        $this->tenant->update(['reminder_templates' => ['first' => ['subject' => 'Οφειλή {invoice_code} — {customer_name}', 'body' => '']]]);
        $custom = app(ReminderMessage::class)->build($inv, 'first', $due, 4, 124.0, ReminderSettings::for($this->tenant->fresh()), 'el');
        $this->assertSame("Οφειλή {$inv->invcode} — Πελάτης Α", $custom['subject']);
        $this->assertStringContainsString('έληξε στις', $custom['bodyText'], 'a blank override keeps the default body');
    }

    public function test_a_proforma_is_named_as_one(): void
    {
        $proforma = $this->invoice(5, $this->cash, 'draft', offered: true)->load(['customer', 'company', 'paymentMethod']);

        $msg = app(ReminderMessage::class)->build($proforma, 'first', ReminderPlanner::dueDateOf($proforma), 5, 124.0, ReminderSettings::for($this->tenant->fresh()), 'el');

        $this->assertStringContainsString('προτιμολόγιο', $msg['subject']);
    }
}
