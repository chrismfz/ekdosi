<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use WHMCS\Database\Capsule;

/**
 * T-1b-2 (timologia v2): the bridge's OWN third-party-invoicing storage.
 *
 * Rather than read the legacy `mod_timologia*` tables directly, the bridge
 * keeps its own `mod_ekdosi_contacts` + `mod_ekdosi_routing`, seeded by a
 * re-runnable, idempotent SYNC from the legacy tables. This:
 *
 *   - decouples ekdosi from the legacy plugin's schema (we can extend ours);
 *   - avoids two writers on the legacy tables (the legacy plugin + desktop
 *     app keep owning mod_timologia*; we only READ them, during sync);
 *   - gives the future hideable v2 client page its OWN tables to write to;
 *   - mirrors the Firebird ETL philosophy: keyed on the legacy id, upsert on
 *     re-run, NEVER touch rows we created ourselves (legacy id NULL), never
 *     auto-delete.
 *
 * resolve.php reads ONLY these own tables — so an operator runs "Sync from
 * legacy timologia" (admin page) once before testing, and again whenever the
 * legacy data changes, until cutover.
 *
 * All methods are static + Capsule-based; callable from both the standalone
 * resolve.php endpoint (after it boots WHMCS) and the addon admin controller.
 */
class ThirdPartyStore
{
    public const CONTACTS = 'mod_ekdosi_contacts';

    public const ROUTING = 'mod_ekdosi_routing';

    public const LEGACY_CONTACTS = 'mod_timologia_contacts';

    public const LEGACY_ROUTING = 'mod_timologia';

