<?php

namespace App\Services\Reminders;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Support\InvoiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which documents get a payment reminder today, and at which stage.
 *
 * Remindable = OUR documents the customer still owes on:
 *   - an issued credit-term invoice (payment method due_days > 0 — a cash-term
 *     one is settled at issue and never owed), due = issue date + due_days;
 *   - an offered προτιμολόγιο (a draft put in front of the customer), due =
 *     offer date + due_days (0 = due at once).
 * Never: WHMCS-linked documents (WHMCS runs its own reminders), legacy/ETL
 * imports, credit notes, cancelled documents, customers who opted out, anything
 * due before the tenant's «reminders_since», or a balance under the minimum.
 *
 * Stages are WHMCS-style day offsets from the due date. On a given day the
 * document gets the HIGHEST stage it has reached that it hasn't had yet — after
 * downtime it catches up with ONE reminder, never a burst of all the missed ones.
 * A «before due» reminder is only ever sent before the due date.
 */
final class ReminderPlanner
{
    /** After a manual reminder, the automatic ladder waits this many days. */
    public const MANUAL_GAP_DAYS = 3;

    public function __construct(private readonly InvoiceBalance $balances) {}

    /**
     * @return list<array{invoice: Invoice, stage: string, due: CarbonImmutable, days: int, balance: float}>
     */
    public function plan(Company $company, CarbonImmutable $today): array
    {
        $planned = [];
        foreach ($this->eligible($company, $today) as [$invoice, $due, $done, $stages, $balance, $resumeOn]) {
            if ($resumeOn !== null && $resumeOn->gt($today->startOfDay())) {
                continue;   // chased by hand a moment ago — the automatic stage waits
            }
            $days = (int) $due->diffInDays($today->startOfDay(), false);
            $stage = self::stageFor($stages, $days, $done);
            if ($stage !== null) {
                $planned[] = ['invoice' => $invoice, 'stage' => $stage, 'due' => $due, 'days' => $days, 'balance' => $balance];
            }
        }

        return $planned;
    }

    /**
     * The first reminder each document would get in the next `$window` days,
     * today included (on today's data) — what plan() would return day by day, in
     * one pass.
     *
     * @return list<array{date: CarbonImmutable, invoice: Invoice, stage: string, balance: float}>
     */
    public function upcoming(Company $company, CarbonImmutable $today, int $window): array
    {
        $today = $today->startOfDay();
        $upcoming = [];
        foreach ($this->eligible($company, $today) as [$invoice, $due, $done, $stages, $balance, $resumeOn]) {
            $days = (int) $due->diffInDays($today, false);
            $from = $resumeOn !== null ? max(0, (int) $today->diffInDays($resumeOn, false)) : 0;
            for ($d = $from; $d < $window; $d++) {
                if (($stage = self::stageFor($stages, $days + $d, $done)) !== null) {
                    $upcoming[] = ['date' => $today->addDays($d), 'invoice' => $invoice, 'stage' => $stage, 'balance' => $balance];

                    break;
                }
            }
        }

        usort($upcoming, static fn (array $a, array $b): int => [$a['date'], $a['invoice']->getKey()] <=> [$b['date'], $b['invoice']->getKey()]);

        return $upcoming;
    }

