<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'customer_user_id',
        'gateway',
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
}
