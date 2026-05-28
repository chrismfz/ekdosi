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
     * service — upsert). $contactid MUST belong to $userid. Returns false if
     * the contact isn't theirs.
     */
    public static function setRouteForUser(int $userid, int $serviceid, string $serviceType, int $contactid, bool $isReceipt): bool
    {
        if (self::contactForUser($userid, $contactid) === null) {
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
            $out[$f] = self::clip(trim((string) ($input[$f] ?? '')), $widths[$f]) ?: null;
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
