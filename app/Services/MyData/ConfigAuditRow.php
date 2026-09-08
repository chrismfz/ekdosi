<?php

namespace App\Services\MyData;

/**
 * One audited entity (the tenant, an invoice type, or a VAT category) with its
 * findings. `status()` rolls the findings up to a single badge state; `modelId`
 * lets a UI deep-link to the record that needs fixing.
 */
final readonly class ConfigAuditRow
{
    /**
     * @param  'tenant'|'invoice_type'|'vat_category'  $kind
     * @param  list<ConfigAuditFinding>  $findings
     */
    public function __construct(
        public string $kind,
        public string $label,
        public array $findings,
        public ?int $modelId = null,
        public ?string $detail = null,
    ) {}

    public function hasError(): bool
    {
        foreach ($this->findings as $f) {
            if ($f->isError()) {
                return true;
            }
        }

        return false;
    }

    /** 'ok' (no findings) | 'warn' (warnings only) | 'error' (≥1 error). */
    public function status(): string
    {
        if ($this->findings === []) {
            return 'ok';
        }

        return $this->hasError() ? 'error' : 'warn';
    }

    /** @return list<string> the finding messages (with [code] prefix when known) */
    public function messages(): array
    {
        return array_map(
            fn (ConfigAuditFinding $f) => ($f->aadeCode ? "[{$f->aadeCode}] " : '').$f->message,
            $this->findings,
        );
    }
}
