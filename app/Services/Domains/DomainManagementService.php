<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\Concerns\GuardsRegistrarWrites;
use App\Support\Domains\DomainChanges;
use RuntimeException;

/**
 * THE one path to registrar management writes (Πυλώνας A / A3d) — the View
 * «Registrar» actions (NS push, transfer lock, WHOIS privacy, contacts push,
 * redemption restore) go through here; nothing else may call
 * DomainRegistrar::updateDomain()/restore().
 *
 * Same skeleton as renew/register/transfer (GuardsRegistrarWrites): every
 * attempt — ok / adopted / failed (refusals included) — lands in
 * domain_registrar_logs under a PER-OPERATION action (set_nameservers /
 * set_lock / set_privacy / set_contacts / restore), and all operations share
 * ONE 'manage' lock per domain (a lock+privacy race would interleave PUTs).
 *
 * restore() is the money leg (redemption fees are typically large): it is
 * sync-first — a name that came back some other way (registrar panel, the
 * registry's own grace handling) is ADOPTED, never re-charged. Restore
 * billing stays manual v1 (no invoice hook) — the operator charges the
 * customer deliberately; DNSSEC key management is deliberately absent v1
 * (docs/BACKLOG.md).
 */
class DomainManagementService
{
    use GuardsRegistrarWrites;

    /** The states where the registrar object is OURS and settable. */
    private const MANAGEABLE = [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace];

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    /** Push the row's LOCAL nameservers to the registrar (full replacement). */
    public function pushNameservers(Domain $domain): DomainRegistrarLog
    {
        $domain->loadMissing('nameservers');
        $hosts = $domain->nameservers
            ->map(fn ($ns) => mb_strtolower(trim((string) $ns->host)))
            ->filter(fn ($h) => $h !== '')
            ->values();

        return $this->manage(
            $domain,
            'set_nameservers',
            ['fqdn' => $domain->fqdn, 'nameservers' => $hosts->all()],
            new DomainChanges(nameservers: $hosts->all()),
            before: function (Domain $domain, callable $log, DomainRegistrar $adapter) use ($hosts): void {
                if ($hosts->count() < 2) {
                    $this->refuseWrite($log, "Το {$domain->fqdn} χρειάζεται τουλάχιστον 2 συμπληρωμένους nameservers (καρτέλα «Nameservers») πριν την αποστολή.");
                }
            },
        );
    }

    public function setTransferLock(Domain $domain, bool $locked): DomainRegistrarLog
    {
        return $this->manage(
            $domain,
            'set_lock',
            ['fqdn' => $domain->fqdn, 'locked' => $locked],
            new DomainChanges(transferLock: $locked),
            before: function (Domain $domain, callable $log, DomainRegistrar $adapter): void {
                if (! $adapter->capabilities()->supportsTransferLock) {
                    $this->refuseWrite($log, 'Ο registrar δεν υποστηρίζει κλείδωμα μεταφοράς μέσω API.');
                }
            },
            // The local mirror follows the REGISTRAR success, atomically with
            // the ok-log — never optimistically before the PUT.
            onSuccess: fn (Domain $domain) => $domain->forceFill(['transfer_lock' => $locked])->save(),
        );
    }

    public function setWhoisPrivacy(Domain $domain, bool $enabled): DomainRegistrarLog
    {
        return $this->manage(
            $domain,
            'set_privacy',
            ['fqdn' => $domain->fqdn, 'enabled' => $enabled],
            new DomainChanges(whoisPrivacy: $enabled),
            before: function (Domain $domain, callable $log, DomainRegistrar $adapter): void {
                if (! $adapter->capabilities()->supportsPrivacy) {
                    $this->refuseWrite($log, 'Ο registrar δεν υποστηρίζει WHOIS privacy μέσω API.');
                }
            },
            onSuccess: fn (Domain $domain) => $domain->forceFill(['whois_privacy' => $enabled])->save(),
        );
    }

    /**
     * Push the row's LOCAL contacts to the registrar (the WHMCS «Modify
     * Contact Details» equivalent) — the adapter ensures reusable handles
     * (creating registrar-side customers as needed) and reassigns them.
     */
    public function pushContacts(Domain $domain): DomainRegistrarLog
    {
        $domain->loadMissing('contacts');

        return $this->manage(
            $domain,
            'set_contacts',
            ['fqdn' => $domain->fqdn, 'contact_types' => $domain->contacts->pluck('type')->values()->all()],
            new DomainChanges(applyContacts: true),
            before: function (Domain $domain, callable $log, DomainRegistrar $adapter): void {
                $registrant = $domain->contacts->firstWhere('type', 'registrant');
                if ($registrant === null || trim((string) $registrant->email) === '') {
                    $this->refuseWrite($log, "Το {$domain->fqdn} χρειάζεται επαφή registrant με email (καρτέλα «Επαφές») πριν την αποστολή επαφών.");
                }
            },
        );
    }

