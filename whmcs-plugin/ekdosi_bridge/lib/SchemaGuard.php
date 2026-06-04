<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use Throwable;
use WHMCS\Database\Capsule;

/**
 * Self-healing schema guard — so the addon NEVER needs a manual
 * deactivate/reactivate to pick up a schema change.
 *
 * WHMCS `require()`s the addon's PHP fresh on every request, so a code-only
 * update is just an upload. The only thing that historically forced the
 * deactivate→reactivate dance was `_activate()` running the schema steps
 * (create our tables, narrow tblinvoices.invoiced back to SMALLINT). And WHMCS
 * core WIPES the addon's `tbladdonmodules` settings on every deactivate — so
 * that dance also cost the operator the bridge config (URL / slug / secret)
 * each time.
 *
 * This guard moves those steps to run on every admin page load (see
 * `ekdosi_bridge_output`). It is intentionally STATELESS — no stored schema
 * version to corrupt or lose on a settings wipe — because every step is both
 * cheap and idempotent:
 *   - `CREATE TABLE IF NOT EXISTS` for our own tables (no-op when present),
 *   - a single information_schema lookup that only triggers the one-time
 *     `tblinvoices.invoiced` BIGINT→SMALLINT narrowing if we ever widened it
 *     (essentially never, after the historical fix).
 *
 * Every step is privilege-safe: a missing ALTER/CREATE grant degrades to a
 * note (and a swallowed throwable on page load), never a fatal that could
 * break the admin page.
 */
class SchemaGuard
{
    /**
     * Run the idempotent schema heal. Cheap enough for every admin page load.
     *
     * @return string[] human-readable notes (surfaced on activate; ignored on
     *                  the silent page-load path)
     */
    public static function ensure(): array
    {
        $notes = [];

        // 1. Keep the AADE MARK in our OWN table and RESTORE tblinvoices.invoiced
        //    to the SMALLINT the legacy ekdosi app expects (we never write it now;
        //    only read it). Idempotent + privilege-safe. See ekdosi_bridge.php
        //    history for the full "why".
        try {
            InvoiceMarkStore::ensureTable();
            $notes[] = 'Mark table (mod_ekdosi_invoice_marks) ready.';

            $col = Capsule::selectOne(
                "SELECT DATA_TYPE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'tblinvoices'
                   AND COLUMN_NAME = 'invoiced'"
            );
            $type = $col ? strtolower((string) $col->DATA_TYPE) : '';

            if ($type === 'bigint') {
                $moved = InvoiceMarkStore::migrateFromInvoicedColumn();
                Capsule::table('tblinvoices')->where('invoiced', '>', 65535)->update(['invoiced' => 1]);
                Capsule::statement('UPDATE tblinvoices SET invoiced = 0 WHERE invoiced IS NULL');
                Capsule::statement('ALTER TABLE tblinvoices MODIFY invoiced SMALLINT(5) NOT NULL DEFAULT 0');
                $notes[] = "Restored tblinvoices.invoiced to SMALLINT (moved {$moved} MARK(s) into "
                    .'mod_ekdosi_invoice_marks; the legacy app reads `invoiced` again).';
            } elseif ($type === '') {
                $notes[] = 'tblinvoices.invoiced not found — nothing to restore.';
            } else {
                $notes[] = "tblinvoices.invoiced is {$type} (not widened by us) — left untouched.";
            }
        } catch (Throwable $e) {
            // Don't fail — the operator may lack ALTER privileges (managed
            // hosting). Surface the exact manual SQL so a DBA can run it.
            $notes[] = 'WARNING: could not auto-restore tblinvoices.invoiced ('
                .$e->getMessage().'). If it is BIGINT, run manually: '
                .'CREATE TABLE IF NOT EXISTS mod_ekdosi_invoice_marks ('
                .'invoiceid BIGINT UNSIGNED NOT NULL PRIMARY KEY, mark VARCHAR(40) NOT NULL, '
                .'invcode VARCHAR(60) NULL DEFAULT NULL, updated_at DATETIME NULL DEFAULT NULL) '
                .'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4; '
                .'INSERT INTO mod_ekdosi_invoice_marks (invoiceid, mark, updated_at) '
                .'SELECT id, invoiced, NOW() FROM tblinvoices WHERE invoiced > 65535 '
                .'ON DUPLICATE KEY UPDATE mark = VALUES(mark); '
                .'UPDATE tblinvoices SET invoiced = 1 WHERE invoiced > 65535; '
                .'UPDATE tblinvoices SET invoiced = 0 WHERE invoiced IS NULL; '
                .'ALTER TABLE tblinvoices MODIFY invoiced SMALLINT(5) NOT NULL DEFAULT 0;';
        }

        // 2. The bridge's own third-party-invoicing tables
        //    (mod_ekdosi_contacts / mod_ekdosi_routing). Idempotent; seeded later
        //    by the admin "Sync from legacy timologia" action.
        try {
            ThirdPartyStore::ensureTables();
            $notes[] = 'Third-party tables (mod_ekdosi_contacts / mod_ekdosi_routing) ready.';
        } catch (Throwable $e) {
            $notes[] = 'WARNING: could not create mod_ekdosi_* tables ('.$e->getMessage()
                .'). Use the "Sync from legacy timologia" admin action once DB privileges allow.';
        }

        // 3. The Plugin-API request log (mod_ekdosi_bridge_log) — visibility for
        //    what ekdosi asks resolve.php. Idempotent; best-effort.
        try {
            BridgeLogStore::ensureTable();
            $notes[] = 'Bridge log table (mod_ekdosi_bridge_log) ready.';
        } catch (Throwable $e) {
            $notes[] = 'WARNING: could not create mod_ekdosi_bridge_log ('.$e->getMessage().').';
        }

        return $notes;
    }

