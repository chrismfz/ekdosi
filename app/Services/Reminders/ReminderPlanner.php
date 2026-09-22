<?php

namespace App\Services\Reminders;

use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceReminder;
use App\Models\Scopes\CompanyScope;
use App\Services\InvoiceBalance;
use App\Support\InvoiceScope;
use Carbon\CarbonImmutable;
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
    public function __construct(private readonly InvoiceBalance $balances) {}

    /**
     * @return list<array{invoice: Invoice, stage: string, due: CarbonImmutable, days: int, balance: float}>
     */
    public function plan(Company $company, CarbonImmutable $today): array
    {
        $settings = ReminderSettings::for($company);
        if (! $settings->enabled || $settings->stages === []) {
            return [];
        }

        $candidates = $this->candidates($company);
        $done = InvoiceReminder::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereIn('invoice_id', $candidates->modelKeys())
            ->whereNotNull('auto_stage')
            ->get(['invoice_id', 'auto_stage'])
            ->groupBy('invoice_id')
            ->map(fn ($rows) => $rows->pluck('auto_stage')->all());

        $planned = [];
        foreach ($candidates as $invoice) {
            $due = self::dueDateOf($invoice);
            if ($due === null || $due->lt($settings->since) || $this->blocker($invoice, $settings) !== null) {
                continue;
            }

            $days = (int) $due->diffInDays($today->startOfDay(), false);
            $stage = self::stageFor($settings->stages, $days, $done->get($invoice->getKey(), []));
            if ($stage === null) {
                continue;
            }

            $planned[] = [
                'invoice' => $invoice,
                'stage' => $stage,
                'due' => $due,
                'days' => $days,
                'balance' => $this->balances->for($invoice)->balance,
            ];
        }

        return $planned;
    }

    /**
     * Our unpaid documents the customer could be reminded about (before the
     * per-document checks in blocker()).
     *
     * @return Collection<int, Invoice>
     */
    public function candidates(Company $company): Collection
    {
        $query = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $company->getKey())
            ->whereNull('legacy_id')
            ->whereNotNull('customer_id')
            ->whereNull('whmcs_invoice_id')
            ->whereDoesntHave('whmcsPending')
            ->whereIn('payment_status', [PaymentStatus::Unpaid->value, PaymentStatus::Partial->value])
            ->where(fn ($q) => $q
                ->where('local_status', 'active')
                ->orWhere(fn ($o) => $o->where('local_status', 'draft')->whereNotNull('offered_at')))
            ->with(['customer', 'paymentMethod', 'invoiceType', 'company']);

        InvoiceScope::live($query);
        InvoiceScope::excludeCreditNotes($query);

        return $query->orderBy('id')->get();
    }

    /**
     * Why this document must NOT be reminded right now (null = it may). Checked
     * when planning AND again just before sending — it may have been paid since.
     */
    public function blocker(Invoice $invoice, ReminderSettings $settings): ?string
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
            default => $this->balanceBlocker($invoice, $settings),
        };
    }

    private function balanceBlocker(Invoice $invoice, ReminderSettings $settings): ?string
    {
        $balance = $this->balances->for($invoice)->balance;
        if ($balance <= 0.005) {
            return 'Εξοφλήθηκε.';
        }
        if ($balance < $settings->minBalance) {
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
        $order = array_keys($stages);
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
