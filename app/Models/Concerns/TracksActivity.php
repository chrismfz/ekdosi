<?php

namespace App\Models\Concerns;

use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Wires spatie/laravel-activitylog onto a tenant-owned model with the project's
 * house rules baked in:
 *
 *   - logOnly($this->loggedAttributes()) — only the business-meaningful columns,
 *     never the denormalised money/myDATA CACHE columns (paid_total,
 *     payment_status, …). That cache is rewritten by InvoiceBalance /
 *     RecomputeInvoiceTotals via forceFill+save on otherwise-unchanged rows; if
 *     those columns were logged, every payment recompute would spam the audit
 *     trail.
 *   - logOnlyDirty() + dontLogEmptyChanges() — a save that touches ONLY
 *     unlogged columns produces no dirty logged attribute → no empty log row.
 *     This is what keeps cache-only writes (and the ETL, which writes via the
 *     query builder and fires no Eloquent events at all) out of the log.
 *   - log_name = the table, so the activity feed can be filtered per entity.
 *   - Greek event descriptions (operator-facing UI text is Greek).
 *
 * The causer is resolved automatically by the package from the authenticated
 * user (null on CLI/queue paths — shown as «Σύστημα»).
 */
trait TracksActivity
{
    use LogsActivity;

    /**
     * The business attributes worth auditing for this model — NEVER cache
     * columns. Each consuming model declares its own set.
     *
     * @return list<string>
     */
    abstract protected function loggedAttributes(): array;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly($this->loggedAttributes())
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName($this->getTable())
            ->setDescriptionForEvent(fn (string $event): string => match ($event) {
                'created' => 'Δημιουργία',
                'updated' => 'Τροποποίηση',
                'deleted' => 'Διαγραφή',
                'restored' => 'Επαναφορά',
                default => $event,
            });
    }
}
