<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use App\Observers\PaymentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Customer payment. Legacy PAYMENT was customer-level only (CUST_ID,
 * PAY_DATE, VALUE, NOTES — no invoice link, no method).
 *
 * Forward-extended for a real invoicer:
 * - `invoice_id` (nullable) allocates a payment to a specific invoice.
 *   NULL = "on-account" (customer-level credit) — this is how every
 *   legacy-imported payment lands, preserving GET_CUSTOMER_BALANCE.
 *   A partial payment is several rows against one invoice.
 * - `payment_method_id` (nullable) records the "way" it was paid.
 *
 * The PaymentObserver keeps invoices.{paid_total,payment_status} in
 * sync (via App\Services\InvoiceBalance) whenever an invoice-allocated
 * payment changes. SoftDeletes so a mis-keyed payment is recoverable
 * and excluded from the paid total while trashed.
 *
 * Audited via TracksActivity (the cross-model audit pass — invoices +
 * customers + payments together).
 */
#[ObservedBy(PaymentObserver::class)]
class Payment extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes, TracksActivity;

    /**
     * Audited columns — every business field on a payment is audit-worthy.
     * See TracksActivity.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return ['customer_id', 'invoice_id', 'payment_method_id', 'pay_date', 'amount', 'notes'];
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'customer_id',
        'invoice_id',
        'payment_method_id',
        'pay_date',
        'amount',
        'notes',
        'reference',
    ];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'amount' => 'decimal:2',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
