<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use App\Enums\ExpenseSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Έξοδο / παραστατικό εξόδων — the doc-header twin of Invoice, for the
 * Έξοδα phase. A document a SUPPLIER filed against us (pulled from myDATA
 * RequestDocs) or keyed manually.
 *
 * Tenant scoping: like Invoice/Supplier, this relies on Filament's
 * BelongsToTenant in panel context; code outside a Filament request must
 * scope by company_id itself (see CLAUDE.md latent items). The myDATA state
 * columns mirror the latest AADE state — the audit trail is ExpenseMark.
 */
class Expense extends Model
{
    use BelongsToCompany;

    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'supplier_id',
        'mydata_mark',
        'uid',
        'authentication_code',
        'invoice_type',
        'series',
        'aa',
        'issue_date',
        'currency',
        'supplier_afm',
        'supplier_name',
        'net_total',
        'vat_total',
        'gross_total',
        'mydata_state',
        'cancelled_by_mark',
        'qr_url',
        'downloading_invoice_url',
        'classification_state',
        'classification_type',
        'classification_category',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'source' => ExpenseSource::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class);
    }

    public function marks(): HasMany
    {
        return $this->hasMany(ExpenseMark::class);
    }
}