    /**
     * Candidates that pass every per-document check, with their due date and the
     * automatic stages they already had — AS THIS KIND of document (a προτιμολόγιο
     * issued as an invoice starts a fresh ladder against its new due date).
     *
     * …and, for a document chased by hand recently, the day its automatic ladder
     * resumes (index 5, null = not chased).
     *
     * @return iterable<array{0: Invoice, 1: CarbonImmutable, 2: list<string>, 3: array<string, int>, 4: float, 5: ?CarbonImmutable}>
     */
    private function eligible(Company $company, CarbonImmutable $today): iterable
    {
        $settings = ReminderSettings::for($company);
        if (! $settings->enabled || $settings->stages === []) {
            return;
        }

        $candidates = $this->candidates($company);
        $outstanding = $this->customerOutstanding($company, $candidates->pluck('customer_id')->unique()->all());
        // The operator just chased it by hand — the automatic stage waits a few
        // days rather than land in the customer's inbox next to it: it resumes
        // MANUAL_GAP_DAYS after the manual one went out (still in flight = today).
        $resumeOn = [];
        InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('trigger', 'manual')
            ->whereIn('status', [InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_SENDING, InvoiceReminder::STATUS_SENT])
            ->where(fn ($q) => $q->whereNull('sent_at')->orWhere('sent_at', '>', $today->subDays(self::MANUAL_GAP_DAYS)->endOfDay()))
            ->get(['invoice_id', 'sent_at'])
            ->each(function (InvoiceReminder $r) use (&$resumeOn, $today): void {
                $from = $r->sent_at !== null ? CarbonImmutable::parse($r->sent_at)->startOfDay() : $today->startOfDay();
                $resume = $from->addDays(self::MANUAL_GAP_DAYS);
                $current = $resumeOn[$r->invoice_id] ?? null;
                $resumeOn[$r->invoice_id] = $current === null || $resume->gt($current) ? $resume : $current;
            });
        $done = InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('invoice_id', $candidates->modelKeys())
            ->whereNotNull('auto_stage')
            ->get(['invoice_id', 'document_kind', 'auto_stage'])
            ->groupBy(fn (InvoiceReminder $r): string => $r->invoice_id.'|'.$r->document_kind)
            ->map(fn ($rows) => $rows->pluck('auto_stage')->all());

        foreach ($candidates as $invoice) {
            $due = self::dueDateOf($invoice);
            if ($due === null || $due->lt($settings->since)) {
                continue;
            }
            $balance = $this->balances->for($invoice)->balance;
            if ($this->blocker($invoice, $settings, $balance, customerOutstanding: $outstanding[$invoice->customer_id] ?? 0.0) !== null) {
                continue;
            }

            yield [$invoice, $due, $done->get($invoice->getKey().'|'.self::kindOf($invoice), []), $settings->stages, $balance, $resumeOn[$invoice->getKey()] ?? null];
        }
    }