    /**
     * Restore from redemption — REAL (usually large) MONEY. Sync-first adopt
     * guard: if the registrar already shows a LIVE record (restored at the
     * panel, or the DEL was our stale state), ADOPT its truth — never a
     * second restore charge. Billing stays manual v1.
     */
    public function restore(Domain $domain): DomainRegistrarLog
    {
        $connection = $domain->effectiveRegistrarConnection();
        $log = $this->writeLogger($domain, $connection, 'restore', ['fqdn' => $domain->fqdn]);

        if ($domain->trashed()) {
            $this->refuseWrite($log, 'Το domain είναι διαγραμμένο (soft-deleted) εδώ — επαναφέρετε πρώτα την εγγραφή.');
        }
        if (! in_array($domain->status, [DomainStatus::Redemption, DomainStatus::Deleted], true)) {
            $this->refuseWrite($log, "Το {$domain->fqdn} δεν είναι σε redemption/διαγραμμένο — η επαναφορά δεν έχει νόημα (δείτε «Συγχρονισμός»).");
        }
        $adapter = $this->resolveWriteAdapter($connection, $log, 'Ο registrar είναι «manual» — κάντε την επαναφορά στο portal του registrar.');
        $this->assertNotClaimedElsewhere($domain, $log, "Το {$domain->fqdn} είναι καταχωρημένο από ΑΛΛΗ εταιρεία στον ίδιο λογαριασμό registrar — δεν επαναφέρεται από εδώ.");

        return $this->withRegistrarLock(
            $domain,
            'restore',
            $log,
            "Άλλη επαναφορά του {$domain->fqdn} είναι ήδη σε εξέλιξη — ΜΗΝ ξαναζητήσετε· δείτε το ιστορικό API σε λίγο.",
            function () use ($domain, $connection, $adapter, $log): DomainRegistrarLog {
                $credentials = $this->factory->credentialsFor($connection);

                // Sync-first (§6.6 discipline on the priciest write): a LIVE
                // record = already restored somewhere → adopt, zero charge.
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
                    // tombstone → genuinely restorable, proceed
                } catch (DomainNotFoundAtRegistrar) {
                    // Gone from the account entirely: nothing to restore — the
                    // name is (or is becoming) publicly re-registrable.
                    $this->refuseWrite($log, "Το {$domain->fqdn} δεν βρίσκεται πλέον στον λογαριασμό — δεν επαναφέρεται (αν χρειάζεται, νέα καταχώρηση όταν αποδεσμευτεί).");
                } catch (\Throwable $e) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, 'Προ-έλεγχος (sync) απέτυχε: '.$e->getMessage());

                    throw new RuntimeException("Η επαναφορά του {$domain->fqdn} ΔΕΝ εκτελέστηκε — ο προ-έλεγχος στον registrar απέτυχε: ".$e->getMessage());
                }

                try {
                    $result = $adapter->restore($domain, $credentials);
                } catch (\Throwable $e) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, $e->getMessage());

                    throw $e;
                }
                $this->sync->apply($domain, $result);
                $this->sync->persistHandles($domain, $result->contactHandles);

                return $log(DomainRegistrarLog::STATUS_OK, [
                    'registrar_expiry' => $result->expiresAt,
                    'raw_status' => $result->rawStatus,
                ], null);
            },
        );
    }

    /**
     * The shared management-write skeleton (the non-money PUT legs): guards →
     * ONE 'manage' lock → updateDomain → apply truth → local mirror → ok-log.
     *
     * @param  ?\Closure(Domain, callable, DomainRegistrar): void  $before  operation-specific refusals (run AFTER the shared guards, before the lock)
     * @param  ?\Closure(Domain): void  $onSuccess  local column mirror, applied only after the registrar accepted
     */
    private function manage(Domain $domain, string $action, array $request, DomainChanges $changes, ?\Closure $before = null, ?\Closure $onSuccess = null): DomainRegistrarLog
    {
        $connection = $domain->effectiveRegistrarConnection();
        $log = $this->writeLogger($domain, $connection, $action, $request);

        if ($domain->trashed()) {
            $this->refuseWrite($log, 'Το domain είναι διαγραμμένο — δεν γίνονται αλλαγές στον registrar.');
        }
        if (! in_array($domain->status, self::MANAGEABLE, true)) {
            $label = $domain->status instanceof DomainStatus ? $domain->status->getLabel() : (string) $domain->status?->value;
            $this->refuseWrite($log, "Το {$domain->fqdn} είναι σε κατάσταση «{$label}» — οι αλλαγές στον registrar γίνονται μόνο σε ενεργά/ληγμένα (grace) domains.");
        }
        $adapter = $this->resolveWriteAdapter($connection, $log, 'Ο registrar είναι «manual» — κάντε την αλλαγή στο portal του registrar (και ενημερώστε το domain εδώ).');
        if ($before !== null) {
            $before($domain, $log, $adapter);
        }
        $this->assertNotClaimedElsewhere($domain, $log, "Το {$domain->fqdn} είναι καταχωρημένο από ΑΛΛΗ εταιρεία στον ίδιο λογαριασμό registrar — δεν αλλάζει από εδώ.");

        return $this->withRegistrarLock(
            $domain,
            'manage',
            $log,
            "Άλλη αλλαγή στο {$domain->fqdn} είναι ήδη σε εξέλιξη — δείτε το ιστορικό API σε λίγο.",
            function () use ($domain, $connection, $adapter, $changes, $onSuccess, $log): DomainRegistrarLog {
                try {
                    $result = $adapter->updateDomain($domain, $changes, $this->factory->credentialsFor($connection));
                } catch (\Throwable $e) {
                    $log(DomainRegistrarLog::STATUS_FAILED, null, $e->getMessage());

                    throw $e;
                }
                $this->sync->apply($domain, $result);
                $this->sync->persistHandles($domain, $result->contactHandles);
                if ($onSuccess !== null) {
                    $onSuccess($domain);
                }

                return $log(DomainRegistrarLog::STATUS_OK, [
                    'raw_status' => $result->rawStatus,
                ], null);
            },
        );
    }
}
