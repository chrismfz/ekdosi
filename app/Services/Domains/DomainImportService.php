<?php

namespace App\Services\Domains;

use App\Contracts\DomainRegistrar;
use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainRegistrarConnection;
use App\Models\DomainTld;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainRecord;

/**
 * Registrar-first portfolio import (Πυλώνας A / A2c, README §9 — owner
 * decision: «να μην εξαρτιόμαστε από WHMCS db»). Pages through the account's
 * OWN domain listing and upserts by (company_id, fqdn):
 *
 *   - New domains land ΑΔΕΣΠΟΤΑ (customer_id = null) with contacts resolved
 *     from the registrar handles — the operator's manual-assign aid on the
 *     «Χωρίς πελάτη» worklist. customer_id is NEVER written by import.
 *   - Existing live rows get ONLY registrar truth, through the SAME
 *     DomainSyncService::apply the nightly sync uses (id adoption, frozen
 *     statuses, expired derivation, atomic NS snapshot) — import and sync can
 *     never disagree. auto_renew is written only on CREATE (option β: after
 *     that it's the operator's/assignment's decision).
 *   - Contacts are (re)written only on UNASSIGNED domains: once assigned,
 *     contact rows are operator territory (may deliberately diverge, §3.7).
 *   - A trashed domain (tombstone) or trashed TLD-catalogue row is SKIPPED —
 *     an operator delete is never resurrected by an import.
 *   - A missing TLD-catalogue row is auto-created, routed to the importing
 *     connection (so the imported domains sync out of the box).
 *
 * READ-ONLY at the registrar (list + contact GETs); one broken contact handle
 * warns and continues — it must never kill a domain import.
 */