    /**
     * Our unpaid documents the customer could be reminded about (before the
     * per-document checks in blocker()) — optionally one customer's.
     *
     * @return Collection<int, Invoice>
     */
    public function candidates(Company $company, ?int $customerId = null): Collection
    {
        return $this->openDocuments($company)
            ->whereNull('legacy_id')
            ->whereNull('whmcs_invoice_id')
            ->whereDoesntHave('whmcsPending')
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->with(['customer', 'paymentMethod', 'invoiceType', 'company'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Every open (unpaid/partial) customer document of the tenant — issued, or an
     * offered προτιμολόγιο — whatever its origin. candidates() narrows it to ours;
     * the insights use it to show what is NOT reminded (WHMCS, legacy).
     *
     * @return Builder<Invoice>
     */
    public function openDocuments(Company $company): Builder
    {
        $query = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNotNull('customer_id')
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value])
            ->where(fn ($q) => $q
                ->where('local_status', 'active')
                ->orWhere(fn ($o) => $o->where('local_status', 'draft')->whereNotNull('offered_at')));

        InvoiceScope::live($query);
        InvoiceScope::excludeCreditNotes($query);

        return $query;
    }

    /**
     * Why this document must NOT be reminded right now (null = it may). Checked
     * when planning AND again just before sending — it may have been paid since.
     * A MANUAL reminder is the operator's deliberate call: the minimum balance
     * (about the automatic ladder) doesn't stop it — the customer's opt-out does.
     */
    public function blocker(Invoice $invoice, ReminderSettings $settings, ?float $balance = null, bool $manual = false, ?float $customerOutstanding = null): ?string
    {
        $customer = $invoice->customer;

        return match (true) {
            $customer === null => 'Χωρίς πελάτη.',
            ! $customer->reminders_enabled => 'Ο πελάτης έχει απενεργοποιημένες υπενθυμίσεις.',
            blank($customer->email) => 'Ο πελάτης δεν έχει email.',
            $invoice->local_status === 'cancelled' || $invoice->mydata_state === 'CANCELLED' => 'Το παραστατικό ακυρώθηκε.',
            $invoice->local_status === 'draft' && $invoice->offered_at === null => 'Η προσφορά ανακλήθηκε.',
            $invoice->isCreditNote() => 'Πιστωτικό.',
            $invoice->legacy_id !== null || $invoice->whmcs_invoice_id !== null => 'Εκτός υπενθυμίσεων (εισαγωγή/WHMCS).',
            self::dueDateOf($invoice) === null => 'Τοις μετρητοίς — δεν οφείλεται.',
            default => $this->balanceBlocker($balance ?? $this->balances->for($invoice)->balance, $manual ? 0.0 : $settings->minBalance)
                ?? $this->netBalanceBlocker($invoice, $customerOutstanding),
        };
    }

    /**
     * An invoice is never chased while the customer owes nothing OVERALL — an
     * on-account payment or credit not yet allocated to it already covers it
     * (the aged-receivables row shows no debt). Not for a προτιμολόγιο: unpaid
     * drafts stay out of the customer's balance by design (MON-5).
     */
    private function netBalanceBlocker(Invoice $invoice, ?float $customerOutstanding): ?string
    {
        if (self::kindOf($invoice) === InvoiceReminder::KIND_PROFORMA || $invoice->customer_id === null) {
            return null;
        }
        $net = $customerOutstanding
            ?? ($this->customerOutstanding($invoice->company ?? Company::query()->find($invoice->company_id), [(int) $invoice->customer_id])[$invoice->customer_id] ?? 0.0);

        return $net <= 0.005 ? 'Ο πελάτης δεν χρωστάει συνολικά (έχει έναντι / πίστωση).' : null;
    }

    /**
     * Each customer's overall outstanding balance — the dashboard / aged-report
     * figure (on-account payments and credits netted).
     *
     * @param  list<int>  $customerIds
     * @return array<int, float>
     */
    public function customerOutstanding(Company $company, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return Customer::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('customers.company_id', $company->getKey())
            ->whereIn('customers.id', $customerIds)
            ->withOutstandingBalance((int) $company->getKey())
            ->get()
            ->mapWithKeys(fn (Customer $c): array => [(int) $c->getKey() => round((float) $c->outstanding_balance, 2)])
            ->all();
    }

    /**
     * Why a MANUAL reminder can't go to this document now (null = it can): the
     * document's own blocker, one already on its way (manual or automatic), or
     * one that already went out today.
     */
    public function manualBlocker(Invoice $invoice, ReminderSettings $settings, ?float $balance = null): ?string
    {
        if (($blocker = $this->blocker($invoice, $settings, $balance, manual: true)) !== null) {
            return $blocker;
        }

        $rows = fn () => InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)->where('invoice_id', $invoice->getKey());

        return match (true) {
            $rows()->whereIn('status', [InvoiceReminder::STATUS_QUEUED, InvoiceReminder::STATUS_SENDING])->exists() => 'Υπάρχει ήδη υπενθύμιση σε αποστολή.',
            $rows()->where('status', InvoiceReminder::STATUS_SENT)->where('sent_at', '>=', CarbonImmutable::today())->exists() => 'Στάλθηκε ήδη υπενθύμιση σήμερα.',
            default => null,
        };
    }

