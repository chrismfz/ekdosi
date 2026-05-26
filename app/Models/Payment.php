<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer payment. Mirrors legacy PAYMENT.
 *
 * Tied to a customer (not an invoice) — matches the legacy schema
 * where payments are running ledger entries and "balance" is
 * computed via GET_CUSTOMER_BALANCE: sum of invoice gross totals
 * (where payment_method.due_days > 0) minus sum of payments. There
 * is no invoice-to-payment FK; payment application is implicit
 * (FIFO by date).
 *
 * The GET_CUSTOMER_BALANCE port lands as a scope on this model or
 * on Customer — tracked in CLAUDE.md as a deferred item for the
 * customer-statement / collections workflow.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'customer_id',
        'pay_date',
        'amount',
        'notes',
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
}
