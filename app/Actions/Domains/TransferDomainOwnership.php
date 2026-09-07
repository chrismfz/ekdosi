<?php

namespace App\Actions\Domains;

use App\Enums\DomainStatus;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Invoice;
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

            // Same money-direction guard as the assign action: a terminal
            // domain's Active contract must not be moved onto (and bill) a
            // new customer for a name the tenant no longer holds.
            if ($locked->status instanceof DomainStatus && $locked->status->isTerminal()) {
                throw new RuntimeException(
                    'Το domain είναι σε κατάσταση «'.$locked->status->getLabel().'» — δεν μεταφέρεται σε νέο πελάτη.'
                );
            }

            // A staged-but-unissued draft renewal still carries the OLD customer
            // (party snapshot included) and would block staging for the new one —
            // worse, issuing it bills the wrong customer on a legal document.
            // The operator resolves the draft first; we never auto-cancel drafts.
            if ($locked->service_contract_id !== null) {
                $openDraft = Invoice::query()
                    ->where('company_id', $locked->company_id)
                    ->where('service_contract_id', $locked->service_contract_id)
                    ->where('local_status', 'draft')
                    ->exists();
                if ($openDraft) {
                    throw new RuntimeException(
                        'Υπάρχει πρόχειρο παραστατικό ανανέωσης για το domain στον τρέχοντα πελάτη — εκδώστε ή ακυρώστε το πρώτα.'
                    );
                }
            }

            $locked->update(['customer_id' => $newCustomer->id]);
            $locked->serviceContract?->update(['customer_id' => $newCustomer->id]);

            return $locked->refresh();
        });
    }
}
