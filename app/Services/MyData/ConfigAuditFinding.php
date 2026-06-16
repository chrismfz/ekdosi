<?php

namespace App\Services\MyData;

/**
 * One configuration issue found by MyDataConfigAudit: a severity, an
 * operator-facing Greek message, and the AADE business-error code it would
 * otherwise trigger (when known), so the UI can show «[223]» next to the fix.
 */
final readonly class ConfigAuditFinding
{
    /** @param 'error'|'warn' $severity */
    public function __construct(
        public string $severity,
        public string $message,
        public ?string $aadeCode = null,
    ) {}

    public function isError(): bool
    {
        return $this->severity === 'error';
    }
}
