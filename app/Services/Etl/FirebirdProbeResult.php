<?php

namespace App\Services\Etl;

/**
 * Outcome of a Firebird «Έλεγχος σύνδεσης». Answers the operator's three
 * questions in one shot: did we connect, is it the right DB (expected legacy
 * tables present), and does it hold data (row counts)?
 *
 * `reason` classifies a failure so the UI can give an actionable message:
 *  - ok            — connected + at least the core legacy tables found.
 *  - driver_missing— pdo_firebird not installed on the host.
 *  - unreachable   — host/port not answering (firewall / wrong IP / Firebird down).
 *  - auth          — connected to the server but user/password rejected.
 *  - no_tables     — connected to a DB, but none of the expected legacy tables
 *                    exist (wrong .fdb path, or an empty/foreign database).
 *  - error         — anything else (surfaced verbatim).
 */
class FirebirdProbeResult
{
    /**
     * @param  array<string, int>  $counts  table => row count (only the tables that existed)
     * @param  string[]  $missing  expected tables that were absent
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $counts = [],
        public readonly array $missing = [],
    ) {}

    /** @param array<string, int> $counts */
    public static function success(array $counts, array $missing = []): self
    {
        $summary = collect($counts)
            ->map(fn (int $n, string $t) => "{$t}: ".number_format($n, 0, ',', '.'))
            ->implode(' · ');

        return new self(true, 'ok', $summary !== '' ? $summary : 'Συνδέθηκε.', $counts, $missing);
    }

    public static function failure(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
