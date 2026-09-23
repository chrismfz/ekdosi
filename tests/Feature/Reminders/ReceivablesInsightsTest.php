<?php

namespace Tests\Feature\Reminders;

use App\Filament\Pages\AgedReceivables;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceReminder;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\RecomputeInvoiceTotals;
use App\Services\Reminders\ReminderInsights;
use App\Services\Reminders\ReminderPlanner;
use App\Services\Reminders\ReminderRunner;
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

    public function test_a_manual_reminder_is_the_operators_call_but_still_needs_an_email_and_a_balance(): void
    {
        $this->tenant->update(['reminders_enabled' => false]);
        $this->customer->update(['reminders_enabled' => false]);   // opted out of the automatic ones
        $inv = $this->invoice(40);

        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv], $this->user->id);
        $this->assertSame(1, $r['queued'], 'automatic switches off + customer opt-out do not stop a deliberate manual reminder');
        Mail::assertSentCount(1);

        $this->customer->update(['email' => null]);
        $r = app(ReminderRunner::class)->sendManual($this->tenant->fresh(), [$inv->fresh()], $this->user->id);
        $this->assertSame(0, $r['queued']);
        $this->assertSame(['TPY'.$inv->code => 'Ο πελάτης δεν έχει email.'], $r['skipped']);
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
    }

    public function test_a_new_automatic_stage_leaves_the_operators_failed_manual_reminder_alone(): void
    {
        $inv = $this->invoice(34);
        $manual = InvoiceReminder::create([
            'company_id' => $this->tenant->id, 'invoice_id' => $inv->id, 'customer_id' => $this->customer->id,
            'stage' => InvoiceReminder::STAGE_MANUAL, 'document_kind' => 'invoice', 'balance' => 124,
            'status' => InvoiceReminder::STATUS_FAILED, 'trigger' => 'manual', 'attempts' => 1,
        ]);

        app(ReminderRunner::class)->run($this->tenant->fresh(), CarbonImmutable::today());

        $this->assertSame(InvoiceReminder::STATUS_FAILED, $manual->fresh()->status);
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
        $whmcs = $this->invoice(40);
        $whmcs->forceFill(['whmcs_invoice_id' => 555])->save();   // not mass-assignable
        $legacy = $this->invoice(400, ['legacy_id' => 77]);
        $noEmail = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Χωρίς email']);
        $blocked = $this->invoice(40, customer: $noEmail);
        $ours = $this->invoice(40);                                                        // reminded → in no gap

        $gaps = app(ReminderInsights::class)->gaps($this->tenant);
        $ids = fn (string $gap): array => collect(app(ReminderInsights::class)->gapList($this->tenant, $gap))->pluck('invoice.id')->all();

        $this->assertSame([$draft->id], $ids(ReminderInsights::GAP_DRAFTS));
        $this->assertSame([$whmcs->id], $ids(ReminderInsights::GAP_WHMCS));
        $this->assertSame([$legacy->id], $ids(ReminderInsights::GAP_LEGACY));
        $this->assertSame([$blocked->id], $ids(ReminderInsights::GAP_BLOCKED));
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
