<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A customer's intent to pay through a gateway (Πυλώνας B / B0b). Created when a
 * payment starts in the portal; settled once — by an operator confirmation
 * (manual) or a signed webhook (later) — never by the browser return. See
 * docs/payment-gateways-design.md §5.
 */
class PaymentIntent extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'company_id',
        'customer_id',
        'invoice_id',
        'customer_user_id',
        'gateway',
        'payment_gateway_connection_id',
        'purpose',
        'amount',
        'currency',
        'status',
        'reference',
        'instructions',
        'expires_at',
        'settled_at',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expires_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The specific invoice this intent targets (null = pay the whole balance, FIFO). */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** The Payment row(s) settle() wrote from this intent (the money trail). */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** The payment method that started this intent (null for a manual/operator one). */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(PaymentGatewayConnection::class, 'payment_gateway_connection_id');
    }
}
