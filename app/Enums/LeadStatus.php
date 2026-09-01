<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Lifecycle of a Lead (υποψήφιος πελάτης). Deliberately loose — any status can
 * move to any other from the «Αλλαγή κατάστασης» action — with two rules:
 *
 *   - Won is written ONLY by ConvertLeadToCustomer (L1); it is never offered
 *     in the picker (see selectable()).
 *   - Lost / DoNotContact require a `lost_reason`.
 *
 *   new ──▶ contacted ──▶ interested ──▶ quoted ──▶ won
 *     └──────┴─────────────┴────────────┴──▶ lost / not_now / do_not_contact
 */
enum LeadStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Contacted = 'contacted';
    case Interested = 'interested';
    case Quoted = 'quoted';
    case Won = 'won';
    case Lost = 'lost';
    case NotNow = 'not_now';
    case DoNotContact = 'do_not_contact';

    public function getLabel(): string
    {
        return match ($this) {
            self::New => 'Νέο',
            self::Contacted => 'Επικοινωνήσαμε',
            self::Interested => 'Ενδιαφέρεται',
            self::Quoted => 'Στάλθηκε προσφορά',
            self::Won => 'Πελάτης',
            self::Lost => 'Χάθηκε',
            self::NotNow => 'Όχι τώρα',
            self::DoNotContact => 'Μην ξαναενοχλήσετε',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'gray',
            self::Contacted => 'info',
            self::Interested => 'primary',
            self::Quoted => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
            self::NotNow => 'gray',
            self::DoNotContact => 'danger',
        };
    }

    /** Still being worked (shows in the default «Ανοιχτά» tab, counts as due/stale). */
    public function isOpen(): bool
    {
        return in_array($this, [self::New, self::Contacted, self::Interested, self::Quoted, self::NotNow], true);
    }

    /** Needs a `lost_reason`. */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Lost, self::DoNotContact], true);
    }

    /**
     * Statuses an operator may pick by hand — everything except Won, which is
     * only ever set by the conversion action.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s): bool => $s !== self::Won));
    }

    /**
     * value => label map for Filament Selects / filters (selectable only).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::selectable())
            ->mapWithKeys(fn (self $s): array => [$s->value => $s->getLabel()])
            ->all();
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return array_map(
            fn (self $s): string => $s->value,
            array_values(array_filter(self::cases(), fn (self $s): bool => $s->isOpen())),
        );
    }
}