    /**
     * Why a RECORDED reminder must not go out (null = it may): the document's own
     * blocker, plus what changed since the row was written — the tenant switched
     * reminders off (automatic rows only), or the προτιμολόγιο was issued as an
     * invoice (its ladder restarts against the new due date).
     */
    public function rowBlocker(InvoiceReminder $row, ?Invoice $invoice, ReminderSettings $settings): ?string
    {
        return match (true) {
            $invoice === null => 'Το παραστατικό δεν υπάρχει.',
            $row->trigger === 'auto' && ! $settings->enabled => 'Οι υπενθυμίσεις απενεργοποιήθηκαν.',
            $row->document_kind !== self::kindOf($invoice) => $row->document_kind === InvoiceReminder::KIND_PROFORMA
                ? 'Το προτιμολόγιο εκδόθηκε ως τιμολόγιο.'
                : 'Το παραστατικό επανήλθε σε πρόχειρο.',
            $row->auto_stage === InvoiceReminder::STAGE_PRE_DUE
                && ($due = self::dueDateOf($invoice)) !== null && $due->lte(CarbonImmutable::today()) => 'Έληξε — ισχύει πλέον η επόμενη βαθμίδα.',
            $this->laterStageExists($row) => 'Αντικαταστάθηκε από νεότερη βαθμίδα.',
            default => $this->blocker($invoice, $settings, manual: $row->trigger === 'manual'),
        };
    }

    /** Has a later automatic stage of this document gone out (or been recorded)? */
    private function laterStageExists(InvoiceReminder $row): bool
    {
        $at = array_search($row->auto_stage, InvoiceReminder::AUTO_STAGES, true);
        if ($row->auto_stage === null || $at === false) {
            return false;
        }

        return InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('invoice_id', $row->invoice_id)
            ->where('document_kind', $row->document_kind)
            ->whereIn('auto_stage', array_slice(InvoiceReminder::AUTO_STAGES, $at + 1))
            ->whereKeyNot($row->getKey())
            ->exists();
    }

    private function balanceBlocker(float $balance, float $minBalance): ?string
    {
        if ($balance <= 0.005) {
            return 'Εξοφλήθηκε.';
        }
        if ($balance < $minBalance) {
            return 'Υπόλοιπο κάτω από το ελάχιστο.';
        }

        return null;
    }

    /**
     * The due date: issued → issue date + due_days (null for cash terms, never
     * owed); offered προτιμολόγιο → offer date + due_days (0 = due at once).
     */
    public static function dueDateOf(Invoice $invoice): ?CarbonImmutable
    {
        $dueDays = (int) ($invoice->paymentMethod?->due_days ?? 0);

        if ($invoice->local_status === 'draft') {
            return $invoice->offered_at !== null
                ? CarbonImmutable::parse($invoice->offered_at)->startOfDay()->addDays(max(0, $dueDays))
                : null;
        }

        return $dueDays > 0 && $invoice->issued_at !== null
            ? CarbonImmutable::parse($invoice->issued_at)->startOfDay()->addDays($dueDays)
            : null;
    }

    public static function kindOf(Invoice $invoice): string
    {
        return $invoice->local_status === 'draft' ? InvoiceReminder::KIND_PROFORMA : InvoiceReminder::KIND_INVOICE;
    }

    /**
     * The stage to send `$days` after the due date (negative = before), given
     * the automatic stages already recorded — or null.
     *
     * @param  array<string, int>  $stages  stage => offset, ascending
     * @param  list<string>  $done
     */
    public static function stageFor(array $stages, int $days, array $done): ?string
    {
        $reached = null;
        foreach ($stages as $stage => $offset) {
            if ($offset <= $days) {
                $reached = $stage;
            }
        }

        if ($reached === null || in_array($reached, $done, true)) {
            return null;
        }

        // A later stage already went out (settings changed since) — don't step back.
        // The FIXED ladder order, not just the configured stages: a stage switched
        // off after it went out still outranks the ones below it (same order the
        // send-time check uses — they must agree, or a row is planned then refused
        // every day).
        $order = InvoiceReminder::AUTO_STAGES;
        foreach ($done as $sent) {
            $sentAt = array_search($sent, $order, true);
            if ($sentAt !== false && $sentAt > array_search($reached, $order, true)) {
                return null;
            }
        }

        // «Before due» is only ever a before-due message.
        if ($reached === InvoiceReminder::STAGE_PRE_DUE && $days >= 0) {
            return null;
        }

        return $reached;
    }
}
