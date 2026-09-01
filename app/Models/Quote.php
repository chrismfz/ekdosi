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
        // Leads L1: the lead this offer was made to (null once/unless from a lead).
        'lead_id',
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
        'language',
        'admin_notes',
        'converted_invoice_id',
        'converted_service_contract_id',
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

    /** The lead this quote was issued to (Leads L1) — null when not from a lead. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
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

    /** The recurring service contract produced by «Μετατροπή σε Υπηρεσία», if any. */
    public function convertedServiceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class, 'converted_service_contract_id');
    }

    public function isConvertedToService(): bool
    {
        return $this->converted_service_contract_id !== null;
    }

    /**
     * Net total of the quote lines whose product is RECURRING — the default
     * «recurring amount» of the contract a «Μετατροπή σε Υπηρεσία» would create.
     * Free-text / one-time-product lines are excluded (they're the first
     * invoice's one-off lines, not part of the renewal).
     */
    public function recurringLinesNetTotal(): float
    {
        return round((float) $this->lines
            ->filter(fn (QuoteLine $l) => (bool) ($l->product?->is_recurring))
            ->sum('net_price'), 2);
    }

    /** First recurring product line (drives the contract's product/vat/cycle defaults). */
    public function firstRecurringLine(): ?QuoteLine
    {
        return $this->lines->first(fn (QuoteLine $l) => (bool) ($l->product?->is_recurring));
    }
}
