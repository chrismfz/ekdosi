<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One myDATA submission record. The legal audit trail of every
 * INSERT / CANCEL the app has sent to AADE for an invoice.
 *
 * The full request + response XML is preserved verbatim per row — DO
 * NOT TRUNCATE OR NORMALISE on read. The legal value of the audit
 * trail is the byte-exact record of what was transmitted.
 *
 * `mark_date` is a DATE, `mark_time` is a TIME (the legacy schema
 * splits them — see schema-fix migration 2026_05_27_000002).
 *
 * Source of truth for myDATA state. The mirror columns on `invoices`
 * (mydata_sent / state / mark / url) are a denormalised cache of the
 * LATEST mark — populated by the future MyDataSubmitter service
 * (PR #7, replacing legacy MARK_AI0 trigger).
 */
class MyDataMark extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $table = 'mydata_marks';

    protected $fillable = [
        'company_id',
        'legacy_id',
        'invoice_id',
        'mark',
        'mydata_action',
        // Provider-side audit (P1; filled by GrProviderSubmitter in P2, null for
        // direct myDATA filings). See docs/paroxos/implementation-plan.md §5.
        'provider_key',
        'authentication_code',
        'delivery_state',
        'invoice_url',
        'request',
        'response',
        'mark_date',
        'mark_time',
    ];

    protected function casts(): array
    {
        return [
            'mark_date' => 'date',
            // mark_time is stored as TIME (HH:MM:SS). Leaving it as a
            // plain string avoids Carbon synthesising today's date,
            // which would silently break date-based comparisons
            // (e.g. 23:00 yesterday's mark would compare as "later
            // than" 09:00 today's mark when both get a today() date).
            // The Filament Tables/Infolist time formatters render
            // the string directly via PHP's strftime-style parsing.
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
