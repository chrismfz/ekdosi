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
        return ['customer_id', 'invoice_id', 'kind', 'payment_method_id', 'bank_account_id', 'pay_date', 'amount', 'transaction_id', 'notes']; // kind: payment|refund
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'customer_id',
        'invoice_id',
        'kind',
        'payment_method_id',
        'bank_account_id',
        'pay_date',
        'amount',
        'notes',
        'reference',
        'transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'pay_date' => 'date',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * SQL for the SIGNED contribution of a payment row to a paid total:
     * a refund counts as NEGATIVE (money went back out). The single place
     * every money aggregate (InvoiceBalance, DashboardMetrics, Customer
     * outstanding-balance) borrows so refunds net out identically. Operates
     * on the `payments` table columns; works on MariaDB + sqlite.
     */
    public const NET_AMOUNT_SQL = "CASE WHEN kind = 'refund' THEN -amount ELSE amount END";

    public function isRefund(): bool
    {
        return $this->kind === 'refund';
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

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
