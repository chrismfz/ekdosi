<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Where a lead came from («από πού ήρθε»). Carried over to the customer's
 * «Προέλευση» section on conversion.
 */
enum LeadSource: string implements HasLabel
{
    case ColdCall = 'cold_call';
    case Referral = 'referral';
    case Website = 'website';
    case Event = 'event';
    case ExistingCustomer = 'existing_customer';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ColdCall => 'Κρύα κλήση',
            self::Referral => 'Σύσταση',
            self::Website => 'Ιστοσελίδα',
            self::Event => 'Εκδήλωση',
            self::ExistingCustomer => 'Υπάρχων πελάτης',
            self::Other => 'Άλλο',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $s): array => [$s->value => $s->getLabel()])
            ->all();
    }
}
