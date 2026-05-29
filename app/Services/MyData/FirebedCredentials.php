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
        if ($tenant->einvoice_provider !== 'gr-mydata') {
            throw new RuntimeException(
                'Η λειτουργία myDATA είναι διαθέσιμη μόνο για ελληνικούς (gr-mydata) μισθωτές.'
            );
        }

        if ($tenant->mydata_mode_enum === MyDataMode::Off) {
            throw new RuntimeException(
                'Η λειτουργία myDATA είναι απενεργοποιημένη (Off) για αυτόν τον μισθωτή.'
            );
        }

        try {
            // Credentials for the tenant's CURRENT mode (sandbox vs
            // production slot); the subscription key is decrypted by cast.
            [$aadeId, $subKey] = $tenant->mydataCredentials();
        } catch (DecryptException) {
            throw new RuntimeException(
                'Αδυναμία αποκρυπτογράφησης των διαπιστευτηρίων myDATA (πιθανή εναλλαγή APP_KEY).'
            );
        }

        if (empty($aadeId) || empty($subKey)) {
            throw new RuntimeException(
                'Δεν έχουν οριστεί διαπιστευτήρια myDATA για αυτόν τον μισθωτή '.
                'στο περιβάλλον '.$tenant->mydata_mode_enum->value.'.'
            );
        }

        $env = $tenant->mydata_mode_enum === MyDataMode::Production ? 'prod' : 'dev';

        MyDataRequest::init($aadeId, $subKey, $env);
        MyDataRequest::setHandler($handler);
    }
}
