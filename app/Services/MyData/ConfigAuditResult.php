<?php

namespace App\Services\MyData;

use App\Models\Company;

/**
 * The full configuration-audit snapshot for a tenant: readiness + per invoice
 * type + per VAT category, with rolled-up error/warning counts.
 */
final readonly class ConfigAuditResult
{
    /**
     * @param  ConfigAuditRow  $tenant  the single tenant-readiness row
     * @param  list<ConfigAuditRow>  $invoiceTypes
     * @param  list<ConfigAuditRow>  $vatCategories
     */
    public function __construct(
        public Company $company,
        public ConfigAuditRow $tenant,
        public array $invoiceTypes,
        public array $vatCategories,
    ) {}

    /** @return list<ConfigAuditRow> every row, in display order */
    public function allRows(): array
    {
        return [$this->tenant, ...$this->invoiceTypes, ...$this->vatCategories];
    }

    public function errorCount(): int
    {
        $n = 0;
        foreach ($this->allRows() as $row) {
            foreach ($row->findings as $f) {
                $n += $f->isError() ? 1 : 0;
            }
        }

        return $n;
    }

    public function warnCount(): int
    {
        $n = 0;
        foreach ($this->allRows() as $row) {
            foreach ($row->findings as $f) {
                $n += $f->isError() ? 0 : 1;
            }
        }

        return $n;
    }

    public function isClean(): bool
    {
        return $this->errorCount() === 0 && $this->warnCount() === 0;
    }
}
