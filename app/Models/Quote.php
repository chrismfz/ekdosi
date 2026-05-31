<?php

namespace App\Models;

use App\Enums\QuoteStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Προσφορά — a sales offer. NOT a legal document: never filed at myDATA,
 * never counted toward money/καρτέλα/ΦΠΑ. Lives in its own table so it can't
 * leak into App\Support\InvoiceScope::live() or any invoice aggregation.
 *
 * `code` is the human-readable identifier (ΠΡ-{n}), allocated per company by
 * App\Services\QuoteNumberer under a row lock — a SEPARATE counter from the
 * legal ΑΑ (invoice_types.invcount), which it must never touch.
 *
 * Totals (net/vat/gross) are recomputed from the lines by
 * App\Services\QuoteTotals after every save (no balance coupling).
 */
class Quote extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'customer_id',
        'code',
        'legacy_id',
        'subject',
        'status',
        'issued_at',
        'valid_until',
        'service_until',
        // Party snapshot (same names as invoices)
        'company_name',
        'vat_no',
        'vies_vat',
        'occupation',
        'address1',
        'address2',
        'city',
        'postcode',
        'country',
        'header_discount_percent',
        'net_total',
        'vat_total',
        'gross_total',
        'proposal_text',
        'customer_notes',
        'admin_notes',
        'converted_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'issued_at' => 'date',
            'valid_until' => 'date',
            'service_until' => 'date',
            'header_discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }

    public function mailLog(): HasMany
    {
        return $this->hasMany(QuoteMailLog::class)->orderByDesc('created_at');
    }

    /** The draft invoice produced by «Μετατροπή σε Παραστατικό», if any. */
    public function convertedInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'converted_invoice_id');
    }

    /** True once this quote has been turned into an invoice (idempotency guard). */
    public function isConverted(): bool
    {
        return $this->converted_invoice_id !== null;
    }
}
