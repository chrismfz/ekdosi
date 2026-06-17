<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\Assistant\AiActionExecutor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A WRITE action the AI «Βοηθός» PREPARED but did not run — it waits for the
 * operator to confirm (or cancel) it. The assistant never sends an email or sets
 * a reminder on its own; a write tool stages the resolved, validated target here
 * as `pending`, and {@see AiActionExecutor} performs the
 * side effect only after the operator clicks «Επιβεβαίωση» — re-validating
 * everything from THIS row, never from client input.
 *
 * A confirmed `reminder` row is also the live reminder itself: it carries
 * `remind_at` and is delivered (as a Filament database notification) by
 * `ai:dispatch-reminders` once due.
 */
class AiPendingAction extends Model
{
    use BelongsToCompany;

    public const TYPE_SEND_STATEMENT = 'send_statement';

    public const TYPE_REMINDER = 'reminder';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'type',
        'status',
        'customer_id',
        'summary',
        'payload',
        'remind_at',
        'confirmed_at',
        'cancelled_at',
        'delivered_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'remind_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @param  Builder<AiPendingAction>  $query */
    public function scopePending(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Confirmed reminders whose time has come and that haven't been delivered.
     *
     * @param  Builder<AiPendingAction>  $query
     */
    public function scopeDueReminders(Builder $query): void
    {
        $query->where('type', self::TYPE_REMINDER)
            ->where('status', self::STATUS_CONFIRMED)
            ->whereNull('delivered_at')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
