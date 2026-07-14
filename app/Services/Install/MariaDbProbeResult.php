<?php

namespace App\Services\Install;

/**
 * Outcome of the installer's «Δοκιμή σύνδεσης» against the target MariaDB.
 *
 * Answers three questions in one shot: did we connect, is the named database
 * reachable, and is it SAFE to install into (empty / migrated-but-no-admin) or
 * does it already hold a finished ekdosi install we must not overwrite?
 *
 * `reason` classifies the result so the wizard can give an actionable message:
 *  - ok               — connected; DB is empty or only partially built → safe.
 *  - already_installed— connected, but a super-admin already exists → REFUSE.
 *  - driver_missing   — pdo_mysql not compiled into this PHP.
 *  - unreachable      — host/port not answering (firewall / wrong host / down).
 *  - auth             — server answered but user/password rejected.
 *  - unknown_database — connected to the server, but the named DB doesn't exist
 *                       (or the user can't see it) — on shared hosting you must
 *                       create the DB in the panel first.
 *  - error            — anything else (surfaced verbatim).
 */
class MariaDbProbeResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $message,
        /** Does the DB already carry ekdosi tables (a `users` table)? Amber, not fatal. */
        public readonly bool $hasSchema = false,
        /** Does a completed install (a super-admin user) already exist? Fatal. */
        public readonly bool $alreadyInstalled = false,
    ) {}

    public static function success(bool $hasSchema): self
    {
        return new self(
            ok: true,
            reason: 'ok',
            message: $hasSchema
                ? 'Συνδέθηκε. Η βάση υπάρχει και περιέχει ήδη πίνακες, αλλά δεν βρέθηκε ολοκληρωμένη εγκατάσταση — ασφαλές να συνεχίσεις (η μετάβαση θα συμπληρώσει ό,τι λείπει).'
                : 'Συνδέθηκε. Η βάση είναι κενή — έτοιμη για εγκατάσταση.',
            hasSchema: $hasSchema,
        );
    }

    public static function alreadyInstalled(): self
    {
        return new self(
            ok: false,
            reason: 'already_installed',
            message: 'Συνδέθηκε, αλλά αυτή η βάση περιέχει ΗΔΗ ολοκληρωμένη εγκατάσταση ekdosi (υπάρχει διαχειριστής). Ο οδηγός δεν θα την αντικαταστήσει. Χρησιμοποίησε κενή βάση ή σύνδεση στο /admin.',
            hasSchema: true,
            alreadyInstalled: true,
        );
    }

    public static function failure(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
