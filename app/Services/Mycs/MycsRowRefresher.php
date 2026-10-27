<?php

namespace App\Services\Mycs;

use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Support\Billing\MycsPayload;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read a MyCS row again right before a document is made from it (K3, cross review of mycs#478 / ekdosi#703; owner
 * 2026-10-27: «κάτι σαν refresh/reload»). The inbox holds a snapshot; MyCS staff may have changed the invoice since
 * (a line, a credit note, its status, its client) or withdrawn their «Send to Ekdosi». A document issued from the old
 * snapshot would be for the wrong amount, and a payment on credit would not close it in MyCS.
 *
 * Unchanged: null, go on. Changed: the row takes MyCS's invoice as it is now and is held for a human, with why. MyCS
 * not answering: nothing is issued now (the reason says so); the row stays as it was. An issued document stays what it
 * is («αν κόπηκε, πάει κόπηκε»): this is only before issuing.
 */
final class MycsRowRefresher
{
    /** @return string|null why no document is made from it now, or null */
    public function check(PendingWhmcsInvoice $row): ?string
    {
        if (! $row->isFromMycs() || $row->company === null) {
            return null;
        }
        try {
            $fresh = app(WhmcsBridgeClientFactory::class)->forSource($row->company)->fetchInvoice((int) $row->whmcs_invoice_id, true);
        } catch (Throwable $e) {
            Log::info('MyCS row not read again before issuing', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

            return 'Η MyCS δεν απάντησε: δεν εκδόθηκε τώρα, ξαναδοκίμασε σε λίγο.';
        }
        $old = is_array($row->payload) ? $row->payload : [];
        $why = $fresh === null ? 'δεν υπάρχει πια στη MyCS' : $this->why($old, $fresh);
        if ($why === null) {
            return null;
        }
        if ($fresh !== null) {
            app(WhmcsInvoiceIngestor::class)->ingest($row->company, $fresh); // the snapshot as MyCS has it now
        }
        $row->refresh();
        $note = 'Άλλαξε στη MyCS από τότε που ήρθε ('.$why.'): έλεγξέ το πριν την έκδοση.';
        $row->update([
            'status' => PendingWhmcsInvoice::STATUS_HELD,
            'hold_reason' => $note,
            'notes' => trim(((string) $row->notes).' '.$note),
        ]);

        return $note;
    }

    /**
     * What changed between the snapshot and MyCS now, in words, or null.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function why(array $old, array $new): ?string
    {
        $changes = [];
        if ((string) ($old['mycs']['fingerprint'] ?? '') !== (string) ($new['mycs']['fingerprint'] ?? '')) {
            $changes[] = 'γραμμές, ποσά ή σημειώματα';
        }
        if (strcasecmp((string) ($old['status'] ?? ''), (string) ($new['status'] ?? '')) !== 0) {
            $changes[] = 'κατάσταση '.($old['status'] ?? '—').' → '.($new['status'] ?? '—');
        }
        if ((int) ($old['userid'] ?? 0) !== (int) ($new['userid'] ?? 0)) {
            $changes[] = 'πελάτης';
        }
        if (MycsPayload::requestedAt($old) !== MycsPayload::requestedAt($new)) {
            $changes[] = MycsPayload::requested($new) ? 'νέο «Send to Ekdosi»' : 'το «Send to Ekdosi» αποσύρθηκε';
        }
        if (MycsPayload::ignored($old) !== MycsPayload::ignored($new)) {
            $changes[] = '«χωρίς τιμολόγηση»';
        }

        return $changes === [] ? null : implode(', ', $changes);
    }
}
