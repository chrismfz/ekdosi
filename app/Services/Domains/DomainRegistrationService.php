<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * THE one path to a registrar registration (Πυλώνας A / A3b) — the View
 * «Καταχώρηση στον registrar» button goes through here; nothing else may call
 * DomainRegistrar::register(). Registration is OPERATOR-GATED (§6.1 register =
 * post-payment + §7.1 «Automatic Registration = No» is the existing practice)
 * — the operator decides when the money side is settled enough to fire.
 *
 * Adopt-on-retry (§6.6, the same discipline as the renewal):
 *   1. checkAvailability FIRST (a transport failure aborts — never register
 *      blind).
 *   2. NOT available → is it already in OUR account (a retry after a timeout
 *      that charged, or registered via the panel)? → ADOPT the registrar
 *      truth, log 'adopted', ZERO register calls. Not ours → refuse loudly
 *      («κατειλημμένο από τρίτο»).
 *   3. Available → register (ensuring contact handles), apply the truth,
 *      persist the handles, stamp registered_at.
 * Every attempt — ok / adopted / failed (refusals included) — lands in
 * domain_registrar_logs.
 */
class DomainRegistrationService
{
    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    public function register(Domain $domain, int $years): DomainRegistrarLog
    {
        $connection = $domain->effectiveRegistrarConnection();

        $log = fn (string $status, ?array $response, ?string $error) => DomainRegistrarLog::create([
            'company_id' => $domain->company_id,
            'domain_id' => $domain->id,
            'registrar_connection_id' => $connection?->id,
            'action' => 'register',
            'status' => $status,
            'request' => ['fqdn' => $domain->fqdn, 'years' => $years],
            'response' => $response,
            'error' => $error,
        ]);
        $refuse = function (string $message) use ($log): never {
            $log(DomainRegistrarLog::STATUS_FAILED, null, $message);

            throw new RuntimeException($message);
        };

        // Register fires ONLY from the pending state: an active/expired/
        // terminal name is never re-registered (that's renew/restore/transfer
        // territory — a register on a live name would create a second charge
        // or a registrar error, never what the operator meant).
        if ($domain->trashed()) {
            $refuse('Το domain είναι διαγραμμένο — δεν καταχωρείται.');
        }
        if ($domain->status !== DomainStatus::PendingRegister) {
            $refuse("Το {$domain->fqdn} δεν είναι σε κατάσταση «Εκκρεμεί καταχώρηση» — η καταχώρηση αφορά μόνο νέα domains.");
        }
        if ($connection === null || ! $connection->isUsable()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Καμία ενεργή σύνδεση registrar.');

            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε ενεργή σύνδεση registrar.');
        }
        $adapter = $this->factory->for($connection);
        if ($adapter->key() === 'manual') {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Ο registrar είναι «manual».');

            throw new DomainRegistrarNotConfigured('Ο registrar είναι «manual» — καταχωρήστε στο portal του registrar και ενημερώστε το domain.');
        }
        $domain->loadMissing(['contacts', 'nameservers']);
        $registrant = $domain->contacts->firstWhere('type', 'registrant');
        if ($registrant === null || trim((string) $registrant->email) === '') {
            $refuse("Το {$domain->fqdn} χρειάζεται επαφή registrant με email πριν την καταχώρηση (καρτέλα «Επαφές»).");
        }
        if ($domain->nameservers->count() < 2) {
            $refuse("Το {$domain->fqdn} χρειάζεται τουλάχιστον 2 nameservers πριν την καταχώρηση (καρτέλα «Nameservers»).");
        }

        $lock = Cache::lock('domains:register:'.$domain->id, 300);
        if (! $lock->get()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Κλειδωμένο — άλλη καταχώρηση σε εξέλιξη.');

            throw new DomainRenewalInProgress(
                "Άλλη καταχώρηση του {$domain->fqdn} είναι ήδη σε εξέλιξη — ΜΗΝ ξαναζητήσετε· δείτε το ιστορικό API σε λίγο."
            );
        }

        try {
            $credentials = $this->factory->credentialsFor($connection);

            // 1. Availability FIRST — the read the whole guard rests on.
            try {
                $availability = $adapter->checkAvailability($domain->fqdn, $credentials);
            } catch (\Throwable $e) {
                $log(DomainRegistrarLog::STATUS_FAILED, null, 'Προ-έλεγχος διαθεσιμότητας απέτυχε: '.$e->getMessage());

                throw new RuntimeException("Η καταχώρηση του {$domain->fqdn} ΔΕΝ εκτελέστηκε — ο προ-έλεγχος διαθεσιμότητας απέτυχε: ".$e->getMessage());
            }

            // 2. Taken: ours (retry-after-charge / panel) → ADOPT; else refuse.
            if (! $availability->available) {
                try {
                    $result = $adapter->syncDomain($domain, $credentials);
                } catch (\Throwable) {
                    $refuse("Το {$domain->fqdn} είναι κατειλημμένο από τρίτο — δεν καταχωρείται. (Δείτε διαθεσιμότητα/WHOIS.)");
                }
                $this->sync->apply($domain, $result);
                $this->persistHandles($domain, $result->contactHandles);

                return $log(DomainRegistrarLog::STATUS_ADOPTED, [
                    'registrar_expiry' => $result->expiresAt,
                    'raw_status' => $result->rawStatus,
                ], null);
            }

            // 3. Free → register.
            try {
                $result = $adapter->register($domain, $years, $credentials);
            } catch (\Throwable $e) {
                $log(DomainRegistrarLog::STATUS_FAILED, null, $e->getMessage());

                throw $e;
            }
            $this->sync->apply($domain, $result);
            $this->persistHandles($domain, $result->contactHandles);
            $updates = [];
            if ($domain->registered_at === null) {
                $updates['registered_at'] = Carbon::today()->toDateString();
            }
            // The registrar may answer without a mapped status (async REQ
            // flows) — a still-pending row keeps its state until the sync
            // promotes it; an ACT answer was already applied above.
            if ($updates !== []) {
                $domain->forceFill($updates)->save();
            }

            return $log(DomainRegistrarLog::STATUS_OK, [
                'registrar_expiry' => $result->expiresAt,
                'registrar_domain_id' => $result->registrarDomainId,
                'raw_status' => $result->rawStatus,
            ], null);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning('domains.register.lock_release_failed', [
                    'domain_id' => $domain->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Persist the ensured registrar handles onto the domain's contact rows —
     * the next register/setContacts must reuse them, never re-create.
     *
     * @param  array<string, string>  $handles
     */
    private function persistHandles(Domain $domain, array $handles): void
    {
        if ($handles === []) {
            return;
        }
        foreach ($domain->contacts as $contact) {
            $handle = $handles[$contact->type] ?? null;
            if ($handle !== null && $contact->registrar_contact_handle !== $handle) {
                $contact->forceFill(['registrar_contact_handle' => $handle])->save();
            }
        }
    }
}
