<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a LeaveRequest:
 *
 *   pending ──▶ approved ──▶ cancelled
 *      ├──────▶ rejected
 *      └──────▶ cancelled   (withdrawn by the employee before a decision)
 *
 * Only `approved` occupies the calendar / consumes the annual balance and is
 * what the accountant (and later ΕΡΓΑΝΗ WTOLeave) is told about.
 */
enum LeaveStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Σε αναμονή',
            self::Approved => 'Εγκρίθηκε',
            self::Rejected => 'Απορρίφθηκε',
            self::Cancelled => 'Ακυρώθηκε',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'gray',
        };
    }
}
