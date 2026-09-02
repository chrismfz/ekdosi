<?php

namespace App\Support\EInvoice;

use App\Models\Invoice;
use App\Support\Pdf\InvoiceBannerState;
use Illuminate\Support\Facades\Log;

/**
 * The ONE decision of whether a document currently presents as a live provider
 * (ΥΠΑΗΕΣ) filing, and — if so — the exact evidence to show (PROV-003).
 *
 * Both the printed representation (`InvoicePdfRenderer`) and the invoice page
 * («myDATA / Πάροχος») resolve through here, so screen and print apply the SAME
 * gates and never diverge. Earlier the two carried the gates separately and drifted
 * on cancelled and missing-licence documents.
 *
 * All gates, in order — a null result means «no provider block»:
 *   1. NOT cancelled — locally OR at AADE (InvoiceBannerState 'cancelled', which
 *      also covers the local-cancel / cancel-pending-myDATA case). A voided
 *      document must never assert a live certified provider issuance.
 *   2. A CURRENT provider filing — a PROVIDER_INSERT mark that IS the live mirror
 *      MARK, and the invoice is VALID at myDATA (Invoice::latestProviderMark()).
 *   3. Its identity resolves WITH a ΥΠΑΗΕΣ licence — the legally-critical field
 *      (A.1112/2025). A block asserting provider issuance without the licence is
 *      worse than none, so the whole block is suppressed and the gap logged.
 *
 * Identity is the FROZEN per-document snapshot when present, else current config
 * (ProviderIdentity::forMark). Returns the array shape the PDF blade consumes.
 *
 * @return array{commercial_name: string, legal_name: string, site: string, aade_code: string, licence_no: string, mark: string, uid: string, auth_code: string}|null
 */
final class ProviderEvidence
{
    public static function resolve(Invoice $invoice): ?array
    {
        if (InvoiceBannerState::for($invoice)['kind'] === 'cancelled') {
            return null;
        }

        $mark = $invoice->latestProviderMark();
        if ($mark === null) {
            return null;
        }

        $identity = ProviderIdentity::forMark($mark);
        if ($identity === null || $identity->licenceNo === '') {
            Log::warning('PROV-003: missing provider_identity/licence — provider evidence suppressed (page + PDF).', [
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->getKey(),
                'provider_key' => $mark->provider_key,
            ]);

            return null;
        }

        return [
            // Never blank: forMark()/forKey() fall the name back to the key, and this
            // adds the provider_key as the last resort.
            'commercial_name' => $identity->commercialName !== '' ? $identity->commercialName : (string) $mark->provider_key,
            'legal_name' => $identity->legalName,
            'site' => $identity->site,
            'aade_code' => $identity->aadeCode,
            'licence_no' => $identity->licenceNo,
            'mark' => (string) $mark->mark,
            'uid' => (string) ($mark->uid ?? ''),
            'auth_code' => (string) ($mark->authentication_code ?? ''),
        ];
    }
}
