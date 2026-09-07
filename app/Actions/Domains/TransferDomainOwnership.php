<?php

namespace App\Actions\Domains;

use App\Models\Customer;
use App\Models\Domain;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * «Μεταφορά ιδιοκτησίας» (Πυλώνας A / A1b) — docs/domains/README.md §9: move an
 * ASSIGNED domain (and its 1:1 ServiceContract, so future renewals bill the new
 * customer) to another customer. Historical invoices stay with the old customer
 * (legal record — invoices reference their own customer, untouched here).
 * ≠ registrar transfer (EPP to another registrar) — deliberately separate
 * names. Audit-logged via the models' TracksActivity.
 */
class TransferDomainOwnership
{
    public function __invoke(Domain $domain, Customer $newCustomer): Domain
    {
        if ($domain->company_id !== $newCustomer->company_id) {
            throw new RuntimeException('Ο πελάτης ανήκει σε άλλη εταιρεία.'); // tenant-safety belt
        }

        return DB::transaction(function () use ($domain, $newCustomer): Domain {
            $locked = Domain::query()->whereKey($domain->id)->lockForUpdate()->first();
            if ($locked === null || $locked->customer_id === null) {
                throw new RuntimeException('Το domain δεν έχει πελάτη — χρησιμοποιήστε την «Ανάθεση σε πελάτη».');
            }
            if ($locked->customer_id === $newCustomer->id) {
                throw new RuntimeException('Το domain ανήκει ήδη σε αυτόν τον πελάτη.');
            }

            $locked->update(['customer_id' => $newCustomer->id]);
            $locked->serviceContract?->update(['customer_id' => $newCustomer->id]);

            return $locked->refresh();
        });
    }
}
