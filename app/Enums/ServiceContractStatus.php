<?php

namespace App\Enums;

/**
 * Lifecycle state of a service contract (WHMCS parity). The enum value is the
 * stored token; labels/colours are operator-facing (Filament badges). The
 * transition map is the single source for what's allowed:
 *
 *   Pending   → Active (start billing) | Cancelled
 *   Active    → Suspended (overdue/manual) | Cancelled | Terminated
 *   Suspended → Active (unsuspend after payment) | Cancelled | Terminated
 *   Cancelled → Active (revive)
 *   Terminated → (terminal)
 *
 * `Suspended` exists from day one so the (future) dunning mechanism has a state
 * to move into. next_due_date advancement is owned by the renewal action, NOT
 * by a status mutator (same discipline as InvoiceNumberer owning the ΑΑ).
 */
enum ServiceContractStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Εκκρεμεί',
            self::Active => 'Ενεργό',
            self::Suspended => 'Σε αναστολή',
            self::Cancelled => 'Ακυρωμένο',
            self::Terminated => 'Τερματισμένο',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Cancelled => 'gray',
            self::Terminated => 'danger',
        };
    }

    /** Is this contract live (billed/renewable)? Only Active counts as due. */
    public function isLive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Allowed next states from this one.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Cancelled],
            self::Active => [self::Suspended, self::Cancelled, self::Terminated],
            self::Suspended => [self::Active, self::Cancelled, self::Terminated],
            self::Cancelled => [self::Active],
            self::Terminated => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** @return array<string, string> value => label, for Filament selects/filters. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
