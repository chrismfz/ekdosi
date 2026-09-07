<?php

namespace App\Services\Domains;

use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Models\DomainRegistrarConnection;
use App\Support\Domains\DomainSyncResult;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    /**
     * Can this domain be synced at all? Routed to a USABLE (active, non-off)
     * non-manual connection AND not in an OPERATOR-terminal status (cancelled /
     * transferred_away — see DomainStatus::blocksSync; a registrar-set Deleted
     * keeps syncing so a redemption restore is picked up).
     */
    public function isSyncable(Domain $domain): bool
    {
        $connection = $this->connectionFor($domain);

        return ! $domain->trashed() // a tombstone is never rewritten (parity with the nightly command)
            && $connection !== null
            && $connection->isUsable()
            && $this->factory->for($connection)->key() !== 'manual'
            && ! ($domain->status instanceof DomainStatus && $domain->status->blocksSync());
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
        // The by-name resolve is authoritative for THIS fqdn — adopt the id it
        // reported even over a stale stored one (else every future run repeats
        // the failing by-id call); keep the old value in module_meta for audit.
        if ($result->registrarDomainId !== null && $result->registrarDomainId !== $domain->registrar_domain_id) {
            if ($domain->registrar_domain_id !== null && $domain->registrar_domain_id !== '') {
                $meta = $updates['module_meta'] ?? ($domain->module_meta ?? []);
                $meta['previous_registrar_domain_id'] = $domain->registrar_domain_id;
                $updates['module_meta'] = $meta;
            }
            $updates['registrar_domain_id'] = $result->registrarDomainId;
        }
        // The registrar may only PROMOTE our view — never overwrite an
        // OPERATOR-terminal status (cancelled/transferred_away; a registrar-set
        // Deleted may be promoted back, e.g. redemption restore → ACT).
        $frozen = $domain->status instanceof DomainStatus && $domain->status->blocksSync();
        $newStatus = $result->status;
        // Openprovider keeps reporting ACT past the expiry date — derive the
        // documented active→expired transition from the REGISTRAR expiry so a
        // lapsed domain never sits in the «Ενεργά» tab (docs §6.5; the full
        // grace/redemption windows land with A3). Also covers an UNMAPPED
        // registrar status on a locally-Active row — the lapse must still land.
        $effectiveExpiry = $result->expiresAt ?? $domain->expires_at?->toDateString();
        $wouldBeActive = $newStatus === DomainStatus::Active
            || ($newStatus === null && $domain->status === DomainStatus::Active);
        if ($wouldBeActive
            && $effectiveExpiry !== null
            && Carbon::parse($effectiveExpiry)->lt(Carbon::today())) {
            $newStatus = DomainStatus::Expired;
        }
        if ($newStatus !== null && ! $frozen) {
            $updates['status'] = $newStatus;
        }
        if ($result->rawStatus !== null) {
            // Merge with any meta the id-adoption block above already staged.
            $meta = $updates['module_meta'] ?? ($domain->module_meta ?? []);
            $meta['registrar_status'] = $result->rawStatus;
            $updates['module_meta'] = $meta;
        }

        // One atomic apply: the row update + the NS snapshot replace commit (or
        // fail) together — a mid-apply crash can't wipe the delegation record.
        DB::transaction(function () use ($domain, $result, $updates): void {
            // forceFill: the sync bookkeeping columns are deliberately not
            // fillable (the InvoiceBalance cache-column discipline).
            $domain->forceFill($updates)->save();

            // NS delegation snapshot: replace only when the registrar reported
            // any (an empty answer must not wipe a manual record).
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
        });
    }
}
