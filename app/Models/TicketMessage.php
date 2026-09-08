<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Observers\TicketMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a ticket thread (Πυλώνας E): a public reply OR an operator-only
 * internal note (`is_internal_note` — never emailed, never shown to the customer).
 * `author_role` + `author_id` identify the writer (customer/operator/system)
 * without a morph; `via` records the channel; `email_message_id` threads the
 * outbound/inbound mail (Phase 3). Attachments reuse the polymorphic morph.
 */
#[ObservedBy(TicketMessageObserver::class)]
class TicketMessage extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory;

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_SYSTEM = 'system';

    public const VIA_PORTAL = 'portal';

    public const VIA_EMAIL = 'email';

    public const VIA_OPERATOR = 'operator';

    public const VIA_SYSTEM = 'system';

    protected $fillable = [
        'company_id',
        'ticket_id',
        'author_role',
        'author_id',
        'body',
        'body_original',
        'is_internal_note',
        'via',
        'email_message_id',
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Resolve the author model for its role. Not an Eloquent relation (the target
     * table depends on the role), so callers eager-load nothing — use sparingly.
     */
    public function author(): ?Model
    {
        if ($this->author_id === null) {
            return null;
        }

        return match ($this->author_role) {
            self::ROLE_CUSTOMER => Customer::find($this->author_id),
            self::ROLE_OPERATOR => User::find($this->author_id),
            default => null,
        };
    }

    /**
     * The channel to record when a caller omits `via`, derived from the author
     * role — so a customer's message never defaults to the operator channel. A
     * caller that knows the real channel (portal vs email) passes `via` itself.
     */
    public static function defaultViaFor(string $authorRole): string
    {
        return match ($authorRole) {
            self::ROLE_OPERATOR => self::VIA_OPERATOR,
            self::ROLE_SYSTEM => self::VIA_SYSTEM,
            default => self::VIA_PORTAL, // customer's default channel
        };
    }

    public function isFromCustomer(): bool
    {
        return $this->author_role === self::ROLE_CUSTOMER;
    }

    public function isFromOperator(): bool
    {
        return $this->author_role === self::ROLE_OPERATOR;
    }
}
