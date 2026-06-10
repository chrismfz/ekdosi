<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasTags;

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
 * Tenant scoping: carries the `BelongsToCompany` global scope (auto-filters
 * reads to the ambient tenant; no-op without context, so CLI/queue still scope
 * by company_id explicitly). The myDATA state columns mirror the latest AADE
 * state — the audit trail is ExpenseMark.
 */
class Expense extends Model
{
    use BelongsToCompany;

    use HasFactory;
    use HasTags;
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
        'category',
        'notes',
        'document_path',
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

    /**
     * Operator-facing label for a classification_state — the single source the
     * list column + infolist + actions share so a new state value (e.g.
     * 'submitted') can't read as «Αχαρακτήριστο» on one surface.
     */
    public static function classificationStateLabel(?string $state): string
    {
        return match ($state) {
            'submitted' => 'Υποβλήθηκε στην ΑΑΔΕ',
            'classified' => 'Χαρακτηρισμένο',
            default => 'Αχαρακτήριστο',
        };
    }

    public static function classificationStateColor(?string $state): string
    {
        return match ($state) {
            'submitted' => 'success',
            'classified' => 'info',
            default => 'gray',
        };
    }

    /**
     * Do the lines carry DIFFERENT classifications (per-line «Χαρακτηρισμός ανά
     * γραμμή»)? When true, the single header type/category field is misleading, so
     * surfaces show «Μικτός — βλ. ανά γραμμή» instead. Each line's effective value
     * is its own, falling back to the header (same rule the submitter uses).
     */
    public function classificationIsMixed(): bool
    {
        $this->loadMissing('lines');
        if ($this->lines->count() < 2) {
            return false;
        }

        $combos = $this->lines->map(fn ($line): string => ($line->classification_type ?: $this->classification_type)
            .'|'.($line->classification_category ?: $this->classification_category))->unique();

        return $combos->count() > 1;
    }
}
