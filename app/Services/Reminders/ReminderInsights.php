<?php

namespace App\Services\Reminders;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use App\Support\InvoiceScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The «what's happening with collections» picture on «Ηλικίωση οφειλών»: how the
 * reminders are doing, and — just as important — which open documents the
 * reminders do NOT cover (so nothing owed hides in a blind spot). Read-only.
 *
 * Document amounts come from the money CACHE (payable − credited − paid), the
 * same figure lists and tables read; the reminders themselves re-check the live
 * balance before anything is sent.
 */
final class ReminderInsights
{
    /** How far back «εξοφλήθηκαν μετά από υπενθύμιση» looks. */
    public const PAID_AFTER_DAYS = 90;

    /** Rows shown per «not reminded» list (the count/total cover them all). */
    public const LIST_LIMIT = 50;

    public const GAP_DRAFTS = 'drafts';

    public const GAP_WHMCS = 'whmcs';

    public const GAP_LEGACY = 'legacy';

    public const GAP_BLOCKED = 'blocked';

    public const GAP_EXCLUDED = 'excluded';

    public const GAP_LABELS = [
        self::GAP_DRAFTS => 'Πρόχειρα που δεν στάλθηκαν ποτέ',
        self::GAP_WHMCS => 'Από WHMCS (ανεξόφλητα)',
        self::GAP_LEGACY => 'Παλιά / εισαγωγές',
        self::GAP_BLOCKED => 'Πελάτες χωρίς email / opt-out',
        self::GAP_EXCLUDED => 'Εκτός κριτηρίων (ημ/νία «από» / ελάχιστο)',
    ];

    public const GAP_HELP = [
        self::GAP_DRAFTS => 'Πρόχειρα που ο πελάτης δεν έχει δει — στείλ\'τα ή οριστικοποίησέ τα για να μπουν στις υπενθυμίσεις.',
        self::GAP_WHMCS => 'Το WHMCS στέλνει τις δικές του υπενθυμίσεις — εδώ δεν ξαναστέλνουμε.',
        self::GAP_LEGACY => 'Από το παλιό πρόγραμμα ή καταχωρισμένα από τα αδέσποτα του myDATA — δεν υπενθυμίζονται αυτόματα.',
        self::GAP_BLOCKED => 'Δικά μας ανεξόφλητα που δεν μπορούν να πάρουν υπενθύμιση (πελάτης χωρίς email ή με απενεργοποιημένες υπενθυμίσεις).',
        self::GAP_EXCLUDED => 'Δικά μας ανεξόφλητα που οι αυτόματες υπενθυμίσεις παρακάμπτουν: έληγαν πριν την ημερομηνία «από» των ρυθμίσεων, ή το υπόλοιπο είναι κάτω από το ελάχιστο. Μπορείς να τα στείλεις χειροκίνητα («Υπενθύμιση τώρα»).',
    ];

    public function __construct(private readonly ReminderPlanner $planner) {}

    /**
     * @return array{enabled: bool, awaiting: int, failed: int, sent30: int, paid_after: array{count: int, amount: float}}
     */
    public function summary(Company $company): array
    {
        $rows = fn () => InvoiceReminder::query()->withoutGlobalScope(CompanyScope::class)->where('company_id', $company->getKey());

        return [
            'enabled' => (bool) $company->reminders_enabled,
            'awaiting' => $rows()->where('status', InvoiceReminder::STATUS_AWAITING)->count(),
            'failed' => $rows()->where('status', InvoiceReminder::STATUS_FAILED)->count(),
            'sent30' => $rows()->where('status', InvoiceReminder::STATUS_SENT)->where('sent_at', '>=', now()->subDays(30))->count(),
            'paid_after' => $this->paidAfterReminder($company),
        ];
    }

    /**
     * Documents reminded in the last PAID_AFTER_DAYS that are now paid in full —
     * counted once each, at the balance their LAST reminder chased.
     *
     * @return array{count: int, amount: float}
     */
    public function paidAfterReminder(Company $company): array
    {
        $latest = InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('status', InvoiceReminder::STATUS_SENT)
            ->where('sent_at', '>=', now()->subDays(self::PAID_AFTER_DAYS))
            ->orderBy('sent_at')
            ->get(['invoice_id', 'balance'])
            ->keyBy('invoice_id');   // later rows overwrite → the last reminder per document

        if ($latest->isEmpty()) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $paid = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('id', $latest->keys())
            ->where('payment_status', PaymentStatus::Paid->value)
            ->pluck('id');

        return [
            'count' => $paid->count(),
            'amount' => round((float) $paid->sum(fn (int $id): float => (float) $latest[$id]->balance), 2),
        ];
    }

    /**
     * The last reminder that went out to each of these customers.
     *
     * @param  list<int>  $customerIds
     * @return array<int, array{sent_at: CarbonImmutable, stage: string}>
     */
    public function lastSentByCustomer(Company $company, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $last = [];
        InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('customer_id', $customerIds)
            ->where('status', InvoiceReminder::STATUS_SENT)
            ->orderBy('sent_at')
            ->get(['customer_id', 'sent_at', 'stage'])
            ->each(function (InvoiceReminder $r) use (&$last): void {
                $last[(int) $r->customer_id] = ['sent_at' => CarbonImmutable::parse($r->sent_at), 'stage' => (string) $r->stage];
            });

        return $last;
    }

