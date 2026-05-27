<?php

namespace App\Services\Etl;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Re-run-safe upsert helper for the Firebird→MariaDB ETL.
 *
 * The legacy ETL design was `wipeCompany() → insert everything`
 * (single-shot, idempotent only because the operator would discard
 * the previous run's data). The realistic operator workflow is:
 *
 *   day 0:   import current legacy backup
 *   day 1-7: operate in ekdosi (create new invoices, edit customer
 *            records, link to WHMCS, mark griniaris, etc.)
 *   day 7:   take a NEWER legacy backup, re-import to pick up the
 *            invoices the legacy system issued during the week
 *
 * The wipe-and-reinsert approach destroys the day 1-7 ekdosi-side
 * work — both rows the operator created entirely in Filament (no
 * legacy_id) AND columns on legacy-imported rows that the operator
 * customised in Filament (`whmcs_client_id`, `is_active`,
 * `peppol_endpoint`, etc.).
 *
 * This upserter solves both:
 *
 *   1. Rows are matched by (company_id, legacy_id) — so a row that
 *      survived a previous import keeps its surrogate `id`. FKs from
 *      ekdosi-only rows referencing legacy-imported rows stay valid
 *      across runs.
 *
 *   2. Columns are split into TWO buckets:
 *        - $updateValues: refresh from legacy on EVERY run. These
 *          are columns the legacy DB owns (name, address, vat rate,
 *          line totals, etc.). Operator edits to these get
 *          overwritten — by design; legacy is the source of truth
 *          during parallel-run.
 *        - $insertOnlyDefaults: written ONLY when the row is being
 *          freshly inserted. Columns the operator owns in Filament
 *          (whmcs_client_id, is_active, peppol_endpoint, etc.). On
 *          update, these are untouched → operator edits preserved.
 *
 *   3. Rows with no legacy_id (entirely operator-created in
 *      Filament) are never touched by the ETL — there's nothing in
 *      the legacy source to match against. They simply survive.
 *
 *   4. Rows that USED to be in legacy but were deleted from the
 *      source are LEFT ALONE per operator preference (CLAUDE.md
 *      PR #29 deferred section: "leave-alone deletion policy"). A
 *      future sweep command can offer to clean them up; we never
 *      auto-delete legacy-imported rows because a corrupt/partial
 *      backup could otherwise nuke real data.
 *
 * Extracted into a service so the upsert logic is unit-testable
 * without a real Firebird connection (which requires the
 * pdo_firebird PHP extension — currently blocked in the sandbox
 * per CLAUDE.md, but the upsert behaviour itself is database-only).
 */
class TenantRowUpserter
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public static function default(): self
    {
        return new self(DB::connection());
    }

    /**
     * Upsert a row keyed by the $matchKeys map. Returns the surrogate
     * id of the row (existing or newly-inserted).
     *
     * @param  string  $table  Target table name.
     * @param  array<string, mixed>  $matchKeys
     *     Columns used to find an existing row (typically
     *     ['company_id' => X, 'legacy_id' => Y] or
     *     ['company_id' => X, 'code' => 'TPY'] for invoice_types).
     * @param  array<string, mixed>  $updateValues
     *     Columns to write on BOTH insert and update — legacy-sourced
     *     fields that should refresh every run.
     * @param  array<string, mixed>  $insertOnlyDefaults
     *     Columns to write ONLY on insert. Filament-managed columns
     *     that must survive re-imports (preserved on update by being
     *     omitted from the UPDATE statement).
     *
     * @return int  Surrogate id. Cast from the DB-driver's native
     *              type to int — both MariaDB (string from auto_inc)
     *              and SQLite (int from rowid) coerce safely.
     */
    public function upsertGetId(
        string $table,
        array $matchKeys,
        array $updateValues,
        array $insertOnlyDefaults = [],
    ): int {
        $existing = $this->db->table($table)
            ->where($matchKeys)
            ->select('id')
            ->first();

        if ($existing !== null) {
            if (! empty($updateValues)) {
                $this->db->table($table)
                    ->where('id', $existing->id)
                    ->update($updateValues);
            }
            return (int) $existing->id;
        }

        // First insertion: merge match keys + legacy values + Filament
        // defaults. Match keys come first so the explicit defaults
        // can't accidentally override them (defensive ordering — if a
        // caller passes 'company_id' in both buckets the match key
        // wins).
        $insertRow = array_merge(
            $insertOnlyDefaults,
            $updateValues,
            $matchKeys,
        );

        return (int) $this->db->table($table)->insertGetId($insertRow);
    }

    /**
     * Upsert without needing the resulting id back. Convenience
     * wrapper for child tables (payments, mydata_marks, etc.) where
     * the row's surrogate id is never referenced by other ETL passes.
     *
     * Same $insertOnlyDefaults semantics as upsertGetId — written on
     * INSERT, ignored on UPDATE. Critical for `created_at`: if it's
     * not in $insertOnlyDefaults, the row inserts with created_at=NULL
     * (verified at vendor/laravel/.../Builder.php:4347 —
     * updateOrInsert's INSERT path doesn't auto-populate timestamps).
     *
     * The array_merge order MATCHES upsertGetId's — matchKeys win
     * last, so a caller that accidentally passes the same key in
     * $values doesn't shadow the match-key value used for the SELECT.
     *
     * @param  array<string, mixed>  $matchKeys
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $insertOnlyDefaults
     */
    public function upsert(
        string $table,
        array $matchKeys,
        array $values,
        array $insertOnlyDefaults = [],
    ): void {
        $existing = $this->db->table($table)
            ->where($matchKeys)
            ->select('id')
            ->first();

        if ($existing !== null) {
            if (! empty($values)) {
                $this->db->table($table)
                    ->where('id', $existing->id)
                    ->update($values);
            }
            return;
        }

        $insertRow = array_merge(
            $insertOnlyDefaults,
            $values,
            $matchKeys,
        );

        $this->db->table($table)->insert($insertRow);
    }
}
