<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\InvoiceBalance;
use App\Support\Whmcs\WhmcsPaymentSyncCache;
use Filament\Notifications\Notification;
use Throwable;

/**
 * READ-ONLY detector for the WHMCS payment-sync worklist. It answers «ποια
 * ανοιχτά επί-πιστώσει τιμολόγια έχει πλέον πληρώσει το WHMCS;» WITHOUT writing
 * any money — it only builds the list the dashboard widget + console page show,
 * caches it (WhmcsPaymentSyncCache), and raises a durable bell notification for
 * each NEWLY-appeared item so the operator finds it «εύκαιρα».
 *
 * The actual money-write stays a deliberate, per-invoice operator step
 * (WhmcsPaymentSyncer::syncInvoice, re-checking WHMCS live at click time) — this
 * class never records a Payment. Phase 1 detects the INBOUND direction only.
 */
class WhmcsPaymentReconciler
{
    public function __construct(private readonly InvoiceBalance $balance) {}

    /**
     * Recompute the tenant's inbound worklist, cache it, and bell-notify new
     * items. Returns the inbound invoice ids found.
     *
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice  WHMCS payload resolver
     *                                                                    (with 'status'); null when unreachable → treated as "not paid", never guessed.
     * @return int[]
     */
    public function reconcile(Company $tenant, callable $fetchInvoice): array
    {
        $previousInbound = WhmcsPaymentSyncCache::inboundIds($tenant);

        $rows = PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->where('status', PendingWhmcsInvoice::STATUS_FILED)
            ->whereNotNull('invoice_id')
            ->whereNotNull('whmcs_invoice_id')
            ->with('invoice.paymentMethod')
            ->get();

        // Outbound is only meaningful for tenants who opted into pushing to WHMCS.
        $wantsOutbound = (bool) $tenant->whmcs_push_payments;

        $inbound = [];
        $outbound = [];

        foreach ($rows as $row) {
            try {
                if ($this->isInboundCandidate($row, $fetchInvoice)) {
                    $inbound[] = (int) $row->invoice_id;
                } elseif ($wantsOutbound && $this->isOutboundCandidate($row)) {
                    $outbound[] = (int) $row->invoice_id;
                }
            } catch (Throwable $e) {
                // Isolate a single bad row (malformed payload, unreachable
                // invoice) so it can't abort the whole tenant's detection.
                report($e);
            }
        }

        $inbound = array_values(array_unique($inbound));
        $outbound = array_values(array_unique($outbound));
        WhmcsPaymentSyncCache::put($tenant, $inbound, $outbound);

        // Bell-notify only items new since the last run — an operator shouldn't
        // be re-pinged for a row they've already seen.
        $fresh = array_values(array_diff($inbound, $previousInbound));
        if ($fresh !== []) {
            $this->notify($tenant, $fresh);
        }

        return $inbound;
    }

    /**
     * An inbound candidate = a filed, WHMCS-linked, live, credit-term
     * («επί πιστώσει») invoice with an OPEN balance that WHMCS now reports Paid.
     * Mirrors WhmcsPaymentSyncer's eligibility exactly (so what shows is what
     * the 1-click action would actually record).
     *
     * @param  callable(int): (array<string, mixed>|null)  $fetchInvoice
     */
    private function isInboundCandidate(PendingWhmcsInvoice $row, callable $fetchInvoice): bool
    {
        $invoice = $row->invoice;
        if ($invoice === null || ! $this->isLiveOpen($invoice)) {
            return false;
        }
        if ((int) ($invoice->paymentMethod?->due_days ?? 0) <= 0) {
            return false;   // cash-term is settled at issue — not a receivable
        }
        if ($this->balance->for($invoice)->balance <= 0.005) {
            return false;   // already settled locally
        }

        $payload = $fetchInvoice((int) $row->whmcs_invoice_id);

        return is_array($payload) && strcasecmp((string) ($payload['status'] ?? ''), 'Paid') === 0;
    }

    /**
     * An outbound candidate = a filed, WHMCS-linked, live, credit-term invoice
     * that is SETTLED locally by a REAL (non-inbound) ekdosi payment and has NOT
     * yet been pushed to WHMCS. Pure DB/PHP — no WHMCS call (the live status
     * check happens at push time). Mirrors WhmcsPaymentPusher's eligibility.
     */
    private function isOutboundCandidate(PendingWhmcsInvoice $row): bool
    {
        $invoice = $row->invoice;
        if ($invoice === null
            || ! $this->isLiveOpen($invoice)
            || $row->whmcs_payment_pushed_at !== null
            || (int) ($invoice->paymentMethod?->due_days ?? 0) <= 0) {
            return false;
        }
        if ($this->balance->for($invoice)->balance > 0.005) {
            return false;   // still open — not a settlement to push
        }

        // Anti-echo: needs a real ekdosi-origin payment, not just an inbound sync.
        return Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where('kind', 'payment')
            ->where(function ($q) {
                $q->whereNull('transaction_id')
                    ->orWhere('transaction_id', 'not like', 'whmcs-paid:%');
            })
            ->exists();
    }

    /** NOT cancelled locally, NOT cancelled at AADE, and not a credit note. */
    private function isLiveOpen(Invoice $invoice): bool
    {
        return $invoice->local_status !== 'cancelled'
            && $invoice->mydata_state !== 'CANCELLED'
            && ! $invoice->isCreditNote();
    }

    /**
     * @param  int[]  $invoiceIds
     */
    private function notify(Company $tenant, array $invoiceIds): void
    {
        try {
            $recipients = $tenant->users;
            if ($recipients->isEmpty()) {
                return;
            }

            $count = count($invoiceIds);
            Notification::make()
                ->title('Πληρωμές WHMCS προς καταγραφή')
                ->body($count === 1
                    ? 'Ένα ανοιχτό (επί πιστώσει) τιμολόγιο πληρώθηκε στο WHMCS — κλείσ’ το από τον «Συγχρονισμό πληρωμών».'
                    : $count.' ανοιχτά (επί πιστώσει) τιμολόγια πληρώθηκαν στο WHMCS — κλείσ’ τα από τον «Συγχρονισμό πληρωμών».')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->sendToDatabase($recipients);
        } catch (Throwable $e) {
            // Best-effort: a notification failure must never break detection.
            report($e);
        }
    }
}