    /**
     * Count + amount of every blind spot — what the reminders never chase.
     *
     * @return array<string, array{count: int, amount: float}>
     */
    public function gaps(Company $company): array
    {
        $out = [];
        foreach (array_keys(self::GAP_LABELS) as $gap) {
            $docs = $this->gapDocuments($company, $gap);
            $out[$gap] = ['count' => $docs->count(), 'amount' => round($docs->sum(fn (Invoice $i): float => self::openAmount($i)), 2)];
        }

        return $out;
    }

    /**
     * One blind spot's documents, biggest first, capped at LIST_LIMIT.
     *
     * @return list<array{invoice: Invoice, amount: float, age: ?int}>
     */
    public function gapList(Company $company, string $gap): array
    {
        return $this->gapDocuments($company, $gap)
            ->map(fn (Invoice $i): array => [
                'invoice' => $i,
                'amount' => self::openAmount($i),
                'age' => $i->issued_at !== null ? (int) CarbonImmutable::parse($i->issued_at)->startOfDay()->diffInDays(CarbonImmutable::today()) : null,
            ])
            ->sortByDesc('amount')
            ->take(self::LIST_LIMIT)
            ->values()
            ->all();
    }

    /** @return Collection<int, Invoice> */
    private function gapDocuments(Company $company, string $gap)
    {
        $with = fn (Builder $q): Builder => $q->with(['customer:id,name,email,reminders_enabled', 'paymentMethod:id,due_days', 'invoiceType:id,is_credit']);

        return match ($gap) {
            // Sale drafts never put in front of the customer (an offered one is a
            // reminded προτιμολόγιο). Not money-owed yet — shown at their value.
            self::GAP_DRAFTS => $with($this->unofferedDrafts($company))->get(),
            // Open, credit-term only — a cash-term document is never owed.
            self::GAP_WHMCS => $with($this->planner->openDocuments($company)
                ->where(fn ($q) => $q->whereNotNull('whmcs_invoice_id')->orWhereHas('whmcsPending')))
                ->get()->filter(fn (Invoice $i): bool => ReminderPlanner::dueDateOf($i) !== null)->values(),
            self::GAP_LEGACY => $with($this->planner->openDocuments($company)->where(fn ($q) => $q->whereNotNull('legacy_id')->orWhereNotNull('origin'))
                ->whereNull('whmcs_invoice_id')->whereDoesntHave('whmcsPending'))
                ->get()->filter(fn (Invoice $i): bool => ReminderPlanner::dueDateOf($i) !== null)->values(),
            // Ours and owed, but the customer can't receive a reminder.
            self::GAP_BLOCKED => $this->owedCandidates($company)
                ->filter(fn (Invoice $i): bool => self::customerUnreachable($i))
                ->values(),
            // Ours, reachable, but outside the automatic ladder's criteria.
            self::GAP_EXCLUDED => $this->excluded($company),
            default => collect(),
        };
    }

    private static function customerUnreachable(Invoice $invoice): bool
    {
        return $invoice->customer !== null && (blank($invoice->customer->email) || ! $invoice->customer->reminders_enabled);
    }

    /** @return Collection<int, Invoice> */
    private function excluded(Company $company)
    {
        $settings = ReminderSettings::for($company);
        if (! $settings->enabled) {
            return collect();   // switched off: the page says so instead
        }

        return $this->owedCandidates($company)
            ->filter(fn (Invoice $i): bool => ! self::customerUnreachable($i)
                && (ReminderPlanner::dueDateOf($i)->lt($settings->since) || $i->getAttribute('chaseable') < $settings->minBalance))
            ->values();
    }

    /**
     * Our credit-term candidates that are really owed — the planner's rules: an
     * open amount, and (for an invoice) capped at what the customer owes overall
     * (on-account money not yet allocated covers it). Tagged with `chaseable`.
     *
     * @return Collection<int, Invoice>
     */
    private function owedCandidates(Company $company)
    {
        $candidates = $this->planner->candidates($company)
            ->filter(fn (Invoice $i): bool => ReminderPlanner::dueDateOf($i) !== null && self::openAmount($i) > 0.005);
        $net = $this->planner->customerOutstanding($company, $candidates->pluck('customer_id')->unique()->values()->all());

        return $candidates
            ->each(fn (Invoice $i) => $i->setAttribute('chaseable', $this->planner->chaseableBalance($i, self::openAmount($i), $net[$i->customer_id] ?? 0.0)))
            ->filter(fn (Invoice $i): bool => $i->getAttribute('chaseable') > 0.005)
            ->values();
    }

    /** @return Builder<Invoice> */
    private function unofferedDrafts(Company $company): Builder
    {
        $query = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->where('local_status', 'draft')
            ->whereNull('offered_at')
            ->whereNull('legacy_id')
            ->whereNotNull('customer_id')
            // A WHMCS-inbox draft is the inbox's work (and WHMCS reminds it) — not
            // a document our reminders would ever pick up once issued.
            ->whereNull('whmcs_invoice_id')
            ->whereDoesntHave('whmcsPending');
        InvoiceScope::excludeCreditNotes($query);

        return $query;
    }

    /**
     * What is still open on a document, from the money cache — a draft (never
     * issued, no money cache yet) at its full value.
     */
    public static function openAmount(Invoice $invoice): float
    {
        $payable = $invoice->payableTotal();
        if ($invoice->local_status === 'draft' && $invoice->offered_at === null) {
            return $payable;
        }

        return round($payable - (float) ($invoice->credited_total ?? 0) - (float) ($invoice->paid_total ?? 0), 2);
    }
}
