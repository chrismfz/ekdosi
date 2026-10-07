<?php

namespace App\Services\Payments;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Which family of IMPORTED on-account («έναντι») payments a credit cleanup may
 * draw from — so a targeted sweep never consumes a genuine operator advance.
 *
 *   epsilon()  — Epsilon import: transaction_id «EPS:…» (nexon).
 *   firebird() — legacy C++Builder/Firebird ETL (migrate:firebird): the row
 *                carries the legacy PAYMENT_ID in `legacy_id` (myip). Only the
 *                ETL writes payments.legacy_id (a CompanyImporter round-trip just
 *                carries those same rows over); a row created in the app
 *                (receipt, gateway, WHMCS, the split-off part of an applied
 *                credit) has it null, so it is never part of this pool. Rows
 *                with amount ≤ 0 are left out: the ETL writes raw (no MON-8
 *                guard), so a legacy zero/negative correction can exist, and
 *                re-pointing it would trip Payment's positive-amount guard.
 *                Its sweep TARGETS only legacy invoices too (scopeTargets):
 *                legacy credit settles legacy debt; any surplus stays έναντι
 *                for the operator — never auto-lands on an app-issued invoice
 *                (whose WHMCS link would push «paid» outward).
 */
final readonly class ImportedCreditPool
{
    private function __construct(
        public string $key,
        private ?string $txPrefix,
    ) {}

    public static function epsilon(): self
    {
        return new self('eps', 'EPS:');
    }

    public static function firebird(): self
    {
        return new self('firebird', null);
    }

    public static function fromSource(string $source): ?self
    {
        return match ($source) {
            'eps', 'epsilon' => self::epsilon(),
            'firebird' => self::firebird(),
            default => null,
        };
    }

    /** Narrow a payments query to this pool's rows. */
    public function scope(Builder|QueryBuilder $query): Builder|QueryBuilder
    {
        // A LITERAL prefix in a SQL LIKE — it must contain no `%`/`_` wildcards.
        return $this->txPrefix !== null
            ? $query->where('transaction_id', 'like', $this->txPrefix.'%')
            : $query->whereNotNull('legacy_id')->where('amount', '>', 0);
    }

    /** Narrow the sweep's open-invoice target set (firebird: legacy invoices only). */
    public function scopeTargets(Builder $invoices): Builder
    {
        return $this->txPrefix !== null ? $invoices : $invoices->whereNotNull('legacy_id');
    }

    /** Ledger reference tag for a sweep over this pool («ΕΦΑ-EPS-…» / «ΕΦΑ-FB-…»). */
    public function referenceTag(): string
    {
        return $this->txPrefix !== null ? 'EPS' : 'FB';
    }
}
