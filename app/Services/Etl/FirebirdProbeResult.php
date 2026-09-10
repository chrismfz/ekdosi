<?php

namespace App\Services\Etl;

/**
 * Outcome of a Firebird «Έλεγχος σύνδεσης». Answers the operator's three
 * questions in one shot: did we connect, is it the right DB (expected legacy
 * tables present), and does it hold data (row counts)?
 *
 * Since the ΑΦΜ hardening it answers a FOURTH question too: would an import be
 * refused because two legacy customers share an ΑΦΜ (or a local customer already
 * holds one)? `afm` carries that report — null when the check could not run (an
 * older `.fbk` without the columns, or no read rights), so the UI can stay silent
 * instead of claiming a clean bill of health it never established.
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
     * @param  LegacyAfmConflictReport|null  $afm  null = the ΑΦΜ check could not run
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $reason,
        public readonly string $message,
        public readonly array $counts = [],
        public readonly array $missing = [],
        public readonly ?LegacyAfmConflictReport $afm = null,
    ) {}

    /** @param array<string, int> $counts */
    public static function success(array $counts, array $missing = [], ?LegacyAfmConflictReport $afm = null): self
    {
        $summary = collect($counts)
            ->map(fn (int $n, string $t) => "{$t}: ".number_format($n, 0, ',', '.'))
            ->implode(' · ');

        return new self(true, 'ok', $summary !== '' ? $summary : 'Συνδέθηκε.', $counts, $missing, $afm);
    }

    /** True when an import from this source would be refused as things stand. */
    public function afmBlocks(): bool
    {
        return $this->afm !== null && $this->afm->hasBlockers();
    }

    /** The ΑΦΜ line for the UI, or null when there is nothing to say. */
    public function afmSummary(): ?string
    {
        if ($this->afm === null || $this->afm->isEmpty()) {
            return null;
        }

        return $this->afm->summary();
    }

    public static function failure(string $reason, string $message): self
    {
        return new self(false, $reason, $message);
    }
}