    /**
     * Page-load entry: heal silently, never let a schema hiccup break the admin
     * page. Notes are dropped here (the activate path surfaces them instead).
     *
     * Hot-path cheap: a single information_schema probe runs first; only when
     * something is actually missing (or tblinvoices.invoiced is still BIGINT) do
     * we fall through to ensure() and its CREATE/ALTER. So the common case
     * issues ONE metadata SELECT and NO DDL — important because DDL implicitly
     * commits any open transaction.
     */
    public static function ensureSilently(): void
    {
        try {
            if (self::schemaLooksReady()) {
                return;
            }
            self::ensure();
        } catch (Throwable $e) {
            // Last-resort guard — ensure() already swallows per-step failures.
        }
    }

    /**
     * Cheap, no-DDL probe: are our three tables + the invcode column present,
     * and is tblinvoices.invoiced already non-BIGINT? When all true we can skip
     * ensure() entirely (no CREATE/ALTER, hence no implicit COMMIT on the hot
     * admin path). Any uncertainty (probe error, missing object, BIGINT column)
     * → false → ensure() runs (itself idempotent + privilege-safe).
     */
    private static function schemaLooksReady(): bool
    {
        try {
            $marks = InvoiceMarkStore::TABLE;
            $contacts = ThirdPartyStore::CONTACTS;
            $routing = ThirdPartyStore::ROUTING;
            $bridgeLog = BridgeLogStore::TABLE;

            $row = Capsule::selectOne(
                'SELECT
                    (SELECT COUNT(*) FROM information_schema.TABLES
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?, ?)) AS tbls,
                    (SELECT COUNT(*) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (\'invcode\', \'state\')) AS mark_cols,
                    (SELECT LOWER(DATA_TYPE) FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'tblinvoices\' AND COLUMN_NAME = \'invoiced\') AS invoiced_type',
                [$marks, $contacts, $routing, $bridgeLog, $marks]
            );
            if ($row === null) {
                return false;
            }

            // invoiced absent ('' ) is fine — nothing to restore. Only BIGINT
            // forces the heavy ensure() branch. mark_cols must be 2 (invcode +
            // state) so a pre-state table still triggers ensure() to ALTER it in.
            return (int) $row->tbls === 4
                && (int) $row->mark_cols === 2
                && (string) ($row->invoiced_type ?? '') !== 'bigint';
        } catch (Throwable $e) {
            return false;
        }
    }
}
