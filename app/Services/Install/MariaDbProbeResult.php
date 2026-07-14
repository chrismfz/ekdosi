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

    public static function failure(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