    /**
     * Create the own tables if absent. Idempotent (CREATE TABLE IF NOT
     * EXISTS). Called from the addon activate hook AND lazily before a sync,
     * so the operator never has to run raw DDL. NOT called from resolve.php —
     * a read-only endpoint must not issue DDL.
     */
    public static function ensureTables(): void
    {
        Capsule::statement(
            'CREATE TABLE IF NOT EXISTS '.self::CONTACTS.' (
                id INT(11) NOT NULL AUTO_INCREMENT,
                legacy_contact_id INT(11) NULL,
                userid INT(11) NOT NULL,
                company_name VARCHAR(191) NULL,
                gr_vatno VARCHAR(30) NULL,
                vies_vatno VARCHAR(60) NULL,
                tax_office VARCHAR(120) NULL,
                address1 VARCHAR(120) NULL,
                address2 VARCHAR(120) NULL,
                city VARCHAR(120) NULL,
                postal_code VARCHAR(20) NULL,
                country VARCHAR(60) NULL,
                description VARCHAR(191) NULL,
                email VARCHAR(120) NULL,
                telephone VARCHAR(40) NULL,
                comments TEXT NULL,
                source VARCHAR(30) NOT NULL DEFAULT "sync",
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_legacy_contact (legacy_contact_id),
                KEY idx_userid (userid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        Capsule::statement(
            'CREATE TABLE IF NOT EXISTS '.self::ROUTING.' (
                id INT(11) NOT NULL AUTO_INCREMENT,
                legacy_routing_id INT(11) NULL,
                userid INT(11) NOT NULL,
                contactid INT(11) NOT NULL,
                serviceid INT(11) NOT NULL,
                service_type VARCHAR(20) NOT NULL,
                is_receipt TINYINT(1) NOT NULL DEFAULT 0,
                source VARCHAR(30) NOT NULL DEFAULT "sync",
                created_at DATETIME NULL,
                updated_at DATETIME NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_legacy_routing (legacy_routing_id),
                KEY idx_lookup (userid, serviceid, service_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    public static function hasOwnTables(): bool
    {
        try {
            $schema = Capsule::schema();

            return $schema->hasTable(self::CONTACTS) && $schema->hasTable(self::ROUTING);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function hasLegacyTables(): bool
    {
        try {
            $schema = Capsule::schema();

            return $schema->hasTable(self::LEGACY_CONTACTS) && $schema->hasTable(self::LEGACY_ROUTING);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Map a WHMCS line-item `type` to the routing vocabulary
     * ('hosting'/'domain'). Null for types the routing never tracked.
     */
    public static function serviceType(string $whmcsItemType): ?string
    {
        $t = strtolower($whmcsItemType);
        if (str_contains($t, 'hosting')) {
            return 'hosting';
        }
        if (str_contains($t, 'domain')) {
            return 'domain';
        }

        return null;
    }

    /**
     * Re-runnable, idempotent import from the legacy mod_timologia* tables
     * into our own. Keyed on the legacy id (upsert). Rows we created in v2
     * (legacy id NULL) are never touched. Orphan routing rows (contact gone)
     * are counted and skipped.
     *
     * @return array{ok: bool, message?: string, contacts_inserted: int,
     *               contacts_updated: int, routes_inserted: int,
     *               routes_updated: int, routes_skipped: int}
     */
    public static function syncFromLegacy(): array
    {
        $result = [
            'ok' => true,
            'contacts_inserted' => 0, 'contacts_updated' => 0,
            'routes_inserted' => 0, 'routes_updated' => 0, 'routes_skipped' => 0,
        ];

        if (! self::hasLegacyTables()) {
            return ['ok' => false, 'message' => 'Legacy mod_timologia tables not found — nothing to sync.']
                + $result;
        }

        self::ensureTables();
        $now = date('Y-m-d H:i:s');

        // Atomic: contacts + routing in one transaction so a mid-sync failure
        // doesn't leave a partial import (re-running is idempotent anyway).
        Capsule::connection()->transaction(function () use (&$result, $now) {
            // 1. Contacts. Build a legacy-id → own-id map for the routing pass.
            $contactMap = [];
            foreach (Capsule::table(self::LEGACY_CONTACTS)->get() as $lc) {
                $legacyId = (int) $lc->id;
                $data = [
                    'userid' => (int) ($lc->userid ?? 0),
                    'company_name' => self::clip($lc->company_name ?? null, 191),
                    'gr_vatno' => self::clip($lc->gr_vatno ?? null, 30),
                    'vies_vatno' => self::clip($lc->vies_vatno ?? null, 60),
                    'tax_office' => self::clip($lc->tax_office ?? null, 120),
                    'address1' => self::clip($lc->address1 ?? null, 120),
                    'address2' => self::clip($lc->address2 ?? null, 120),
                    'city' => self::clip($lc->city ?? null, 120),
                    'postal_code' => self::clip($lc->postal_code ?? null, 20),
                    'country' => self::clip($lc->country ?? null, 60),
                    'description' => self::clip($lc->description ?? null, 191),
                    'email' => self::clip($lc->email ?? null, 120),
                    'telephone' => self::clip($lc->telephone ?? null, 40),
                    'comments' => $lc->comments ?? null,
                    'source' => 'sync',
                    'updated_at' => $now,
                ];

                $existing = Capsule::table(self::CONTACTS)
                    ->where('legacy_contact_id', $legacyId)
                    ->first();
                if ($existing) {
                    Capsule::table(self::CONTACTS)->where('id', $existing->id)->update($data);
                    $ownId = (int) $existing->id;
                    $result['contacts_updated']++;
                } else {
                    $ownId = (int) Capsule::table(self::CONTACTS)->insertGetId(
                        $data + ['legacy_contact_id' => $legacyId, 'created_at' => $now]
                    );
                    $result['contacts_inserted']++;
                }
                $contactMap[$legacyId] = $ownId;
            }

            // 2. Routing. Map the legacy contactid → our own contact id.
            foreach (Capsule::table(self::LEGACY_ROUTING)->get() as $lr) {
                $legacyContactId = (int) ($lr->contactid ?? 0);
                $ownContactId = $contactMap[$legacyContactId] ?? null;
                if ($ownContactId === null) {
                    // Contact referenced by this route wasn't synced (deleted /
                    // dangling). Skip rather than create a routing row with no
                    // billing identity.
                    $result['routes_skipped']++;

                    continue;
                }

                $legacyId = (int) $lr->id;
                $data = [
                    'userid' => (int) ($lr->userid ?? 0),
                    'contactid' => $ownContactId,
                    'serviceid' => (int) ($lr->serviceid ?? 0),
                    'service_type' => self::clip($lr->service_type ?? '', 20),
                    'is_receipt' => (int) ((bool) ($lr->isReceipt ?? 0)),
                    'source' => 'sync',
                    'updated_at' => $now,
                ];

                $existing = Capsule::table(self::ROUTING)
                    ->where('legacy_routing_id', $legacyId)
                    ->first();
                if ($existing) {
                    Capsule::table(self::ROUTING)->where('id', $existing->id)->update($data);
                    $result['routes_updated']++;
                } else {
                    Capsule::table(self::ROUTING)->insert(
                        $data + ['legacy_routing_id' => $legacyId, 'created_at' => $now]
                    );
                    $result['routes_inserted']++;
                }
            }
        });

        return $result;
    }

    /**
     * Per-line routing for one invoice, read from the OWN tables.
     *
     * @return array<string, mixed>
     */
    public static function resolveInvoice($invoice): array
    {
        $ownTables = self::hasOwnTables();
        $userId = (int) $invoice->userid;
        $items = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoice->id)
            ->get(['id', 'type', 'relid', 'description']);

        $lines = [];
        $routedLines = 0;
        $partyKeys = [];

        foreach ($items as $item) {
            $relid = (int) ($item->relid ?? 0);
            $serviceType = self::serviceType((string) ($item->type ?? ''));

            $contact = null;
            $isReceipt = false;
            if ($ownTables && $serviceType !== null && $relid > 0) {
                $route = Capsule::table(self::ROUTING)
                    ->where('userid', $userId)
                    ->where('serviceid', $relid)
                    ->where('service_type', $serviceType)
                    ->first();
                if ($route) {
                    $isReceipt = (bool) ((int) ($route->is_receipt ?? 0));
                    $contactRow = Capsule::table(self::CONTACTS)
                        ->where('id', (int) $route->contactid)
                        ->first();
                    if ($contactRow) {
                        $contact = self::contactArray($contactRow);
                    }
                }
            }

            $routed = $contact !== null;
            if ($routed) {
                $routedLines++;
                $partyKeys['contact:'.$contact['id']] = true;
            } else {
                $partyKeys['reseller:'.$userId] = true;
            }

            $lines[] = [
                'item_id' => (int) $item->id,
                'relid' => $relid,
                'type' => (string) ($item->type ?? ''),
                'service_type' => $serviceType,
                'description' => (string) ($item->description ?? ''),
                'routed' => $routed,
                'is_receipt' => $isReceipt,
                'contact' => $contact,
            ];
        }

        $distinctParties = count($partyKeys);

        return [
            'status' => 'ok',
            'whmcs_invoice_id' => (int) $invoice->id,
            'userid' => $userId,
            // Kept for the ekdosi-side ThirdPartyResolution contract; now means
            // "the own routing tables exist" (they're created at activation /
            // first sync).
            'timologia_present' => $ownTables,
            'lines' => $lines,
            'summary' => [
                'line_count' => count($lines),
                'routed_lines' => $routedLines,
                'unrouted_lines' => count($lines) - $routedLines,
                'distinct_parties' => $distinctParties,
                'multi_party' => $distinctParties > 1,
            ],
        ];
    }

    /**
     * Batch third-party resolution for a PAGE of invoices — for the admin
     * invoice list's «Τρίτος» column. Returns
     *   invoiceId => ['bucket' => 'none'|'single'|'multi', 'names' => string[]]
     * where `names` are the distinct third-party beneficiary company names on
     * the invoice (so the column can show WHO, not just yes/no). Computed
     * LOCALLY from mod_ekdosi_routing (no ekdosi/inbox dependency — works for
     * every invoice, historical included).
     *
     * Efficient: ~3 queries total regardless of page size — line items for the
     * page, the routing rows for the (userid, serviceid, type) tuples present,
     * and the contacts those route to — joined in PHP. Mirrors resolveInvoice's
     * per-line logic (relid → routing → contact) without per-invoice fan-out.
     *
     * @param  array<int, object>  $invoices  rows with ->id and ->userid
     * @return array<int, array{bucket: string, names: list<string>}>
     */
    public static function bucketsForInvoices(array $invoices): array
    {
        $out = [];
        $blank = ['bucket' => 'none', 'names' => []];
        if ($invoices === [] || ! self::hasOwnTables()) {
            foreach ($invoices as $inv) {
                $out[(int) $inv->id] = $blank;
            }

            return $out;
        }

        $userById = [];
        foreach ($invoices as $inv) {
            $userById[(int) $inv->id] = (int) $inv->userid;
        }
        $invoiceIds = array_keys($userById);

        // 1. All line items for the page's invoices.
        $items = Capsule::table('tblinvoiceitems')
            ->whereIn('invoiceid', $invoiceIds)
            ->get(['invoiceid', 'type', 'relid']);

        // 2. Routing rows that could match — scoped to the page's users + the
        //    relids present. Keyed "userid:serviceid:service_type" => contactid.
        $userIds = array_values(array_unique(array_values($userById)));
        $relIds = [];
        foreach ($items as $it) {
            $rid = (int) ($it->relid ?? 0);
            if ($rid > 0) {
                $relIds[$rid] = true;
            }
        }
        $routeContact = [];   // composite key => contactid
        $contactIds = [];
        if ($userIds !== [] && $relIds !== []) {
            foreach (Capsule::table(self::ROUTING)
                ->whereIn('userid', $userIds)
                ->whereIn('serviceid', array_keys($relIds))
                ->get(['userid', 'serviceid', 'service_type', 'contactid']) as $r) {
                $cid = (int) $r->contactid;
                $routeContact[((int) $r->userid).':'.((int) $r->serviceid).':'.((string) $r->service_type)] = $cid;
                $contactIds[$cid] = true;
            }
        }

        // 3. Contact names for the routed contacts (one query).
        $contactName = [];
        if ($contactIds !== []) {
            foreach (Capsule::table(self::CONTACTS)
                ->whereIn('id', array_keys($contactIds))
                ->get(['id', 'company_name']) as $c) {
                $contactName[(int) $c->id] = (string) $c->company_name;
            }
        }

        // 4. Fold per invoice: distinct billing parties (each routed line = its
        //    contact; each unrouted line = the reseller). Collect routed
        //    contact names for display.
        $partyKeys = [];   // invoiceId => set of party keys
        $names = [];       // invoiceId => [contactName => true]
        foreach ($items as $it) {
            $invId = (int) $it->invoiceid;
            $userId = $userById[$invId] ?? 0;
            $relid = (int) ($it->relid ?? 0);
            $serviceType = self::serviceType((string) ($it->type ?? ''));
            $key = $userId.':'.$relid.':'.$serviceType;
            $cid = ($serviceType !== null && $relid > 0) ? ($routeContact[$key] ?? 0) : 0;

            if ($cid > 0) {
                $partyKeys[$invId]['c:'.$cid] = true;
                $nm = $contactName[$cid] ?? ('#'.$cid);
                $names[$invId][$nm] = true;
            } else {
                $partyKeys[$invId]['reseller'] = true;
            }
        }

        foreach ($invoiceIds as $invId) {
            $keys = $partyKeys[$invId] ?? [];
            $bucket = 'none';
            if (count($keys) > 1) {
                $bucket = 'multi';
            } elseif (count($keys) === 1 && ! isset($keys['reseller'])) {
                $bucket = 'single';
            }
            $out[$invId] = [
                'bucket' => $bucket,
                'names' => array_keys($names[$invId] ?? []),
            ];
        }

        return $out;
    }

    /**
     * Every WHMCS client with >=1 routing row in the OWN tables, with counts.
     *
     * @return array<string, mixed>
     */
    public static function resellers(): array
    {
        if (! self::hasOwnTables()) {
            return ['status' => 'ok', 'resellers' => []];
        }

        $rows = Capsule::table(self::ROUTING)
            ->select('userid', Capsule::raw('COUNT(*) AS routes'))
            ->groupBy('userid')
            ->get();

        $resellers = [];
        foreach ($rows as $row) {
            $resellers[] = ['userid' => (int) $row->userid, 'routes' => (int) $row->routes];
        }

        return ['status' => 'ok', 'resellers' => $resellers];
    }

    /**
     * Batched: the ROUTED services per user, resolved to their WHMCS domain
     * label — for the admin list's «Υπηρεσίες» column. Distinct live labels +
     * a count of routes whose service no longer exists (dead). ~3 queries total
     * regardless of user count (routing rows for the users, then the hosting +
     * domain labels for the serviceids present).
     *
     * @param  array<int, int>  $userids
     * @return array<int, array{labels: list<string>, dead: int}>
     */
    public static function routedServicesForUsers(array $userids): array
    {
        $out = [];
        if ($userids === [] || ! self::hasOwnTables()) {
            return $out;
        }

        $routes = Capsule::table(self::ROUTING)
            ->whereIn('userid', $userids)
            ->get(['userid', 'serviceid', 'service_type']);

        // Normalise service_type the SAME way the SQL does: the column is
        // utf8mb4_unicode_ci, so where('service_type','hosting') matches
        // 'Hosting'/'HOSTING'/'hosting ' too. Match that here (strtolower+trim) or
        // a legacy mixed-case route would read «live» in the list yet be counted
        // dead and DELETED by the purge — the exact divergence to avoid.
        $hostIds = [];
        $domIds = [];
        foreach ($routes as $r) {
            $sid = (int) $r->serviceid;
            $t = strtolower(trim((string) $r->service_type));
            if ($t === 'hosting') {
                $hostIds[$sid] = true;
            } elseif ($t === 'domain') {
                $domIds[$sid] = true;
            }
        }
        $hostLabel = $hostIds === [] ? []
            : Capsule::table('tblhosting')->whereIn('id', array_keys($hostIds))->pluck('domain', 'id')->all();
        $domLabel = $domIds === [] ? []
            : Capsule::table('tbldomains')->whereIn('id', array_keys($domIds))->pluck('domain', 'id')->all();

        // Guard the «dead» verdict against an EMPTY service table (fresh install /
        // transient during a reimport): if the table has no rows at all we can't
        // tell «service deleted» from «not populated yet», so we never call that
        // type dead — matching purgeOrphans(), which skips it for the same reason.
        $hasHosting = self::tableHasRows('tblhosting');
        $hasDomains = self::tableHasRows('tbldomains');

        foreach ($routes as $r) {
            $uid = (int) $r->userid;
            $sid = (int) $r->serviceid;
            $type = strtolower(trim((string) $r->service_type));
            $out[$uid] ??= ['labels' => [], 'dead' => 0];

            // «dead» must mean the SAME thing the purge does: the service ROW is
            // GONE (existence gap), NOT merely a blank domain label. A live
            // hosting product with an empty domain is valid — show it as «#id».
            // Unknown service types can't be existence-checked here (the purge
            // leaves them), so they are never counted dead.
            if ($type === 'hosting' || $type === 'domain') {
                $map = $type === 'hosting' ? $hostLabel : $domLabel;
                $tableHasRows = $type === 'hosting' ? $hasHosting : $hasDomains;
                if (array_key_exists($sid, $map)) {
                    $label = trim((string) ($map[$sid] ?? ''));
                    $out[$uid]['labels'][] = $label !== '' ? $label : ('#'.$sid);
                } elseif ($tableHasRows) {
                    $out[$uid]['dead']++;   // service row truly gone
                } else {
                    $out[$uid]['labels'][] = '#'.$sid;   // can't verify → not "dead"
                }
            } else {
                $out[$uid]['labels'][] = '#'.$sid;
            }
        }
        foreach ($out as $uid => $v) {
            $out[$uid]['labels'] = array_values(array_unique($v['labels']));
        }

        return $out;
    }

    /**
     * Read-only count of ORPHAN rows — rows in our tables that reference a WHMCS
     * client / service / contact that no longer exists (no FKs, no cascade on
     * WHMCS delete). Powers the admin list's orphan badge + the «Καθαρισμός»
     * button decision. Categories can OVERLAP (a deleted client's route to a
     * dead service counts in two rows), so `total` is a sum for a boolean
     * "any orphans?" — the purge reports the authoritative deleted count.
     *
     * @return array{contacts_deleted_client:int, routes_deleted_client:int,
     *               routes_dead_service:int, total:int}
     */
    public static function orphanSummary(): array
    {
        $z = [
            'contacts_deleted_client' => 0, 'routes_deleted_client' => 0,
            'routes_dead_service' => 0, 'total' => 0,
        ];
        if (! self::hasOwnTables()) {
            return $z;
        }

        // Each existence check is guarded by the reference table being non-empty
        // (see tableHasRows): an empty tblclients/tblhosting/tbldomains — fresh
        // install or a transient reimport window — must NOT make every row look
        // orphaned (`NOT IN (empty)` is TRUE for all rows).
        if (self::tableHasRows('tblclients')) {
            $z['contacts_deleted_client'] = (int) Capsule::table(self::CONTACTS)
                ->whereNotIn('userid', fn ($q) => $q->select('id')->from('tblclients'))->count();
            $z['routes_deleted_client'] = (int) Capsule::table(self::ROUTING)
                ->whereNotIn('userid', fn ($q) => $q->select('id')->from('tblclients'))->count();
        }
        if (self::tableHasRows('tblhosting')) {
            $z['routes_dead_service'] += (int) Capsule::table(self::ROUTING)
                ->where('service_type', 'hosting')
                ->whereNotIn('serviceid', fn ($q) => $q->select('id')->from('tblhosting'))->count();
        }
        if (self::tableHasRows('tbldomains')) {
            $z['routes_dead_service'] += (int) Capsule::table(self::ROUTING)
                ->where('service_type', 'domain')
                ->whereNotIn('serviceid', fn ($q) => $q->select('id')->from('tbldomains'))->count();
        }
        $z['total'] = $z['contacts_deleted_client'] + $z['routes_deleted_client']
            + $z['routes_dead_service'];

        return $z;
    }

    /** True if a table has at least one row (guards NOT-IN-empty-subquery false positives). */
    private static function tableHasRows(string $table): bool
    {
        try {
            return Capsule::table($table)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * DESTRUCTIVE: delete orphan rows — routes/contacts that can never match a
     * real invoice because the WHMCS client or the WHMCS service they reference
     * no longer exists — the SAME two categories the admin list surfaces and
     * orphanSummary() counts. Scoped STRICTLY to those existence gaps (never
     * touches a row that still resolves), transactional, and safe to re-run.
     * Subqueries only ever read OTHER tables than the delete target (no MySQL
     * same-table-delete conflict).
     *
     * @return array{contacts_deleted:int, routes_deleted:int}
     */
    public static function purgeOrphans(): array
    {
        $out = ['contacts_deleted' => 0, 'routes_deleted' => 0];
        if (! self::hasOwnTables()) {
            return $out;
        }

        Capsule::connection()->transaction(function () use (&$out) {
            // Routes first (they reference contacts). Each delete removes rows the
            // next condition would also catch, so the counts never double. Scope
            // matches EXACTLY what the list surfaces + orphanSummary() counts:
            // deleted-client and dead-service. (A live client's route to a deleted
            // CONTACT — «dangling» — is not purged: it can't occur through the
            // cascade/validation paths, is inert if it somehow does, and the list
            // never shows it, so purging it silently would be a surprise.)
            // Each delete is gated on its reference table being NON-EMPTY, so an
            // empty tblclients/tblhosting/tbldomains (fresh install / transient
            // reimport) can never turn `NOT IN (empty)` into "delete everything".
            $hasClients = self::tableHasRows('tblclients');
            if ($hasClients) {
                $out['routes_deleted'] += (int) Capsule::table(self::ROUTING)
                    ->whereNotIn('userid', fn ($q) => $q->select('id')->from('tblclients'))->delete();
            }
            if (self::tableHasRows('tblhosting')) {
                $out['routes_deleted'] += (int) Capsule::table(self::ROUTING)
                    ->where('service_type', 'hosting')
                    ->whereNotIn('serviceid', fn ($q) => $q->select('id')->from('tblhosting'))->delete();
            }
            if (self::tableHasRows('tbldomains')) {
                $out['routes_deleted'] += (int) Capsule::table(self::ROUTING)
                    ->where('service_type', 'domain')
                    ->whereNotIn('serviceid', fn ($q) => $q->select('id')->from('tbldomains'))->delete();
            }

            // Then orphan contacts (WHMCS client gone) — same empty-table guard.
            if ($hasClients) {
                $out['contacts_deleted'] += (int) Capsule::table(self::CONTACTS)
                    ->whereNotIn('userid', fn ($q) => $q->select('id')->from('tblclients'))->delete();
            }
        });

        return $out;
    }

    // ----------------------------------------------------------------------
    // Admin-side helpers (addon admin page). The admin operator is trusted
    // (WHMCS admin auth + CSRF at the controller), so the by-id reads/deletes
    // below are NOT userid-scoped — they act on a specific row the operator
    // picked from a rendered list (a client's contact, an orphan row). The
    // per-client contact CREATE/UPDATE reuse the userid-scoped *ForUser methods
    // (the admin page is always in one client's context).
    // ----------------------------------------------------------------------

    /** One contact by id (any client) — for the admin edit form. */
    public static function contactById(int $id): ?object
    {
        return Capsule::table(self::CONTACTS)->where('id', $id)->first();
    }

    /**
     * Delete one contact by id AND cascade its routing rows (a route with no
     * billing identity is meaningless). Admin-only; returns true if it existed.
     */
    public static function deleteContactById(int $id): bool
    {
        if (self::contactById($id) === null) {
            return false;
        }
        Capsule::connection()->transaction(function () use ($id) {
            Capsule::table(self::ROUTING)->where('contactid', $id)->delete();
            Capsule::table(self::CONTACTS)->where('id', $id)->delete();
        });

        return true;
    }

    /** Delete one routing row by id. Admin-only. Returns true if it existed. */
    public static function deleteRouteById(int $id): bool
    {
        return Capsule::table(self::ROUTING)->where('id', $id)->delete() > 0;
    }

    /**
     * A client's routing rows whose SERVICE no longer exists (dead) —
     * hosting/domain serviceid gone from tblhosting/tbldomains.
     * servicesForUser() lists only LIVE services, so these dead routes are
     * otherwise invisible AND unmanageable on the per-client page. Each carries
     * the contact it routes to (name) so the operator can decide. Guarded by the
     * reference table being non-empty (same rule as orphanSummary/purgeOrphans),
     * so a transient empty table never flags a live route dead.
     *
     * @return array<int, array{id:int, serviceid:int, service_type:string, contactid:int, contact_name:string}>
     */
    public static function deadServiceRoutesForUser(int $userid): array
    {
        if (! self::hasOwnTables()) {
            return [];
        }
        $rows = Capsule::table(self::ROUTING)->where('userid', $userid)->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $hasHosting = self::tableHasRows('tblhosting');
        $hasDomains = self::tableHasRows('tbldomains');
        $names = self::contactNamesFor($rows);

        $out = [];
        foreach ($rows as $r) {
            $type = strtolower(trim((string) $r->service_type));
            $sid = (int) $r->serviceid;
            $dead = false;
            if ($type === 'hosting' && $hasHosting) {
                $dead = ! Capsule::table('tblhosting')->where('id', $sid)->exists();
            } elseif ($type === 'domain' && $hasDomains) {
                $dead = ! Capsule::table('tbldomains')->where('id', $sid)->exists();
            }
            if ($dead) {
                $out[] = [
                    'id' => (int) $r->id,
                    'serviceid' => $sid,
                    'service_type' => $type,
                    'contactid' => (int) $r->contactid,
                    'contact_name' => (string) ($names[(int) $r->contactid] ?? ('#'.(int) $r->contactid)),
                ];
            }
        }

        return $out;
    }

    /**
     * Detailed orphan rows for the admin «Ορφανά» page — the SAME existence gaps
     * orphanSummary() counts and purgeOrphans() deletes, but ENUMERATED so the
     * operator sees exactly which rows before deleting, with per-row delete. Two
     * lists: orphan contacts (WHMCS client gone) and orphan routes (client gone
     * OR service dead). Every existence check is guarded by the reference table
     * being non-empty, so a fresh/empty install never reports everything orphan.
     *
     * @return array{contacts: array<int, array{id:int,userid:int,company_name:string,gr_vatno:string}>,
     *               routes: array<int, array{id:int,userid:int,serviceid:int,service_type:string,contact_name:string,reason:string}>}
     */
    public static function orphanRows(): array
    {
        $out = ['contacts' => [], 'routes' => []];
        if (! self::hasOwnTables()) {
            return $out;
        }

        $hasClients = self::tableHasRows('tblclients');
        $hasHosting = self::tableHasRows('tblhosting');
        $hasDomains = self::tableHasRows('tbldomains');

        // null = "can't verify" (empty tblclients) → never flag a deleted-client orphan.
        $liveClients = $hasClients
            ? array_flip(array_map('intval', Capsule::table('tblclients')->pluck('id')->all()))
            : null;

        // Orphan contacts: WHMCS client gone.
        if ($liveClients !== null) {
            foreach (Capsule::table(self::CONTACTS)->get() as $c) {
                if (! isset($liveClients[(int) $c->userid])) {
                    $out['contacts'][] = [
                        'id' => (int) $c->id,
                        'userid' => (int) $c->userid,
                        'company_name' => (string) ($c->company_name ?? ''),
                        'gr_vatno' => (string) ($c->gr_vatno ?? ''),
                    ];
                }
            }
        }

        // Orphan routes: client gone OR service dead. Preload the live service id
        // sets for the relids present (2 queries) — no per-row exists() fan-out.
        $routes = Capsule::table(self::ROUTING)->get();
        $names = self::contactNamesFor($routes);
        $hostIds = [];
        $domIds = [];
        foreach ($routes as $r) {
            $t = strtolower(trim((string) $r->service_type));
            $sid = (int) $r->serviceid;
            if ($t === 'hosting') {
                $hostIds[$sid] = true;
            } elseif ($t === 'domain') {
                $domIds[$sid] = true;
            }
        }
        $liveHost = ($hasHosting && $hostIds !== [])
            ? array_flip(array_map('intval', Capsule::table('tblhosting')->whereIn('id', array_keys($hostIds))->pluck('id')->all()))
            : [];
        $liveDom = ($hasDomains && $domIds !== [])
            ? array_flip(array_map('intval', Capsule::table('tbldomains')->whereIn('id', array_keys($domIds))->pluck('id')->all()))
            : [];

        foreach ($routes as $r) {
            $uid = (int) $r->userid;
            $sid = (int) $r->serviceid;
            $type = strtolower(trim((string) $r->service_type));

            $clientGone = $liveClients !== null && ! isset($liveClients[$uid]);
            $serviceDead = false;
            if ($type === 'hosting' && $hasHosting) {
                $serviceDead = ! isset($liveHost[$sid]);
            } elseif ($type === 'domain' && $hasDomains) {
                $serviceDead = ! isset($liveDom[$sid]);
            }
            if (! $clientGone && ! $serviceDead) {
                continue;
            }

            $reasons = [];
            if ($clientGone) {
                $reasons[] = 'διαγρ. πελάτης';
            }
            if ($serviceDead) {
                $reasons[] = 'νεκρή υπηρεσία';
            }
            $out['routes'][] = [
                'id' => (int) $r->id,
                'userid' => $uid,
                'serviceid' => $sid,
                'service_type' => $type,
                'contact_name' => (string) ($names[(int) $r->contactid] ?? ('#'.(int) $r->contactid)),
                'reason' => implode(' + ', $reasons),
            ];
        }

        return $out;
    }

    /**
     * contactid => company_name for a collection of routing rows (one query).
     *
     * @param  iterable<object>  $routes
     * @return array<int, string>
     */
    private static function contactNamesFor($routes): array
    {
        $ids = [];
        foreach ($routes as $r) {
            $ids[(int) $r->contactid] = true;
        }
        if ($ids === []) {
            return [];
        }

        return Capsule::table(self::CONTACTS)
            ->whereIn('id', array_keys($ids))
            ->pluck('company_name', 'id')->all();
    }

    /**
     * Shape an own-contacts row into the resolve.php contact object. Values
     * are returned AS STORED (ekdosi decodes HTML entities when it
     * materialises a Customer).
     *
     * @return array<string, mixed>
     */
    public static function contactArray($row): array
    {
        return [
            'id' => (int) $row->id,
            'company_name' => (string) ($row->company_name ?? ''),
            'gr_vatno' => (string) ($row->gr_vatno ?? ''),
            'vies_vatno' => (string) ($row->vies_vatno ?? ''),
            'tax_office' => (string) ($row->tax_office ?? ''),
            'address1' => (string) ($row->address1 ?? ''),
            'address2' => (string) ($row->address2 ?? ''),
            'city' => (string) ($row->city ?? ''),
            'postal_code' => (string) ($row->postal_code ?? ''),
            'country' => (string) ($row->country ?? ''),
            'description' => (string) ($row->description ?? ''),
            'email' => (string) ($row->email ?? ''),
            'telephone' => (string) ($row->telephone ?? ''),
        ];
    }

    /** Trim a legacy value to our column width (legacy widths sometimes differ). */
    private static function clip($value, int $len): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;

        return mb_substr($s, 0, $len);
    }

    // ----------------------------------------------------------------------
    // T-2: client-area CRUD. All methods are scoped by $userid (the logged-in
    // WHMCS client) — a client can only ever see/edit/route their OWN rows.
    // v2-created rows have legacy_contact_id/legacy_routing_id = NULL, so the
    // legacy→own sync never touches them. Writes go to the OWN tables only
    // (decision: "v2 writes own tables only"); the legacy mod_timologia* are
    // not written here.
    // ----------------------------------------------------------------------

    /** The fields a client may set on a contact. */
    public const CONTACT_FIELDS = [
        'company_name', 'gr_vatno', 'vies_vatno', 'tax_office',
        'address1', 'address2', 'city', 'postal_code', 'country',
        'description', 'email', 'telephone',
    ];

    /** @return array<int, object> the client's own contacts. */
    public static function contactsForUser(int $userid): array
    {
        return Capsule::table(self::CONTACTS)
            ->where('userid', $userid)
            ->orderBy('company_name')
            ->get()
            ->all();
    }

    /** A single contact, but only if it belongs to $userid (ownership guard). */
    public static function contactForUser(int $userid, int $id): ?object
    {
        return Capsule::table(self::CONTACTS)
            ->where('id', $id)
            ->where('userid', $userid)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return int the new contact id
     */
    public static function createContactForUser(int $userid, array $input): int
    {
        self::ensureTables();
        $now = date('Y-m-d H:i:s');

        return (int) Capsule::table(self::CONTACTS)->insertGetId(
            self::contactInput($input) + [
                'userid' => $userid,
                'source' => 'v2',
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    /** Update one of $userid's contacts. Returns false if it isn't theirs. */
    public static function updateContactForUser(int $userid, int $id, array $input): bool
    {
        if (self::contactForUser($userid, $id) === null) {
            return false;
        }
        Capsule::table(self::CONTACTS)
            ->where('id', $id)
            ->where('userid', $userid)
            ->update(self::contactInput($input) + ['updated_at' => date('Y-m-d H:i:s')]);

        return true;
    }

    /**
     * Delete one of $userid's contacts AND cascade its routing rows (a route
     * with no billing identity is meaningless). Scoped by userid throughout.
     */
    public static function deleteContactForUser(int $userid, int $id): bool
    {
        if (self::contactForUser($userid, $id) === null) {
            return false;
        }
        Capsule::connection()->transaction(function () use ($userid, $id) {
            Capsule::table(self::ROUTING)
                ->where('userid', $userid)
                ->where('contactid', $id)
                ->delete();
            Capsule::table(self::CONTACTS)
                ->where('id', $id)
                ->where('userid', $userid)
                ->delete();
        });

        return true;
    }

    /**
     * The client's services (hosting + domains) with any current routing.
     * Used by the routing UI — "for each service, who is billed?".
     *
     * @return array<int, array{serviceid:int, service_type:string, label:string,
     *               contactid:?int, is_receipt:bool}>
     */
    public static function servicesForUser(int $userid): array
    {
        $routes = [];
        foreach (Capsule::table(self::ROUTING)->where('userid', $userid)->get() as $r) {
            $routes[$r->service_type.':'.(int) $r->serviceid] = $r;
        }

        $services = [];
        foreach (Capsule::table('tblhosting')->where('userid', $userid)->get(['id', 'domain']) as $h) {
            $services[] = self::serviceRow($h, 'hosting', $routes);
        }
        foreach (Capsule::table('tbldomains')->where('userid', $userid)->get(['id', 'domain']) as $d) {
            $services[] = self::serviceRow($d, 'domain', $routes);
        }

        return $services;
    }

    /**
     * Route a service to one of the client's contacts (one contact per
     * service — upsert). BOTH the contact AND the service must belong to
     * $userid (so a crafted POST can't create routing rows pointing at another
     * client's service id — those would be inert, but we refuse them anyway).
     * Returns false if either isn't theirs.
     *
     * NOTE: there is intentionally NO DB unique key on
     * (userid, serviceid, service_type) — the legacy sync inserts here too and
     * a hard constraint would abort a whole sync on any dirty/duplicate legacy
     * row. The read-then-write upsert is sufficient for the single-client v2
     * page (worst case: a double-click leaves a duplicate that resolveInvoice
     * collapses via ->first()).
     */
    public static function setRouteForUser(int $userid, int $serviceid, string $serviceType, int $contactid, bool $isReceipt): bool
    {
        if (self::contactForUser($userid, $contactid) === null) {
            return false;
        }
        if (! self::clientOwnsService($userid, $serviceid, $serviceType)) {
            return false;
        }
        self::ensureTables();
        $now = date('Y-m-d H:i:s');

        $existing = Capsule::table(self::ROUTING)
            ->where('userid', $userid)
            ->where('serviceid', $serviceid)
            ->where('service_type', $serviceType)
            ->first();

        $data = ['contactid' => $contactid, 'is_receipt' => (int) $isReceipt, 'updated_at' => $now];
        if ($existing) {
            Capsule::table(self::ROUTING)->where('id', $existing->id)->update($data);
        } else {
            Capsule::table(self::ROUTING)->insert($data + [
                'userid' => $userid, 'serviceid' => $serviceid, 'service_type' => $serviceType,
                'source' => 'v2', 'created_at' => $now,
            ]);
        }

        return true;
    }

    /** Does this service (hosting/domain id) belong to the client? */
    private static function clientOwnsService(int $userid, int $serviceid, string $serviceType): bool
    {
        $table = $serviceType === 'hosting' ? 'tblhosting' : ($serviceType === 'domain' ? 'tbldomains' : null);
        if ($table === null) {
            return false;
        }

        return Capsule::table($table)
            ->where('id', $serviceid)
            ->where('userid', $userid)
            ->exists();
    }

    /** Clear the routing for a service → bill the client themselves (default). */
    public static function clearRouteForUser(int $userid, int $serviceid, string $serviceType): void
    {
        Capsule::table(self::ROUTING)
            ->where('userid', $userid)
            ->where('serviceid', $serviceid)
            ->where('service_type', $serviceType)
            ->delete();
    }

    /** Whitelist + width-clip the client-supplied contact fields. */
    private static function contactInput(array $input): array
    {
        $widths = [
            'company_name' => 191, 'gr_vatno' => 30, 'vies_vatno' => 60, 'tax_office' => 120,
            'address1' => 120, 'address2' => 120, 'city' => 120, 'postal_code' => 20,
            'country' => 60, 'description' => 191, 'email' => 120, 'telephone' => 40,
        ];
        $out = [];
        foreach (self::CONTACT_FIELDS as $f) {
            $trimmed = trim((string) ($input[$f] ?? ''));
            // === '' (not ?:) so a legitimately falsy value like "0" survives.
            $out[$f] = $trimmed === '' ? null : self::clip($trimmed, $widths[$f]);
        }

        return $out;
    }

    private static function serviceRow($svc, string $type, array $routes): array
    {
        $route = $routes[$type.':'.(int) $svc->id] ?? null;

        return [
            'serviceid' => (int) $svc->id,
            'service_type' => $type,
            'label' => (string) ($svc->domain ?? ('#'.$svc->id)),
            'contactid' => $route ? (int) $route->contactid : null,
            'is_receipt' => $route ? (bool) ((int) $route->is_receipt) : false,
        ];
    }
}
