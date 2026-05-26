<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Issued invoice (παραστατικό). Mirrors legacy INVOICE.
 *
 * The party-snapshot columns (address1, vat_no, occupation, company_name,
 * etc.) are intentionally denormalised at issue-time: a customer's
 * address or AFM may change after the invoice is filed, but the FILED
 * legal document must keep the values as they were on that date.
 * NEVER join through `customer` to display these on a printed invoice
 * or audit view — use the snapshot columns.
 *
 * The mydata_* columns are a denormalised cache that mirrors the latest
 * mydata_marks row. Source of truth for myDATA submissions is the
 * mydata_marks HasMany. Replacement for the legacy MARK_AI0 trigger
 * lands in PR #7 (MyDataSubmitter service); for now the cache is
 * populated by the ETL.
 *
 * `code` is the per-invoice-type sequence number (ΑΑ).
 * `invcode` is the human-readable identifier = invoice_type.code . code,
 * e.g. "APY423". Allocated by App\Services\InvoiceNumberer under a row
 * lock so concurrent issues for the same type can't collide.
 */
class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Mass-assignable columns. The myDATA cache columns
     * (mydata_sent / mydata_state / mydata_mark / mydata_url) are
     * INTENTIONALLY OMITTED — they're written ONLY by the future
     * MyDataSubmitter service (PR #7) inside the same DB transaction
     * as the matching mydata_marks row. Leaving them fillable would
     * let any Filament edit form (PR #8) accept `mydata_state = 'VALID'`
     * with an arbitrary MARK from operator input, with no audit row
     * to back it. The submitter uses `forceFill()` to bypass this guard.
     *
     * Same shape as the legacy schema, where MARK_AI0 (an AFTER INSERT
     * trigger on MARK) was the only writer of those columns.
     *
     * ETL note: MigrateFromFirebird::copyInvoices populates these
     * directly via the DB query builder (not via Model::create), so
     * the fillable restriction doesn't affect the ETL.
     */
    protected $fillable = [
        'company_id',
        'legacy_id',
        'invcode',
        'code',
        'invoice_type_id',
        'customer_id',
        'issued_at',
        'distribution_aim_id',
        'delivery_method_id',
        'payment_method_id',
        'conv_invoice_id',
        'delivery_date',
        'header_discount_percent',
        'net_total',
        'gross_total',
        'withhold_amount',
        'mailed',
        'printed',
        // Party snapshot at issue time
        'address1',
        'address2',
        'city',
        'postcode',
        'country',
        'company_name',
        'vat_no',
        'vies_vat',
        'occupation',
        'notes',
        'email_sent',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'delivery_date' => 'date',
            'header_discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'withhold_amount' => 'decimal:2',
            'mailed' => 'boolean',
            'printed' => 'boolean',
            'mydata_sent' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoiceType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    /**
     * The invoice this one was converted from (legacy
     * delivery-note → invoice path; CONV_INVOICE_ID).
     */
    public function convertedFromInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conv_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    public function mydataMarks(): HasMany
    {
        return $this->hasMany(MyDataMark::class);
    }

    /**
     * Latest myDATA submission for this invoice — for the read-only
     * view page. Ordered by the legal action time (mark_date +
     * mark_time), NOT by autoincrement id. Live submissions get id
     * order = action order, but ETL-imported MARK rows from legacy
     * may be inserted in arbitrary order (Firebird SELECT order),
     * so id-based ordering would return the wrong row when the
     * legacy operator cancelled and re-submitted out of insertion
     * sequence. id is included as a final tiebreaker for the
     * pathological case of two MARKs at exactly the same second.
     */
    public function latestMydataMark(): HasOne
    {
        return $this->hasOne(MyDataMark::class)->latestOfMany(['mark_date', 'mark_time', 'id']);
    }
}
