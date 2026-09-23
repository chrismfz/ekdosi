<?php

namespace Tests\Feature\Reminders;

use App\Filament\Pages\AgedReceivables;
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
use App\Services\Reminders\ReminderInsights;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderRunner;
use App\Services\Reminders\ReminderSender;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * PR B of the payment reminders: the collections picture on «Ηλικίωση οφειλών» —
 * reminder insights, the blind spots the reminders don't cover, the last reminder
 * per customer, and the manual «Υπενθύμιση τώρα».
 */
class ReceivablesInsightsTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private InvoiceType $type;

    private PaymentMethod $credit30;

    private Customer $customer;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->tenant = Company::create([
            'name' => 'Ins ΑΕ', 'slug' => 'ins-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
            'reminders_enabled' => true, 'reminders_mode' => 'review', 'reminders_since' => '2020-01-01',
            'reminder_first_days' => 3, 'reminder_second_days' => 10, 'reminder_final_days' => 20,
            'reminder_min_balance' => 1, 'reminder_attach_pdf' => false,
        ]);
        $this->type = InvoiceType::create(['company_id' => $this->tenant->id, 'code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $this->credit30 = PaymentMethod::create(['company_id' => $this->tenant->id, 'description' => '30 ημέρες', 'due_days' => 30]);
        $this->customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Οφειλέτης ΑΕ', 'email' => 'ofeil@example.gr']);

        $this->user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $this->tenant->users()->attach($this->user->id);
        $this->actingAs($this->user);
        Filament::setTenant($this->tenant);
    }

    /** A €124 document issued $daysAgo days ago (30-day terms). */
    private function invoice(int $daysAgo, array $extra = [], ?Customer $customer = null): Invoice
    {
        static $n = 0;
        $n++;
        $inv = Invoice::create($extra + [   // $extra wins
            'company_id' => $this->tenant->id, 'invoice_type_id' => $this->type->id, 'customer_id' => ($customer ?? $this->customer)->id,
            'payment_method_id' => $this->credit30->id, 'code' => $n, 'invcode' => 'TPY'.$n,
            'issued_at' => now()->subDays($daysAgo), 'local_status' => 'active',
        ]);
        InvoiceLine::create(['company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'qty' => 1, 'price_per_item' => 100, 'vat_percent' => 24]);
        app(RecomputeInvoiceTotals::class)($inv);

        return $inv->fresh();
    }

    /* ───────────── «Υπενθύμιση τώρα» ───────────── */

    public function test_the_manual_reminder_pre_ticks_overdue_documents_and_sends_one_email_each(): void
    {
        Gate::before(fn () => true);
        $overdue = $this->invoice(40);   // due 10 days ago
        $notDue = $this->invoice(5);     // due in 25 days

        $page = Livewire::test(AgedReceivables::class)
            ->mountAction('remind', ['customer' => $this->customer->id]);
        $this->assertSame([$overdue->id], array_map('intval', $page->get('mountedActions')[0]['data']['invoices']));

        $page->set('mountedActions.0.data.invoices', [(string) $overdue->id, (string) $notDue->id])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified();

        Mail::assertSentCount(2);
        $rows = InvoiceReminder::orderBy('id')->get();
        $this->assertSame([InvoiceReminder::STAGE_MANUAL, InvoiceReminder::STAGE_MANUAL], $rows->pluck('stage')->all());
        $this->assertSame([null, null], $rows->pluck('auto_stage')->all(), 'a manual reminder never burns an automatic stage');
        $this->assertSame(['manual', 'manual'], $rows->pluck('trigger')->all());
        $this->assertSame([InvoiceReminder::STATUS_SENT, InvoiceReminder::STATUS_SENT], $rows->pluck('status')->all());
        $this->assertSame($this->user->id, $rows->first()->triggered_by_user_id);
    }

    public function test_a_manual_reminder_works_with_automatic_ones_off_but_respects_the_customer(): void
    {
        $this->tenant->update(['reminders_enabled' => false, 'reminder_min_balance' => 500]);
        $inv = $this->invoice(40);

        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);
        $this->assertSame(1, $r['queued'], 'the tenant switch and the minimum balance are about the automatic ladder');
        Mail::assertSentCount(1);

        $again = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv->fresh()], $this->user->id);
        $this->assertSame(['TPY'.$inv->code => 'Στάλθηκε ήδη υπενθύμιση σήμερα.'], $again['skipped'], 'never twice in a day');

        $this->customer->update(['reminders_enabled' => false]);
        $other = $this->invoice(40);
        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$other], $this->user->id);
        $this->assertSame(['TPY'.$other->code => 'Ο πελάτης έχει απενεργοποιημένες υπενθυμίσεις.'], $r['skipped']);

        $this->customer->update(['reminders_enabled' => true, 'email' => null]);
        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$other->fresh()], $this->user->id);
        $this->assertSame(['TPY'.$other->code => 'Ο πελάτης δεν έχει email.'], $r['skipped']);
        Mail::assertSentCount(1);
    }

    public function test_a_manual_reminder_replaces_an_automatic_one_and_never_joins_one_in_flight(): void
    {
        $inv = $this->invoice(34);
        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());   // review mode → awaiting 1st
        $auto = InvoiceReminder::where('invoice_id', $inv->id)->sole();

        app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);

        $this->assertSame(InvoiceReminder::STATUS_CANCELLED, $auto->fresh()->status);
        $this->assertSame('first', $auto->fresh()->auto_stage, 'the manual one covered that stage');
        Mail::assertSentCount(1);

        $other = $this->invoice(34);
        InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $other->id, 'customer_id' => $this->customer->id,
            'stage' => 'first', 'auto_stage' => 'first', 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_SENDING, 'trigger' => 'auto',
        ]);
        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$other], $this->user->id);
        $this->assertSame(['TPY'.$other->code => 'Υπάρχει ήδη υπενθύμιση σε αποστολή.'], $r['skipped']);
    }

    public function test_a_partly_covered_invoice_is_chased_only_for_what_the_customer_really_owes(): void
    {
        $this->tenant->update(['reminders_mode' => 'auto']);
        $inv = $this->invoice(34);   // €124 open
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 100, 'pay_date' => now()]);   // €100 on account, not allocated

        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $row = InvoiceReminder::where('invoice_id', $inv->id)->sole();
        $this->assertSame('24.00', (string) $row->balance, 'the aged report says €24 — so does the email');
        Mail::assertSent(InvoiceReminderMail::class, fn (InvoiceReminderMail $m) => str_contains($m->bodyText, '24,00 €') && ! str_contains($m->bodyText, '124,00 €'));
    }

    public function test_a_failed_reminder_is_not_resent_the_day_a_manual_one_went_out(): void
    {
        $inv = $this->invoice(34);
        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());
        $auto = InvoiceReminder::where('invoice_id', $inv->id)->sole();
        $auto->forceFill(['status' => InvoiceReminder::STATUS_FAILED, 'attempts' => 1])->save();

        app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);
        $this->assertSame('Αντικαταστάθηκε από χειροκίνητη υπενθύμιση.', $auto->fresh()->reason, 'the failed one is retired');

        // Even a row that slipped through (another screen) never makes it a second email today.
        $stray = InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'customer_id' => $this->customer->id,
            'stage' => 'second', 'auto_stage' => 'second', 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_QUEUED, 'trigger' => 'auto',
        ]);
        app(ReminderSender::class)->send($stray->id);

        $this->assertSame('Στάλθηκε ήδη υπενθύμιση σήμερα.', $stray->fresh()->reason);
        Mail::assertSentCount(1);
    }

    public function test_a_manual_send_that_may_have_arrived_still_holds_the_automatic_ladder(): void
    {
        $inv = $this->invoice(34);
        InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'customer_id' => $this->customer->id,
            'stage' => InvoiceReminder::STAGE_MANUAL, 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_FAILED, 'trigger' => 'manual', 'attempts' => 1,   // died mid-send
        ]);

        $this->assertSame([], app(ReminderPlanner::class)->plan($this->tenant->fresh(), CarbonImmutable::today()));
    }

    public function test_an_invoice_covered_by_on_account_money_is_never_chased(): void
    {
        $inv = $this->invoice(34);
        Payment::create(['company_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'invoice_id' => null,
            'kind' => 'payment', 'amount' => 200, 'pay_date' => now()]);   // on account, not allocated

        $this->assertSame([], app(ReminderPlanner::class)->plan($this->tenant->fresh(), CarbonImmutable::today()));
        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);
        $this->assertSame(['TPY'.$inv->code => 'Ο πελάτης δεν χρωστάει συνολικά (έχει έναντι / πίστωση).'], $r['skipped']);
        Mail::assertNothingSent();
    }

    /** Two layers: the checkbox options reject a foreign id, and the action re-scopes to the customer's own candidates. */
    public function test_a_forged_document_of_another_customer_is_ignored(): void
    {
        Gate::before(fn () => true);
        $other = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άλλος', 'email' => 'allos@example.gr']);
        $theirs = $this->invoice(40, customer: $other);
        $this->invoice(40);

        Livewire::test(AgedReceivables::class)
            ->mountAction('remind', ['customer' => $this->customer->id])
            ->set('mountedActions.0.data.invoices', [(string) $theirs->id])
            ->callMountedAction();

        $this->assertSame(0, InvoiceReminder::where('invoice_id', $theirs->id)->count());
        Mail::assertNothingSent();
    }

    public function test_the_remind_button_needs_the_reminder_update_permission(): void
    {
        Gate::before(fn ($user, string $ability) => $ability !== 'Update:InvoiceReminder');
        $this->invoice(40);

        Livewire::test(AgedReceivables::class)
            ->assertActionHidden('remind')
            ->assertDontSee('Υπενθύμιση τώρα');
    }

    public function test_after_a_manual_reminder_the_automatic_stage_waits_a_few_days(): void
    {
        $inv = $this->invoice(34);   // due 4 days ago → the 1st is due
        app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);
        $planned = fn (int $inDays): array => collect(app(ReminderPlanner::class)->plan($this->tenant->fresh(), CarbonImmutable::today()->addDays($inDays)))
            ->pluck('invoice.id')->all();

        $this->assertSame([], $planned(0), 'not on the same day as the manual one');
        $this->assertSame([], $planned(ReminderPlanner::MANUAL_GAP_DAYS - 1));
        $this->assertSame([$inv->id], $planned(ReminderPlanner::MANUAL_GAP_DAYS));
        // …and the «Επόμενες» preview shows it on the day the ladder resumes, not never.
        $upcoming = app(ReminderPlanner::class)->upcoming($this->tenant->fresh(), CarbonImmutable::today(), 14);
        $this->assertSame([[$inv->id, CarbonImmutable::today()->addDays(ReminderPlanner::MANUAL_GAP_DAYS)->toDateString()]],
            array_map(fn (array $u): array => [$u['invoice']->id, $u['date']->toDateString()], $upcoming));
    }

    public function test_a_new_automatic_stage_retires_a_stale_failed_manual_reminder(): void
    {
        $inv = $this->invoice(34);
        $manual = InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'customer_id' => $this->customer->id,
            'stage' => InvoiceReminder::STAGE_MANUAL, 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_FAILED, 'trigger' => 'manual', 'attempts' => 1,
        ]);
        InvoiceReminder::whereKey($manual->id)->update(['updated_at' => now()->subDays(ReminderPlanner::MANUAL_GAP_DAYS + 1)]);   // past the wait

        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $this->assertSame(InvoiceReminder::STATUS_CANCELLED, $manual->fresh()->status, 'no «Ξανά αποστολή» of a stale manual one after the newer stage');
        $this->assertSame(1, InvoiceReminder::where('invoice_id', $inv->id)->where('trigger', 'auto')->count());
    }

    /* ───────────── insights ───────────── */

    public function test_the_summary_counts_waiting_failed_sent_and_paid_after_a_reminder(): void
    {
        $paid = $this->invoice(40);
        $open = $this->invoice(40);
        $row = fn (Invoice $i, string $status, array $extra = []) => InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $i->id, 'customer_id' => $this->customer->id,
            'stage' => 'first', 'auto_stage' => $status === InvoiceReminder::STATUS_SENT ? 'first' : null,
            'document_kind' => 'invoice', 'balance' => 124, 'status' => $status, 'trigger' => 'auto',
        ] + $extra);
        $row($paid, InvoiceReminder::STATUS_SENT, ['sent_at' => now()->subDays(5)]);
        $row($open, InvoiceReminder::STATUS_SENT, ['sent_at' => now()->subDays(40)]);
        $row($open, InvoiceReminder::STATUS_AWAITING);
        $row($open, InvoiceReminder::STATUS_FAILED);
        $paid->forceFill(['payment_status' => 'paid'])->save();

        $summary = app(ReminderInsights::class)->summary($this->tenant);

        $this->assertSame(1, $summary['awaiting']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['sent30'], 'only the last 30 days');
        $this->assertSame(['count' => 1, 'amount' => 124.0], $summary['paid_after']);
    }

    public function test_the_blind_spots_list_what_the_reminders_never_chase(): void
    {
        $draft = $this->invoice(3, ['local_status' => 'draft']);                          // never offered
        $whmcsDraft = $this->invoice(3, ['local_status' => 'draft']);
        $whmcsDraft->forceFill(['whmcs_invoice_id' => 556])->save();                       // the WHMCS inbox's
        $old = $this->invoice(40);
        $this->tenant->update(['reminders_since' => now()->subDays(5)->toDateString()]);  // due before «από»
        $whmcs = $this->invoice(40);
        $whmcs->forceFill(['whmcs_invoice_id' => 555])->save();   // not mass-assignable
        $legacy = $this->invoice(400, ['legacy_id' => 77]);
        $noEmail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Χωρίς email']);
        $blocked = $this->invoice(40, customer: $noEmail);
        $ours = $this->invoice(34);                                                        // due 4 days ago, after «από» → in no gap

        $gaps = app(ReminderInsights::class)->gaps($this->tenant);
        $ids = fn (string $gap): array => collect(app(ReminderInsights::class)->gapList($this->tenant, $gap))->pluck('invoice.id')->all();

        $this->assertSame([$draft->id], $ids(ReminderInsights::GAP_DRAFTS));
        $this->assertSame([$whmcs->id], $ids(ReminderInsights::GAP_WHMCS));
        $this->assertSame([$legacy->id], $ids(ReminderInsights::GAP_LEGACY));
        $this->assertSame([$blocked->id], $ids(ReminderInsights::GAP_BLOCKED));
        $this->assertSame([$old->id], $ids(ReminderInsights::GAP_EXCLUDED), 'due before the «από» date: never reminded automatically');
        $this->assertNotContains($whmcsDraft->id, $ids(ReminderInsights::GAP_DRAFTS));
        $this->assertSame(['count' => 1, 'amount' => 124.0], $gaps[ReminderInsights::GAP_WHMCS]);
        foreach (array_keys(ReminderInsights::GAP_LABELS) as $gap) {
            $this->assertNotContains($ours->id, $ids($gap));
        }
    }

    public function test_the_page_shows_the_insights_and_the_last_reminder_per_customer(): void
    {
        Gate::before(fn () => true);
        $inv = $this->invoice(40);
        $this->invoice(3, ['local_status' => 'draft']);
        InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'customer_id' => $this->customer->id,
            'stage' => 'second', 'auto_stage' => 'second', 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_SENT, 'trigger' => 'auto', 'sent_at' => now()->subDays(2),
        ]);

        Livewire::test(AgedReceivables::class)
            ->assertOk()
            ->assertSee('Υπενθυμίσεις πληρωμής')
            ->assertSee('Πρόχειρα που δεν στάλθηκαν ποτέ')
            ->assertSee(now()->subDays(2)->format('d/m/Y'))
            ->assertSee('2η υπενθύμιση')
            ->mountAction('gap', ['kind' => ReminderInsights::GAP_DRAFTS])
            ->assertActionMounted('gap');
    }
}
