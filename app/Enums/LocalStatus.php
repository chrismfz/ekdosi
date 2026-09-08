<?php

namespace App\Enums;

/**
 * Local (business-intent) status of an invoice — orthogonal to the
 * AADE-side mydata_state. Freely editable by the operator, with one
 * legal guard enforced elsewhere: an invoice that is CANCELLED at myDATA
 * can't be revived (terminal at AADE).
 */
enum LocalStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Πρόχειρο',
            self::Active => 'Ενεργό',
            self::Cancelled => 'Ακυρωμένο',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Active => 'success',
            self::Cancelled => 'danger',
        };
    }
}
