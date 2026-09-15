<?php

namespace App\Services\Install;

/**
 * Outcome of the installer's «Δοκιμή σύνδεσης» against the target MariaDB.
 *
 * Answers: did we connect, and is the database SAFE to install into? «Safe
 * without a second thought» means EMPTY (no tables at all). ANY existing table
 * — whether a partial prior ekdosi attempt OR a foreign application's schema
 * (WHMCS, another Laravel app…) — is flagged `needsOverride`: the wizard refuses
 * it unless the operator ticks «Συνέχεια σε μη-κενή βάση». This is the guard
 * against fat-fingering a populated/wrong database (the `users`-table-only check
 * used to report a foreign DB as «empty» and migrate straight into it).
 *
 * `reason` classifies the result so the wizard can give an actionable message:
 *  - ok               — connected; DB is EMPTY → safe to proceed automatically.
 *  - non_empty        — connected, but the DB already has tables that are not a
 *                       finished ekdosi install → require the override checkbox.
 *  - already_installed— connected, non-empty, AND an ekdosi admin already exists
 *                       → require the override (or point at an empty DB).
 *  - unmigratable     — connected, and at least one table the schema baseline
 *                       would CREATE already exists while `migrations` is
 *                       missing/empty → a DEAD END the override cannot rescue
 *                       (see the factory below). Hard stop.
 *  - driver_missing   — pdo_mysql not compiled into this PHP.
 *  - unreachable      — host/port not answering (firewall / wrong host / down).
 *  - auth             — server answered but user/password rejected.
 *  - unknown_database — connected to the server, but the named DB doesn't exist.
 *  - error            — anything else (surfaced verbatim).
 */
class MariaDbProbeResult
{
    public function __construct(
        /** Connected AND empty → safe to auto-proceed. False for both failures and non-empty DBs. */
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $message,
        /** Connected but the DB is non-empty → the operator must confirm with the override checkbox. */
        public readonly bool $needsOverride = false,
        /** Non-empty AND already holds an ekdosi admin (a finished/partial install). */
        public readonly bool $alreadyInstalled = false,
        public readonly int $tableCount = 0,
    ) {}

    public static function emptyDatabase(): self
    {
        return new self(true, 'ok', 'Συνδέθηκε. Η βάση είναι κενή — έτοιμη για εγκατάσταση.');
    }

    public static function nonEmpty(int $tableCount): self
    {
        return new self(
            ok: false,
            reason: 'non_empty',
            message: "Συνδέθηκε, αλλά η βάση ΔΕΝ είναι κενή (περιέχει {$tableCount} πίνακες που δεν φαίνονται εγκατάσταση ekdosi). "
                .'Βεβαιώσου ότι έδωσες τη σωστή, κενή βάση. Αν πρόκειται για ημιτελή προηγούμενη προσπάθεια, τσέκαρε «Συνέχεια σε μη-κενή βάση» και ξαναπροσπάθησε.',
            needsOverride: true,
            tableCount: $tableCount,
        );
    }

    public static function alreadyInstalled(int $tableCount): self
    {
        return new self(
            ok: false,
            reason: 'already_installed',
            message: 'Συνδέθηκε, αλλά αυτή η βάση περιέχει ΗΔΗ εγκατάσταση ekdosi (υπάρχει διαχειριστής). '
                .'Αν πρόκειται για ημιτελή προηγούμενη προσπάθεια που θέλεις να ολοκληρωθεί, τσέκαρε «Συνέχεια σε μη-κενή βάση»· διαφορετικά χρησιμοποίησε κενή βάση.',
            needsOverride: true,
            alreadyInstalled: true,
            tableCount: $tableCount,
        );
    }

    /**
     * The one non-empty state the override checkbox must NOT be offered for.
     *
     * Since the v2.0.2 squash the schema comes from `database/schema/*-schema.sql`,
     * and `MigrateCommand::prepareDatabase()` loads that baseline whenever
     * `hasRunAnyMigrations()` is false — i.e. whenever `migrations` is missing or
     * empty, REGARDLESS of the data tables already sitting there. So a database
     * already holding ANY table the baseline creates, with no migration rows (an
     * aborted `ekdosi:db-restore`, or an install whose own `migrate` died
     * mid-baseline — `loadSchemaState()` calls `deleteRepository()` BEFORE it
     * runs the dump), is unrecoverable from the wizard: every retry re-attempts
     * the baseline and dies on the first `CREATE TABLE … already exists`.
     * Ticking «Συνέχεια σε μη-κενή βάση» just loops. Say so, and name the
     * actions that work.
     *
     * The advice is deliberately hedged: all the probe knows is «a baseline
     * table name exists + no migration rows». That is ALSO the shape of (a) a
     * COMPLETE database whose `migrations` was truncated or dropped out of band,
     * and (b) a database SHARED with another application — ~30 of the 105
     * baseline names are generic enough to collide (`users`, `cache`, `notes`,
     * `tags`, `products`, `payments`…). The hard stop is right in all three
     * cases (`CREATE TABLE users` would genuinely fail), but the DIAGNOSIS is a
     * guess, so name the alternatives and never tell the operator to drop a
     * database unconditionally — those tables may hold live παραστατικά, or
     * another app's data.
     */
    public static function unmigratable(int $tableCount): self
    {
        return new self(
            ok: false,
            reason: 'unmigratable',
            message: "Συνδέθηκε, και η βάση περιέχει ήδη πίνακες του schema ({$tableCount} συνολικά), αλλά ο πίνακας `migrations` "
                .'λείπει ή είναι άδειος. Αυτό είναι υπόλειμμα ημιτελούς εγκατάστασης ή διακοπείσας επαναφοράς '
                .'(ekdosi:db-restore) — ή, αν αυτή η βάση ΜΟΙΡΑΖΕΤΑΙ με άλλη εφαρμογή, σύγκρουση ονομάτων πινάκων '
                .'(το schema μας έχει γενικά ονόματα: users, cache, notes, products…). Σε κάθε περίπτωση ΔΕΝ '
                .'διορθώνεται με επανάληψη — το migrate θα ξαναπροσπαθήσει να χτίσει '
                .'το schema από την αρχή και θα σκάσει σε «Table … already exists». Η λύση: δώσε ΔΙΚΗ ΤΗΣ, κενή βάση '
                .'στο ekdosi· αν επρόκειτο για διακοπείσα επαναφορά, ξανατρέξε την ΕΠΑΝΑΦΟΡΑ '
                .'(ekdosi:db-restore) από την αρχή. ΜΟΝΟ αν είσαι βέβαιος ότι αυτή η βάση δεν κρατά δεδομένα που '
                .'χρειάζεσαι (έλεγξε πρώτα π.χ. SELECT COUNT(*) FROM invoices), δώσε ΚΕΝΗ βάση με DROP DATABASE / '
                .'CREATE DATABASE.',
            needsOverride: false,
            tableCount: $tableCount,
        );
    }

    public static function failure(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
