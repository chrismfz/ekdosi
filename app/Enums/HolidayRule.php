<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** How a CompanyHoliday recurs. */
enum HolidayRule: string implements HasLabel
{
    /** Same day/month every year (e.g. πολιούχος 29/8). */
    case Fixed = 'fixed';
    /** N days from Orthodox Easter Sunday (e.g. a moveable local feast). */
    case Easter = 'easter';
    /** A single date (e.g. an office closure). */
    case Once = 'once';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Κάθε χρόνο (σταθερή ημερομηνία)',
            self::Easter => 'Κάθε χρόνο (σε σχέση με το Πάσχα)',
            self::Once => 'Μία φορά',
        };
    }
}
