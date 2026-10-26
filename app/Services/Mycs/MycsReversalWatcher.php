<?php

namespace App\Services\Mycs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Support\Hr\ErganiStaff;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * K2 of the MyCS Bridge (docs/bridges-connectors.md §12): an issued document stands whatever happens in MyCS
 * («αν κόπηκε, πάει κόπηκε») — but staff must know when the sale behind it is reversed there, to issue a credit note:
 * MyCS refunded it (a gateway refund, a chargeback, staff), cancelled it, or it is unpaid again (its payment deleted).
 *
 * Read only, a few rows a run: the company's issued rows in turn (a cursor over their ids), one `op=invoice` each — MyCS
 * allows 120 requests a minute (about a day for a full round of a few thousand rows). Each reversal rings the staff
 * bell once — the notification is the record; nothing is changed here.
 */
final class MycsReversalWatcher
{
    public const TITLE = 'Χρειάζεται πιστωτικό (MyCS)';

    public const KIND = 'mycs_reversal';

    /** @return array{checked: int, flagged: int} */
    public function run(Company $tenant, int $limit = 50): array
    {
        if (! $tenant->usesMycs()) {
            return ['checked' => 0, 'flagged' => 0];
        }
        $client = app(WhmcsBridgeClientFactory::class)->forSource($tenant);
        $cursorKey = 'mycs-reversals-cursor:'.$tenant->id;
        $after = (int) Cache::get($cursorKey, 0);
        $rows = $this->rows($tenant, $after, $limit);
        if ($rows->isEmpty() && $after > 0) {
            $rows = $this->rows($tenant, 0, $limit); // round again
        }
        $checked = 0;
        $flagged = 0;
        $read = 0;
        foreach ($rows as $row) {
            $checked++;
            try {
                $payload = $client->fetchInvoice((int) $row->whmcs_invoice_id);
            } catch (Throwable $e) {
                Log::info('MyCS reversal check: invoice not read (next round)', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

                continue;
            }
            $read++;
            // The same number for another client (WHMCS and MyCS share the id space): not this document's sale.
            if (! is_array($payload) || (int) ($payload['userid'] ?? 0) !== (int) $row->whmcs_userid) {
                continue;
            }
            $documents = $this->documents($row);
            $reason = $this->reversal((string) ($payload['status'] ?? ''), $row, $documents);
            if ($reason === null || $documents->isEmpty()) {
                continue;
            }
            if ($this->notify($tenant, $row, $documents, $reason)) {
                $flagged++;
            }
        }
        // MyCS down for the whole run (nothing read): the same rows next run, not a round later (review of #702).
        if ($rows->isNotEmpty() && $read > 0) {
            Cache::forever($cursorKey, (int) $rows->last()->id);
        }

        return ['checked' => $checked, 'flagged' => $flagged];
    }

    /** @return Collection<int, PendingWhmcsInvoice> */
    private function rows(Company $tenant, int $after, int $limit)
    {
        return PendingWhmcsInvoice::query()
            ->where('company_id', $tenant->id)
            ->whereIn('status', [PendingWhmcsInvoice::STATUS_FILED, PendingWhmcsInvoice::STATUS_SPLIT])
            ->whereNotNull('whmcs_invoice_id')
            // Its client known, and not another client's number (review of #702).
            ->whereNotNull('whmcs_userid')
            ->where('source_conflict', false)
            // A split row has no filed_at (its documents are issued one by one): by its last change (review of #702).
            ->where(fn ($q) => $q->where('filed_at', '>=', now()->subDays(400))
                ->orWhere(fn ($w) => $w->where('status', PendingWhmcsInvoice::STATUS_SPLIT)->where('updated_at', '>=', now()->subDays(400))))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /**
     * What happened to the sale in MyCS, or null: refunded, cancelled, or unpaid again after it was issued as paid, or
     * after a payment from MyCS closed it here (the inbound sync's «whmcs-paid:» one; review of #702).
     *
     * @param  Collection<int, Invoice>  $documents
     */
    private function reversal(string $status, PendingWhmcsInvoice $row, $documents): ?string
    {
        return match (strtolower($status)) {
            'refunded' => 'έγινε επιστροφή χρημάτων (refund ή chargeback)',
            'cancelled' => 'ακυρώθηκε',
            'unpaid' => strcasecmp((string) ($row->payload['status'] ?? ''), 'Paid') === 0
                || Payment::query()->whereIn('invoice_id', $documents->pluck('id'))->where('transaction_id', 'like', 'whmcs-paid:%')->exists()
                    ? 'ξαναέγινε απλήρωτο (η πληρωμή του διαγράφηκε)' : null,
            default => null,
        };
    }

    /**
     * The row's live documents: issued (not drafts), not cancelled here or at AADE, not fully credited. A split row has
     * several (review of #702: all are named).
     *
     * @return Collection<int, Invoice>
     */
    private function documents(PendingWhmcsInvoice $row)
    {
        return Invoice::query()
            ->where('company_id', $row->company_id)
            ->where(fn ($q) => $q->where('whmcs_pending_id', $row->id)->orWhere('id', $row->invoice_id))
            ->where('local_status', '!=', 'cancelled')
            ->whereNotNull('mydata_mark')
            ->orderBy('id')
            ->get()
            ->reject(fn (Invoice $invoice) => $invoice->mydata_state === 'CANCELLED' || $invoice->isFullyCredited())
            ->values();
    }

    /**
     * Once per invoice and reversal, ever: the notification itself is the record (it survives a cache clear; review of
     * #702), sent only when there is someone to tell.
     *
     * @param  Collection<int, Invoice>  $documents
     */
    private function notify(Company $tenant, PendingWhmcsInvoice $row, $documents, string $reason): bool
    {
        $key = md5($reason);
        $told = DatabaseNotification::query()
            ->where('data->viewData->kind', self::KIND)
            ->where('data->viewData->company_id', (string) $tenant->id)
            ->where('data->viewData->whmcs_invoice_id', (string) $row->whmcs_invoice_id)
            ->where('data->viewData->reason', $key)
            ->exists();
        if ($told) {
            return false;
        }
        try {
            $recipients = ErganiStaff::staffRecipients($tenant);
            if ($recipients->isEmpty()) {
                return false; // nobody to tell yet: the next round tries again
            }
            $codes = $documents->pluck('invcode')->implode(', ');
            Notification::make()
                ->title(self::TITLE)
                ->body('Το MyCS #'.$row->whmcs_invoice_id.' '.$reason.' · '.($documents->count() > 1 ? 'τα παραστατικά ' : 'το παραστατικό ')
                    .$codes.' ισχύει ακόμα — κόψε πιστωτικό αν χρειάζεται.')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->viewData(['kind' => self::KIND, 'company_id' => (string) $tenant->id, 'whmcs_invoice_id' => (string) $row->whmcs_invoice_id, 'reason' => $key])
                ->sendToDatabase($recipients);

            return true;
        } catch (Throwable $e) {
            Log::warning('MyCS reversal notification failed (next round tries again)', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
