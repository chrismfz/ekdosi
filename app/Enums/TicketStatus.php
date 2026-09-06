<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a support ticket (Πυλώνας E). Status is driven by WHO posts, not
 * typed by hand (see App\Actions\Support\PostTicketMessage):
 *
 *   open ──(operator reply)──▶ answered ──(customer reply)──▶ customer_reply ──▶ …
 *     └ a public message always moves the ticket to the poster's side; an
 *       internal note changes nothing.
 *   any ──(operator action)──▶ on_hold / closed
 *   closed ──(any public reply)──▶ answered|customer_reply  (a reply reopens it)
 *
 * `open` + `customer_reply` = the operator queue (needsOperator). `answered` =
 * ball in the customer's court. Greek labels (operator-facing); stored token is
 * the English value.
 */
enum TicketStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Answered = 'answered';
    case CustomerReply = 'customer_reply';
    case OnHold = 'on_hold';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Ανοιχτό',
            self::Answered => 'Απαντήθηκε',
            self::CustomerReply => 'Απάντηση πελάτη',
            self::OnHold => 'Σε αναμονή',
            self::Closed => 'Κλειστό',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::CustomerReply => 'danger',
            self::Answered => 'success',
            self::OnHold => 'info',
            self::Closed => 'gray',
        };
    }

    /** In the operator queue — needs an operator to act. */
    public function needsOperator(): bool
    {
        return in_array($this, [self::Open, self::CustomerReply], true);
    }

    /** Any non-closed ticket (a "live" ticket). */
    public function isActive(): bool
    {
        return $this !== self::Closed;
    }

    /**
     * The status a ticket moves to after a PUBLIC message from the given author
     * role. A customer/guest reply lands it back in the operator queue; an
     * operator reply marks it answered; either reopens a closed ticket. Internal
     * notes never call this (they don't change status).
     */
    public static function afterPublicMessageFrom(string $authorRole): self
    {
        return match ($authorRole) {
            'operator' => self::Answered,
            default => self::CustomerReply, // customer | guest
        };
    }

    /**
     * value => Greek label map for Filament Selects / filters.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s): array => [$s->value => $s->getLabel()])
            ->all();
    }

    /** Values in the operator queue — for the default «Στην ουρά μου» filter. */
    public static function queueValues(): array
    {
        return array_map(
            fn (self $s): string => $s->value,
            array_values(array_filter(self::cases(), fn (self $s): bool => $s->needsOperator())),
        );
    }
}
