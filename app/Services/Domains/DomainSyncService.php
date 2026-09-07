<?php

namespace App\Services\Domains;

use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;

/**
 * Applies one registrar pull to one domain (Πυλώνας A / A2b) — shared by the
 * nightly `domains:sync` and the per-domain «Συγχρονισμός» action. The write
 * discipline mirrors the myDATA cache columns: expiry/status/NS/registrar-id
 * are REGISTRAR truth, last_synced_at/sync_error are the sync bookkeeping —
 * failures are RECORDED on the row (and rethrown to the caller for its own
 * reporting), never swallowed into silence.
 */
class DomainSyncService
{
    public function __construct(private readonly DomainRegistrarFactory $factory) {}

    /**
     * The connection whose adapter serves this domain — delegates to THE one
     * routing source (Domain::effectiveRegistrarConnection), so UI and sync
     * can never disagree about which registrar handles a domain.
     */
    public function connectionFor(Domain $domain): ?DomainRegistrarConnection
    {
        return $domain->effectiveRegistrarConnection();
    }

    /** Can this domain be synced at all (routed to an is_active, non-manual connection)? */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $this->connectionFor($domain);

        return $connection !== null
            && $connection->is_active
            && $this->factory->for($connection)->key() !== 'manual';
    }

    /**
     * Pull + apply. Throws on failure AFTER stamping sync_error on the row.
     */
    public function sync(Domain $domain): DomainSyncResult
    {
        $connection = $this->connectionFor($domain);
        if ($connection === null) {
            throw new DomainRegistrarNotConfigured('Το domain δεν δρομολογείται σε καμία σύνδεση registrar (ούτε μέσω TLD).');
        }

        $adapter = $this->factory->for($connection);
        try {
            $result = $adapter->syncDomain($domain, $this->factory->credentialsFor($connection));
        } catch (\Throwable $e) {
            $domain->forceFill([
                'last_synced_at' => Carbon::now(),
                'sync_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            throw $e;
        }

        $this->apply($domain, $result);

        return $result;
    }

    private function apply(Domain $domain, DomainSyncResult $result): void
    {
        $updates = [
            'last_synced_at' => Carbon::now(),
            'sync_error' => null,
        ];
        if ($result->expiresAt !== null) {
            $updates['expires_at'] = $result->expiresAt;
        }
        if ($result->registrarDomainId !== null && ($domain->registrar_domain_id === null || $domain->registrar_domain_id === '')) {
            $updates['registrar_domain_id'] = $result->registrarDomainId;
        }
        if ($result->status !== null) {
            $updates['status'] = $result->status;
        }
        if ($result->rawStatus !== null) {
            $meta = $domain->module_meta ?? [];
            $meta['registrar_status'] = $result->rawStatus;
            $updates['module_meta'] = $meta;
        }

        // forceFill: the sync bookkeeping columns are deliberately not fillable
        // (the InvoiceBalance cache-column discipline); status/expiry are.
        $domain->forceFill($updates)->save();

        // NS delegation snapshot: replace only when the registrar reported any
        // (an empty answer must not wipe a manual record on a partial response).
        if ($result->nameservers !== []) {
            $domain->nameservers()->delete();
            foreach (array_values($result->nameservers) as $i => $host) {
                $domain->nameservers()->create([
                    'company_id' => $domain->company_id,
                    'host' => $host,
                    'sort_order' => $i,
                ]);
            }
        }
    }
}
