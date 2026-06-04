<?php

namespace WHMCS\Module\Addon\EkdosiBridge;

use WHMCS\Database\Capsule;

/**
 * The AADE MARK store — OUR OWN table, so the bridge never has to touch the
 * shared `tblinvoices.invoiced` column again.
 *
 * Background / the γκάφα this fixes: an earlier version of this plugin widened
 * `tblinvoices.invoiced` (a SMALLINT the LEGACY ekdosi app + prepare_for_ekdosi
 * use as a {0,1} "invoiced/processed" flag) to BIGINT so it could stuff the
 * 15-digit MARK in. That broke the legacy app, which expects `invoiced` to be
 * SMALLINT. We now keep the MARK HERE and leave `invoiced` strictly to the
 * legacy side (we only READ it, to surface "Invoiced in legacy app").
 *
 * One row per WHMCS invoice that ekdosi filed at AADE: { invoiceid → mark }.
 */
class InvoiceMarkStore
{
    public const TABLE = 'mod_ekdosi_invoice_marks';

    /** Create the table if absent. Idempotent. */
    public static function ensureTable(): void
    {
        Capsule::statement(
            'CREATE TABLE IF NOT EXISTS '.self::TABLE.' (
                invoiceid BIGINT UNSIGNED NOT NULL,
                mark VARCHAR(40) NOT NULL,
                invcode VARCHAR(60) NULL DEFAULT NULL,
                state VARCHAR(20) NULL DEFAULT NULL,
                pdf_url VARCHAR(255) NULL DEFAULT NULL,
                updated_at DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (invoiceid)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        // The `invcode` column was added after the table first shipped (it
        // carries the ekdosi ΤΠΥ — e.g. ΑΠΥ423 — shown next to the MARK). Add
        // it to pre-existing tables; IF NOT EXISTS keeps it idempotent on the
        // versions of MariaDB the tenants run.
        try {
            Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN IF NOT EXISTS invcode VARCHAR(60) NULL DEFAULT NULL AFTER mark');
        } catch (\Throwable $e) {
            // Older MariaDB without ADD COLUMN IF NOT EXISTS, or no ALTER
            // privilege — fall back to a probe, and ignore "already there".
            if (! self::hasInvcodeColumn()) {
                try {
                    Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN invcode VARCHAR(60) NULL DEFAULT NULL AFTER mark');
                } catch (\Throwable $ignored) {
                    // Last resort: leave the column absent. set()/get() guard
                    // against it so the MARK still stores; only invcode is lost.
                }
            }
        }

        // `state` (active/cancelled) added later still — lets the WHMCS badges
        // show «ΑΚΥΡΩΜΕΝΟ» after ekdosi cancels at AADE. Same idempotent +
        // privilege-safe ALTER pattern as invcode.
        try {
            Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN IF NOT EXISTS state VARCHAR(20) NULL DEFAULT NULL AFTER invcode');
        } catch (\Throwable $e) {
            if (! self::hasStateColumn()) {
                try {
                    Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN state VARCHAR(20) NULL DEFAULT NULL AFTER invcode');
                } catch (\Throwable $ignored) {
                    // set()/stateFor() guard against absence — MARK still stores.
                }
            }
        }

        // `pdf_url` (signed link to the official ekdosi παραστατικό PDF) added
        // later still. Same idempotent + privilege-safe ALTER pattern.
        try {
            Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN IF NOT EXISTS pdf_url VARCHAR(255) NULL DEFAULT NULL AFTER state');
        } catch (\Throwable $e) {
            if (! self::hasPdfUrlColumn()) {
                try {
                    Capsule::statement('ALTER TABLE '.self::TABLE.' ADD COLUMN pdf_url VARCHAR(255) NULL DEFAULT NULL AFTER state');
                } catch (\Throwable $ignored) {
                    // set()/pdfUrlFor() guard against absence — MARK still stores.
                }
            }
        }
    }

    /** Per-request cache of column-existence probes (information_schema is slow). */
    private static array $columnCache = [];

    private static function hasColumn(string $column): bool
    {
        if (array_key_exists($column, self::$columnCache)) {
            return self::$columnCache[$column];
        }
        try {
            $col = Capsule::selectOne(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                [self::TABLE, $column]
            );

            return self::$columnCache[$column] = ($col !== null);
        } catch (\Throwable $e) {
            return false;   // don't cache a transient failure
        }
    }

    /**
     * All stored columns for one invoice in a SINGLE query (vs four separate
     * get()/invcodeFor()/stateFor()/pdfUrlFor() round-trips). Absent optional
     * columns come back null. Returns null mark when there's no row.
     *
     * @return array{mark:?string, invcode:?string, state:?string, pdf_url:?string}
     */
    public static function row(int $invoiceId): array
    {
        $select = ['mark'];
        foreach (['invcode', 'state', 'pdf_url'] as $c) {
            if (self::hasColumn($c)) {
                $select[] = $c;
            }
        }
        $r = Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->first($select);

        return [
            'mark' => ($r->mark ?? null) !== null && $r->mark !== '' ? (string) $r->mark : null,
            'invcode' => ($r->invcode ?? null) !== null && $r->invcode !== '' ? (string) $r->invcode : null,
            'state' => ($r->state ?? null) !== null && $r->state !== '' ? (string) $r->state : null,
            'pdf_url' => ($r->pdf_url ?? null) !== null && $r->pdf_url !== '' ? (string) $r->pdf_url : null,
        ];
    }

    private static function hasInvcodeColumn(): bool
    {
        return self::hasColumn('invcode');
    }

    private static function hasStateColumn(): bool
    {
        return self::hasColumn('state');
    }

    private static function hasPdfUrlColumn(): bool
    {
        return self::hasColumn('pdf_url');
    }

    public static function hasTable(): bool
    {
        try {
            return Capsule::schema()->hasTable(self::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** The MARK ekdosi filed for this WHMCS invoice, or null if none. */
    public static function get(int $invoiceId): ?string
    {
        $v = Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->value('mark');

        return $v !== null ? (string) $v : null;
    }

    /** The ekdosi ΤΠΥ (invcode, e.g. ΑΠΥ423) for this WHMCS invoice, or null. */
    public static function invcodeFor(int $invoiceId): ?string
    {
        if (! self::hasInvcodeColumn()) {
            return null;
        }
        $v = Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->value('invcode');

        return ($v !== null && $v !== '') ? (string) $v : null;
    }

    /** The AADE state ekdosi last wrote for this WHMCS invoice ('active'/'cancelled'), or null. */
    public static function stateFor(int $invoiceId): ?string
    {
        if (! self::hasStateColumn()) {
            return null;
        }
        $v = Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->value('state');

        return ($v !== null && $v !== '') ? (string) $v : null;
    }

    /** The signed public URL to the official ekdosi παραστατικό PDF, or null. */
    public static function pdfUrlFor(int $invoiceId): ?string
    {
        if (! self::hasPdfUrlColumn()) {
            return null;
        }
        $v = Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->value('pdf_url');

        return ($v !== null && $v !== '') ? (string) $v : null;
    }

    /**
     * Upsert the MARK for an invoice (string — never int-cast a 15-digit MARK).
     * Optionally also stores the ekdosi ΤΠΥ (`invcode`, e.g. ΑΠΥ423), the AADE
     * `state` ('active'/'cancelled'), and a signed `pdfUrl` to the official PDF.
     * Each extra-column write is guarded so a tenant whose table predates the
     * column (and couldn't be ALTERed) still stores the MARK.
     */
    public static function set(int $invoiceId, string $mark, ?string $invcode = null, ?string $state = null, ?string $pdfUrl = null): void
    {
        $values = ['mark' => $mark, 'updated_at' => date('Y-m-d H:i:s')];
        if ($invcode !== null && $invcode !== '' && self::hasInvcodeColumn()) {
            $values['invcode'] = $invcode;
        }
        if ($state !== null && $state !== '' && self::hasStateColumn()) {
            $values['state'] = $state;
        }
        if ($pdfUrl !== null && $pdfUrl !== '' && self::hasPdfUrlColumn()) {
            $values['pdf_url'] = $pdfUrl;
        }

        Capsule::table(self::TABLE)->updateOrInsert(
            ['invoiceid' => $invoiceId],
            $values,
        );
    }

    /** Drop our MARK for an invoice ("reset to unfiled" on the ekdosi side). */
    public static function forget(int $invoiceId): void
    {
        Capsule::table(self::TABLE)->where('invoiceid', $invoiceId)->delete();
    }

    /**
     * { invoiceid → mark } for the given ids (capped). Powers the admin
     * invoice-list badge JS + the consolidated list.
     *
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    public static function map(array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (Capsule::table(self::TABLE)->whereIn('invoiceid', array_slice($ids, 0, 200))->get(['invoiceid', 'mark']) as $row) {
            $out[(int) $row->invoiceid] = (string) $row->mark;
        }

        return $out;
    }

    /**
     * Rollback helper: copy any MARK currently stuffed into
     * `tblinvoices.invoiced` (values that don't fit SMALLINT, i.e. > 65535 —
     * those are the 15-digit MARKs the old plugin wrote) into THIS table.
     * Returns how many were moved. The caller then resets those invoiced rows
     * to a SMALLINT-safe value and narrows the column.
     */
    public static function migrateFromInvoicedColumn(): int
    {
        $moved = 0;
        foreach (Capsule::table('tblinvoices')->where('invoiced', '>', 65535)->get(['id', 'invoiced']) as $row) {
            self::set((int) $row->id, (string) $row->invoiced);
            $moved++;
        }

        return $moved;
    }
}
