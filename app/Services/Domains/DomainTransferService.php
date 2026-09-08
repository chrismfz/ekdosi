<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Models\Scopes\CompanyScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * THE one path to registrar transfers (Πυλώνας A / A3c, §6.3) — the View
 * «Μεταφορά στον registrar» (inbound) and «Κωδικός EPP» (outbound aid) go
 * through here; nothing else may call DomainRegistrar::transferIn()/
 * getEppCode().
 *
 * INBOUND is async by nature: this starts the transfer (REAL MONEY — gTLD
 * transfers charge a renewal year) and the row stays PendingTransfer; the
 * nightly sync drives the §6.3 machine (completed → Active + expiry, failed
 * (FAI) → sync_error ⚠ for the operator, empty → keep polling). Adopt-on-
 * retry: a prior attempt that reached the registrar (id / any transfer log)
 * probes the account first — an in-progress or completed transfer is adopted,
 * never re-paid. OUTBOUND stays operator-gated v1: the EPP code retrieval is
 * audit-logged WITHOUT the code (it enables the domain's departure).
 */
class DomainTransferService
{
    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    public function transferIn(Domain $domain, string $authCode): DomainRegistrarLog
    {
        $connection = $domain->effectiveRegistrarConnection();

        $log = fn (string $status, ?array $response, ?string $error) => DomainRegistrarLog::create([
            'company_id' => $domain->company_id,
            'domain_id' => $domain->id,
            'registrar_connection_id' => $connection?->id,
            'action' => 'transfer_in',
            'status' => $status,
            // NEVER the auth code — it is a bearer credential for the name.
            'request' => ['fqdn' => $domain->fqdn],
            'response' => $response,
            'error' => $error,
        ]);
        $refuse = function (string $message) use ($log): never {
            $log(DomainRegistrarLog::STATUS_FAILED, null, $message);

            throw new RuntimeException($message);
        };

        if ($domain->trashed()) {
            $refuse('Το domain είναι διαγραμμένο — δεν μεταφέρεται.');
        }
        if ($domain->status !== DomainStatus::PendingTransfer) {
            $refuse("Το {$domain->fqdn} δεν είναι σε κατάσταση «Εκκρεμεί μεταφορά» — βάλτε το πρώτα σε αυτήν (νέα εισερχόμενη μεταφορά).");
        }
        if ($connection === null || ! $connection->isUsable()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Καμία ενεργή σύνδεση registrar.');

            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε ενεργή σύνδεση registrar.');
        }
        $adapter = $this->factory->for($connection);
        if ($adapter->key() === 'manual') {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Ο registrar είναι «manual».');

            throw new DomainRegistrarNotConfigured('Ο registrar είναι «manual» — κάντε τη μεταφορά στο portal του registrar.');
        }
        $domain->loadMissing(['contacts', 'nameservers']);
        $registrant = $domain->contacts->firstWhere('type', 'registrant');
        if ($registrant === null || trim((string) $registrant->email) === '') {
            $refuse("Το {$domain->fqdn} χρειάζεται επαφή registrant με email πριν τη μεταφορά (καρτέλα «Επαφές»).");
        }
        // Shared reseller creds serve ΟΛΕΣ τις εταιρείες: a name another
        // TENANT tracks at a registrar must never be transferred/adopted onto
        // this tenant's row (the DomainRegistrationService guard, same P0
        // class). Deliberate all-tenant sweep (CLAUDE.md CLI rule, option c).
        $claimedElsewhere = Domain::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->withTrashed()
            ->where('fqdn', $domain->fqdn)
            ->where('company_id', '!=', $domain->company_id)
            ->whereNotNull('registrar_domain_id')
            ->where('registrar_domain_id', '!=', '')
            ->exists();
        if ($claimedElsewhere) {
            $refuse("Το {$domain->fqdn} είναι ήδη καταχωρημένο από ΑΛΛΗ εταιρεία στον ίδιο λογαριασμό registrar — δεν μεταφέρεται/υιοθετείται από εδώ.");
        }

        $lock = Cache::lock('domains:transfer:'.$domain->id, 300);
        if (! $lock->get()) {
            $log(DomainRegistrarLog::STATUS_FAILED, null, 'Κλειδωμένο — άλλη μεταφορά σε εξέλιξη.');

            throw new DomainRenewalInProgress(
                "Άλλη μεταφορά του {$domain->fqdn} είναι ήδη σε εξέλιξη — ΜΗΝ ξαναζητήσετε· δείτε το ιστορικό API σε λίγο."
            );
        }

        try {
            $credentials = $this->factory->credentialsFor($connection);

            // Adopt-on-retry, UNCONDITIONAL: probe the account FIRST, always —
            // a transfer may have been started outside ekdosi (the registrar
            // panel) or by a timed-out POST that charged. A live record = the
            // transfer is in progress or done → adopt its truth, never a
            // second charged request. A tombstone/not-found = proceed (one
            // cheap GET buys an airtight guard).
            try {
                $probe = $adapter->syncDomain($domain, $credentials);
                if (! $probe->deadRecord && $probe->status !== DomainStatus::Deleted) {
                    $this->sync->apply($domain, $probe);
                    $this->sync->persistHandles($domain, $probe->contactHandles);

                    return $log(DomainRegistrarLog::STATUS_ADOPTED, [
                        'registrar_expiry' => $probe->expiresAt,
                        'raw_status' => $probe->rawStatus,
                    ], null);
                }
                // tombstone → the earlier request died; start fresh below
            } catch (DomainNotFoundAtRegistrar) {
                // not in the account yet — start (or restart) the transfer
            } catch (\Throwable $e) {
                $log(DomainRegistrarLog::STATUS_FAILED, null, 'Έλεγχος λογαριασμού (probe) απέτυχε: '.$e->getMessage());

                throw new RuntimeException("Η μεταφορά του {$domain->fqdn} ΔΕΝ ξεκίνησε — ο έλεγχος του λογαριασμού απέτυχε: ".$e->getMessage());
            }

            try {
                $result = $adapter->transferIn($domain, $authCode, $credentials);
            } catch (\Throwable $e) {
                // The auth code is a bearer credential — SCRUB it from any
                // registrar echo before the message reaches a log row, the
                // operator notification, or an exception trace.
                $message = str_replace($authCode, '«κωδικός EPP»', $e->getMessage());
                $log(DomainRegistrarLog::STATUS_FAILED, null, $message);

                throw new RuntimeException($message, previous: null);
            }
            $this->sync->apply($domain, $result);
            $this->sync->persistHandles($domain, $result->contactHandles);

            return $log(DomainRegistrarLog::STATUS_OK, [
                'registrar_domain_id' => $result->registrarDomainId,
                'raw_status' => $result->rawStatus,
            ], null);
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
                Log::warning('domains.transfer.lock_release_failed', [
                    'domain_id' => $domain->id, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * The transfer-OUT aid: retrieve (never store) the EPP/auth code. The
     * audit row records only THAT it was retrieved — the code itself enables
     * the domain's departure and must never sit in a table.
     */
    public function eppCode(Domain $domain): string
    {
        $connection = $domain->effectiveRegistrarConnection();
        if ($domain->trashed() || $connection === null || ! $connection->isUsable()) {
            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε ενεργή σύνδεση registrar.');
        }
        $adapter = $this->factory->for($connection);
        if ($adapter->key() === 'manual') {
            throw new DomainRegistrarNotConfigured('Ο registrar είναι «manual» — πάρτε τον κωδικό EPP από το portal του.');
        }

        try {
            $code = $adapter->getEppCode($domain, $this->factory->credentialsFor($connection));
        } catch (\Throwable $e) {
            DomainRegistrarLog::create([
                'company_id' => $domain->company_id, 'domain_id' => $domain->id,
                'registrar_connection_id' => $connection->id,
                'action' => 'epp_code', 'status' => DomainRegistrarLog::STATUS_FAILED,
                'request' => ['fqdn' => $domain->fqdn], 'error' => $e->getMessage(),
            ]);

            throw $e;
        }
        if ($code === null) {
            // The retrieval DID reach the registrar — it must leave an audit
            // row like every other leg, even though no code came back.
            DomainRegistrarLog::create([
                'company_id' => $domain->company_id, 'domain_id' => $domain->id,
                'registrar_connection_id' => $connection->id,
                'action' => 'epp_code', 'status' => DomainRegistrarLog::STATUS_FAILED,
                'request' => ['fqdn' => $domain->fqdn], 'error' => 'Ο registrar δεν επέστρεψε κωδικό.',
            ]);

            throw new RuntimeException("Ο registrar δεν επέστρεψε κωδικό EPP για το {$domain->fqdn}.");
        }
        DomainRegistrarLog::create([
            'company_id' => $domain->company_id, 'domain_id' => $domain->id,
            'registrar_connection_id' => $connection->id,
            'action' => 'epp_code', 'status' => DomainRegistrarLog::STATUS_OK,
            'request' => ['fqdn' => $domain->fqdn],
            'response' => ['retrieved' => true], // deliberately NOT the code
        ]);

        return $code;
    }
}