class DomainImportService
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 500; // safety stop against a lying/endless pager

    public function __construct(
        private readonly DomainRegistrarFactory $factory,
        private readonly DomainSyncService $sync,
    ) {}

    /** Can this connection feed the import at all? */
    public function isImportable(DomainRegistrarConnection $connection): bool
    {
        if ($connection->trashed() || ! $connection->isUsable()) {
            return false;
        }

        $adapter = $this->factory->for($connection);

        return $adapter->key() !== 'manual' && $adapter->capabilities()->supportsPortfolioImport;
    }

    /**
     * Import the whole account portfolio for one connection.
     * `$warn` receives human-readable non-fatal problems (contact failures,
     * skipped tombstones); page/transport failures throw.
     *
     * @return array{created: int, updated: int, skipped: int, contacts: int}
     */
    public function import(Company $company, DomainRegistrarConnection $connection, ?callable $warn = null): array
    {
        if (! $this->isImportable($connection)) {
            throw new DomainRegistrarNotConfigured(
                'Η σύνδεση δεν υποστηρίζει import portfolio (manual/ανενεργή, ή registrar χωρίς listing API).'
            );
        }

        $warn ??= static function (string $message): void {};
        $adapter = $this->factory->for($connection);
        $credentials = $this->factory->credentialsFor($connection);

        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'contacts' => 0];
        /** @var array<string, RegistrarContact|false> $contactCache handles are reused across domains — false = failed, don't re-hit */
        $contactCache = [];
        /** @var array<string, DomainTld|false> $tldCache a portfolio has few distinct TLDs — false = trashed (skip) */
        $tldCache = [];

        $offset = 0;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $adapter->listDomains($credentials, $offset, self::PAGE_SIZE);
            foreach ($result->records as $record) {
                $this->importRecord($company, $connection, $record, $contactCache, $tldCache, $counts, $warn, $adapter, $credentials);
            }

            // Advance by the PAGE size and stop on the RAW row count — rows
            // the adapter couldn't shape were still consumed server-side, so
            // stopping on count($records) would silently truncate the account.
            // max() guards an adapter that forgot to pass rawCount (its
            // default 0 would otherwise stop the pager after a FULL page).
            $offset += self::PAGE_SIZE;
            if (max($result->rawCount, count($result->records)) < self::PAGE_SIZE) {
                break;
            }
            if ($result->total !== null && $offset >= $result->total) {
                break;
            }
        }

        return $counts;
    }

    /**
     * @param  array<string, RegistrarContact|false>  $contactCache
     * @param  array<string, DomainTld|false>  $tldCache
     */
    private function importRecord(
        Company $company,
        DomainRegistrarConnection $connection,
        RegistrarDomainRecord $record,
        array &$contactCache,
        array &$tldCache,
        array &$counts,
        callable $warn,
        DomainRegistrar $adapter,
        DomainRegistrarCredentials $credentials,
    ): void {
        $fqdn = $record->fqdn();

        if (! array_key_exists($record->tld, $tldCache)) {
            $tldRule = DomainTld::withTrashed()
                ->where('company_id', $company->id)
                ->where('tld', $record->tld)
                ->first();
            if ($tldRule !== null && $tldRule->trashed()) {
                // The operator deleted this TLD from the catalogue — an import
                // must not resurrect it behind their back. Warn ONCE per TLD.
                $warn("Παράλειψη domains σε .{$record->tld}: το TLD είναι διαγραμμένο στον κατάλογο «TLDs & τιμές».");
                $tldRule = false;
            } elseif ($tldRule === null) {
                $tldRule = DomainTld::create([
                    'company_id' => $company->id,
                    'tld' => $record->tld,
                    'registrar_connection_id' => $connection->id,
                    'min_years' => 1,
                    'is_active' => true,
                ]);
            }
            $tldCache[$record->tld] = $tldRule;
        }
        $tldRule = $tldCache[$record->tld];
        if ($tldRule === false) {
            $counts['skipped']++;

            return;
        }

        $domain = Domain::withTrashed()
            ->where('company_id', $company->id)
            ->where('fqdn', $fqdn)
            ->first();
        if ($domain !== null && $domain->trashed()) {
            // Tombstone: an operator delete is never rewritten (sync parity).
            $counts['skipped']++;

            return;
        }
        if ($domain !== null && $domain->status instanceof DomainStatus && $domain->status->blocksSync()) {
            // Operator-terminal row (cancelled/transferred_away): the nightly
            // sync refuses to touch these and so does the import — the frozen
            // expiry/NS snapshot is historical record (blocksSync parity).
            $counts['skipped']++;
            $warn($domain->status->frozenSkipMessage($fqdn));

            return;
        }

        if ($domain === null) {
            $domain = Domain::create([
                'company_id' => $company->id,
                'customer_id' => null, // αδέσποτο — ONLY the operator assigns
                'domain_tld_id' => $tldRule->id,
                // The domain provably lives in THIS account — pin the routing
                // (the TLD default may point elsewhere for other domains).
                'registrar_connection_id' => $connection->id,
                'sld' => $record->sld,
                'tld' => $record->tld,
                'fqdn' => $fqdn,
                'status' => $record->status ?? DomainStatus::Active,
                'registered_at' => $record->registeredAt,
                'auto_renew' => $record->autoRenew ?? false,
            ]);
            $counts['created']++;
        } else {
            // Existing live row: registrar truth only — never customer_id,
            // auto_renew, overrides or routing (operator decisions).
            if ($domain->registered_at === null && $record->registeredAt !== null) {
                $domain->forceFill(['registered_at' => $record->registeredAt])->save();
            }
            $counts['updated']++;
        }

        // The SAME truth-apply as the nightly sync (id adoption, frozen
        // statuses, expired derivation, atomic NS snapshot).
        $this->sync->apply($domain, new DomainSyncResult(
            expiresAt: $record->expiresAt,
            nameservers: $record->nameservers,
            registrarDomainId: $record->registrarDomainId,
            status: $record->status,
            rawStatus: $record->rawStatus,
        ));

        $counts['contacts'] += $this->applyContacts($domain, $record->contactHandles, $adapter, $credentials, $contactCache, $warn);
    }

    /**
     * Per-domain convenience (the View «Συγχρονισμός» flow): resolve the
     * domain's own routing and pull the given handles. Same rules as the bulk
     * import — unassigned rows only, failures warn and never throw.
     *
     * @param  array<string, string>  $handles  contact type → handle
     */
    public function refreshContacts(Domain $domain, array $handles, ?callable $warn = null): int
    {
        $connection = $domain->effectiveRegistrarConnection();
        // Usable non-manual is enough here — supportsPortfolioImport is about
        // ACCOUNT LISTING, the wrong capability for a per-handle contact GET
        // (a future adapter with sync+contacts but no listing must still work).
        if ($connection === null || $connection->trashed() || ! $connection->isUsable()) {
            return 0;
        }
        if ($this->factory->for($connection)->key() === 'manual') {
            return 0;
        }
        $cache = [];

        return $this->applyContacts(
            $domain,
            $handles,
            $this->factory->for($connection),
            $this->factory->credentialsFor($connection),
            $cache,
            $warn ?? static function (string $message): void {},
        );
    }

    /**
     * Resolve handles → upsert domain_contacts. Assign-aid ONLY while the
     * domain is unassigned (after assignment the rows are operator territory
     * and may diverge, §3.7). Returns the contact rows written.
     *
     * @param  array<string, string>  $handles
     * @param  array<string, RegistrarContact|false>  $contactCache
     */
    private function applyContacts(
        Domain $domain,
        array $handles,
        DomainRegistrar $adapter,
        DomainRegistrarCredentials $credentials,
        array &$contactCache,
        callable $warn,
    ): int {
        if ($domain->customer_id !== null || $handles === []) {
            return 0;
        }

        $written = 0;
        foreach ($handles as $type => $handle) {
            if (! in_array($type, DomainContact::TYPES, true)) {
                continue;
            }
            try {
                if (! array_key_exists($handle, $contactCache)) {
                    try {
                        $contactCache[$handle] = $adapter->getContact($handle, $credentials) ?? false;
                    } catch (\RuntimeException $e) {
                        $contactCache[$handle] = false; // don't re-hit a broken handle this run
                        $warn("Επαφή {$handle} ({$domain->fqdn}): ".$e->getMessage());
                    }
                }
                $contact = $contactCache[$handle];
                if (! $contact instanceof RegistrarContact) {
                    continue;
                }
                DomainContact::updateOrCreate(
                    ['domain_id' => $domain->id, 'type' => $type],
                    [
                        'company_id' => $domain->company_id,
                        // Truncated to the column limits — a registrar can
                        // return oversize values and strict MariaDB would
                        // otherwise fail the upsert (a name is an assign-aid,
                        // a clipped one still serves it).
                        'name' => mb_substr($contact->name, 0, 190),
                        'org' => $contact->org !== null ? mb_substr($contact->org, 0, 190) : null,
                        'email' => $contact->email !== null ? mb_substr($contact->email, 0, 190) : null,
                        'phone' => $contact->phone !== null ? mb_substr($contact->phone, 0, 40) : null,
                        'address1' => $contact->address1 !== null ? mb_substr($contact->address1, 0, 190) : null,
                        'address2' => $contact->address2 !== null ? mb_substr($contact->address2, 0, 190) : null,
                        'city' => $contact->city !== null ? mb_substr($contact->city, 0, 120) : null,
                        'postcode' => $contact->postcode !== null ? mb_substr($contact->postcode, 0, 20) : null,
                        'country' => $contact->country,
                        'registrar_contact_handle' => mb_substr($contact->handle, 0, 40),
                    ],
                );
                $written++;
            } catch (\Throwable $e) {
                // Belt over the truncation braces: the WRITE itself failing
                // (DB hiccup) must warn, never kill the sync/import — the
                // stated contract of both flows.
                $warn("Επαφή {$handle} ({$domain->fqdn}): ".$e->getMessage());
            }
        }

        return $written;
    }
}
