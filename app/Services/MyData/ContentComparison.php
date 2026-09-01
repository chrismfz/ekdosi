<?php

namespace App\Services\MyData;

/**
 * Outcome of a content cross-check between a local document and the AADE summary
 * for the same MARK (MYD-017). Two distinct kinds of divergence — neither is a
 * clean match, but each needs a different repair:
 *
 *   - conflicts:    BOTH sides carry the field but the values DIFFER — a real
 *                   content conflict (danger; the local doc says something AADE
 *                   contradicts).
 *   - incompletes:  AADE carries a value our LOCAL record lacks — the doc was
 *                   never fully captured/verified (warning; complete it, don't
 *                   trust it as reconciled). NOT a conflict, so it is kept out of
 *                   the danger bucket, but it must never read as "matched" either.
 *
 * Each entry is an operator-facing Greek description of the field.
 */
final readonly class ContentComparison
{
    /**
     * @param  list<string>  $conflicts
     * @param  list<string>  $incompletes
     */
    public function __construct(
        public array $conflicts,
        public array $incompletes,
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    public function hasIncompletes(): bool
    {
        return $this->incompletes !== [];
    }

    public function isClean(): bool
    {
        return $this->conflicts === [] && $this->incompletes === [];
    }
}
