<?php

namespace App\Services\Mycs;

use App\Models\Company;
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
    public const NOT_ANSWERING = 'Η MyCS δεν απάντησε: δεν εκδόθηκε τώρα, ξαναδοκίμασε σε λίγο.';

    /** @return string|null why no document is made from it now, or null */
    public function check(PendingWhmcsInvoice $row): ?string
    {
        // The company as stored, not as a list loaded it (its columns trimmed: R2-0 of #703). A MyCS company's every row,
        // one staged from WHMCS before the cut-over too (R2-6).
        $company = Company::query()->find($row->company_id);
        if ($company === null || ! (bool) $company->mycs_enabled) {
            return null;
        }
        try {
            $fresh = app(WhmcsBridgeClientFactory::class)->forSource($company)->fetchInvoice((int) $row->whmcs_invoice_id, true);
        } catch (Throwable $e) {
            if (! str_contains($e->getMessage(), 'invoice_not_found')) {
                Log::info('MyCS row not read again before issuing', ['pending_id' => $row->id, 'error' => $e->getMessage()]);

                return self::NOT_ANSWERING;
            }
            $fresh = null; // MyCS answered: no such invoice (R2-5)
        }
        $old = is_array($row->payload) ? $row->payload : [];
        $why = $fresh === null ? 'δεν υπάρχει πια στη MyCS' : $this->why($old, $fresh);
        if ($why === null) {
            return null;
        }
        if ($fresh !== null) {
            app(WhmcsInvoiceIngestor::class)->ingest($company, $fresh); // the snapshot (and the customer's flags) as MyCS has it now
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
     * What changed between the snapshot and MyCS now, in words, or null. Everything MyCS sends counts (lines, routing to
     * third parties, the recipient's ΑΦΜ and document type, the account's flags: R2-2..R2-4 of #703), but what Ekdosi
     * itself wrote back (`mycs.documents`).
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function why(array $old, array $new): ?string
    {
        if (self::normalised($old) === self::normalised($new)) {
            return null;
        }
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
        if (($old['mycs']['immediate_invoicing'] ?? null) !== ($new['mycs']['immediate_invoicing'] ?? null)) {
            $changes[] = 'άμεση τιμολόγηση';
        }
        if (($old['mycs']['recipient'] ?? null) != ($new['mycs']['recipient'] ?? null)) {
            $changes[] = 'στοιχεία παραλήπτη (ΑΦΜ, παραστατικό)';
        }
        if (($old['mycs']['routing_hash'] ?? null) !== MycsPayload::routingHash($new['third_party'] ?? null)) {
            $changes[] = 'αποδέκτες γραμμών';
        }

        return $changes === [] ? 'άλλα στοιχεία' : implode(', ', $changes);
    }

    /** @param  array<string, mixed>  $payload */
    private static function normalised(array $payload): string
    {
        // As the ingestor stores it: the routing as its hash (MyCS sends it, the snapshot keeps only the hash).
        if (array_key_exists('third_party', $payload)) {
            $payload['mycs']['routing_hash'] = MycsPayload::routingHash($payload['third_party']);
            unset($payload['third_party']);
        }
        unset($payload['mycs']['documents']);
        $sort = function (array &$value) use (&$sort): void {
            ksort($value);
            foreach ($value as &$child) {
                if (is_array($child)) {
                    $sort($child);
                }
            }
        };
        $sort($payload);

        return (string) json_encode($payload);
    }
}
