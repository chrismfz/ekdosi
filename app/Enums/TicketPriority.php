<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Ticket priority (Πυλώνας E). Greek labels; stored token is the English value.
 */
enum TicketPriority: string implements HasColor, HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';

    public function getLabel(): string
    {
        return match ($this) {
            self::Low => 'Χαμηλή',
            self::Normal => 'Κανονική',
            self::High => 'Υψηλή',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'danger',
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
}
