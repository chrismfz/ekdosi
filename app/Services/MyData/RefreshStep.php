<?php

namespace App\Services\MyData;

/**
 * One step of the «Ανανέωση όλων» orchestration (a tab's fetch). Carries a label,
 * a status, and a short operator-facing note so the summary toast can report what
 * succeeded and what (e.g. a 429) didn't — without aborting the rest.
 */
final readonly class RefreshStep
{
    /** @param 'ok'|'warn'|'error' $status */
    public function __construct(
        public string $label,
        public string $status,
        public ?string $note = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'ok';
    }
}
