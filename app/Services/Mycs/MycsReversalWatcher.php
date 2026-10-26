<?php

namespace App\Services\Mycs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Support\Hr\ErganiStaff;
use Filament\Notifications\Notification;
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
 * allows 120 requests a minute. Each reversal rings the staff bell once; nothing is changed here.
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
        foreach ($rows as $row) {
            $checked++;
            try {
                $payload = $client->fetchInvoice((int) $row->whmcs_invoice_id);
            } catch (Throwable $e) {
                Log::info('MyCS reversal check: invoice not read (next round)', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

                continue;
            }
            // The same number for another client (WHMCS and MyCS share the id space): not this document's sale.
            if (! is_array($payload) || ($row->whmcs_userid !== null && (int) ($payload['userid'] ?? 0) !== (int) $row->whmcs_userid)) {
                continue;
            }
            $reason = $this->reversal((string) ($payload['status'] ?? ''), $row);
            $invoice = $reason === null ? null : $this->document($row);
            if ($invoice === null || $invoice->local_status === 'cancelled' || $invoice->mydata_state === 'CANCELLED' || $invoice->isFullyCredited()) {
                continue;
            }
            if ($this->notify($tenant, $row, $invoice, $reason)) {
                $flagged++;
            }
        }
        if ($rows->isNotEmpty()) {
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
            ->where('filed_at', '>=', now()->subDays(400))
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();
    }

    /** What happened to the sale in MyCS, or null: refunded, cancelled, or unpaid again after it was issued as paid. */
    private function reversal(string $status, PendingWhmcsInvoice $row): ?string
    {
        return match (strtolower($status)) {
            'refunded' => 'έγινε επιστροφή χρημάτων (refund ή chargeback)',
            'cancelled' => 'ακυρώθηκε',
            'unpaid' => strcasecmp((string) ($row->payload['status'] ?? ''), 'Paid') === 0 ? 'ξαναέγινε απλήρωτο (η πληρωμή του διαγράφηκε)' : null,
            default => null,
        };
    }

    /** The row's live document (a split row's: its latest; each is checked by the operator anyway). */
    private function document(PendingWhmcsInvoice $row): ?Invoice
    {
        return Invoice::query()
            ->where('company_id', $row->company_id)
            ->where(fn ($q) => $q->where('whmcs_pending_id', $row->id)->orWhere('id', $row->invoice_id))
            ->where('local_status', '!=', 'cancelled')
            ->orderByDesc('id')
            ->first();
    }

    /** Once per invoice and reversal (a year), to the company's staff. */
    private function notify(Company $tenant, PendingWhmcsInvoice $row, Invoice $invoice, string $reason): bool
    {
        if (! Cache::add('mycs-reversal:'.$tenant->id.':'.$row->whmcs_invoice_id.':'.md5($reason), true, now()->addYear())) {
            return false;
        }
        try {
            $recipients = ErganiStaff::staffRecipients($tenant);
            if ($recipients->isEmpty()) {
                return false;
            }
            Notification::make()
                ->title(self::TITLE)
                ->body('Το MyCS #'.$row->whmcs_invoice_id.' '.$reason.' · το παραστατικό '.$invoice->invcode.' ισχύει ακόμα — κόψε πιστωτικό αν χρειάζεται.')
                ->icon('heroicon-o-receipt-refund')
                ->color('warning')
                ->viewData(['kind' => self::KIND, 'company_id' => (string) $tenant->id, 'whmcs_invoice_id' => (string) $row->whmcs_invoice_id])
                ->sendToDatabase($recipients);

            return true;
        } catch (Throwable $e) {
            Log::warning('MyCS reversal notification failed', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
