<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One inbound gateway notification (a vPOS return). Write-once audit row for the
 * «Log πύλης» — see the create migration. Provenance/debugging only; it never
 * moves money (that is the Payment via settle()).
 */
class PaymentGatewayEvent extends Model
{
    use BelongsToCompany;

    /** outcome values. */
    public const OUTCOME_SETTLED = 'settled';      // verified capture → money recorded

    public const OUTCOME_IGNORED = 'ignored';      // verified but not a capture (auth/cancel/…), or already settled

    public const OUTCOME_REJECTED = 'rejected';    // failed a guard (digest/amount/currency/unknown order)

    protected $fillable = [
        'company_id',
        'payment_intent_id',
        'gateway',
        'order_id',
        'outcome',
        'reason',
        'verified',
        'provider_status',
        'transaction_id',
        'amount',
        'currency',
        'ip',
        'message',
        'diagnostics',
    ];

    protected function casts(): array
    {
        return [
            'verified' => 'boolean',
            'amount' => 'decimal:2',
            'diagnostics' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The intent this return referred to (soft ref — may be gone). */
    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    /** Operator-facing label for the outcome (single source for the UI). */
    public static function outcomeLabel(?string $outcome): string
    {
        return match ($outcome) {
            self::OUTCOME_SETTLED => 'Καταχωρίστηκε',
            self::OUTCOME_IGNORED => 'Αγνοήθηκε',
            self::OUTCOME_REJECTED => 'Απορρίφθηκε',
            default => (string) $outcome,
        };
    }
}
