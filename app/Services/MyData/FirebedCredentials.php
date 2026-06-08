<?php

namespace App\Services\MyData;

use App\Enums\MyDataMode;
use App\Models\Company;
use Firebed\AadeMyData\Http\MyDataRequest;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;

/**
 * Single place that primes firebed's static credential state for a tenant.
 *
 * Shared by SalesReconciler (sales reconciliation) and the read-only fetch
 * commands (e.g. mydata:fetch-docs) so the provider / mode / credential
 * guards — and the friendly "APP_KEY rotated" decrypt error — live in ONE
 * place instead of being copy-pasted (and drifting) per caller.
 */
class FirebedCredentials
{
    /**
     * @param  callable|\GuzzleHttp\Handler\MockHandler|null  $handler
     *         Optional Guzzle handler for tests. Passing null RESETS
     *         firebed's leftover static handler so a MockHandler from an
     *         earlier test can't intercept a later real call.
     *
     * @throws RuntimeException  with an operator-facing Greek message for
     *         every misconfiguration (non-GR tenant, Off mode, missing or
     *         undecryptable credentials).
     */
    public static function init(Company $tenant, callable|object|null $handler = null): void
    {
        // READ access: both direct-myDATA (gr-mydata) AND provider (gr-provider)
        // tenants read their own picture from AADE — the provider only changes
        // who SUBMITS, not who can read the tenant's own ΑΦΜ documents back.
        // (Submission stays gr-mydata-only via the EInvoiceSubmitter factory +
        // MyDataSubmitter, which prime firebed's credentials directly — not here.)
        // mydataReadMode() resolves the right environment for either channel:
        // for gr-mydata it's the submission mode; for a provider (whose
        // mydata_mode is 'off') it's the populated credential slot.
        if (! in_array($tenant->einvoice_provider, ['gr-mydata', 'gr-provider'], true)) {
            throw new RuntimeException(
                'Η ανάγνωση από myDATA είναι διαθέσιμη μόνο για ελληνικούς μισθωτές (gr-mydata ή πάροχος).'
            );
        }

        $mode = $tenant->mydataReadMode();

        if ($mode === null) {
            // gr-mydata with mode Off, or a provider with no read credentials.
            throw new RuntimeException(
                $tenant->einvoice_provider === 'gr-mydata'
                    ? 'Η λειτουργία myDATA είναι απενεργοποιημένη (Off) για αυτόν τον μισθωτή.'
                    : 'Δεν έχουν οριστεί διαπιστευτήρια ανάγνωσης myDATA για αυτόν τον πάροχο-μισθωτή.'
            );
        }

        try {
            // Credentials for the resolved READ environment (sandbox vs
            // production slot); the subscription key is decrypted by cast.
            [$aadeId, $subKey] = $tenant->mydataCredentials($mode);
        } catch (DecryptException) {
            throw new RuntimeException(
                'Αδυναμία αποκρυπτογράφησης των διαπιστευτηρίων myDATA (πιθανή εναλλαγή APP_KEY).'
            );
        }

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'Δεν έχουν οριστεί διαπιστευτήρια myDATA για αυτόν τον μισθωτή '.
                'στο περιβάλλον '.$mode->value.'.'
            );
        }

        $env = $mode === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);
        MyDataRequest::setHandler($handler);
    }
}
