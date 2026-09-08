<?php

namespace App\Enums;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Recurring billing cycle for products / service contracts (WHMCS parity:
 * One-Time / Monthly / Quarterly / Semi-Annually / Annually / Biennially /
 * Triennially). The enum value is the stored token; labels are operator-facing
 * Greek. `advance()` computes the next due date — the single home for the
 * month/year arithmetic, using *NoOverflow so 31 Jan + 1 month → 28/29 Feb
 * (not 2/3 Mar). One-Time has no next due → null.
 */
enum BillingCycle: string
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semiannual';
    case Annual = 'annual';
    case Biennial = 'biennial';
    case Triennial = 'triennial';

    public function label(): string
    {
        return match ($this) {
            self::OneTime => 'Εφάπαξ',
            self::Monthly => 'Μηνιαία',
            self::Quarterly => 'Τριμηνιαία',
            self::SemiAnnual => 'Εξαμηνιαία',
            self::Annual => 'Ετήσια',
            self::Biennial => 'Διετής',
            self::Triennial => 'Τριετής',
        };
    }

    /** Months in the cycle (null for One-Time). Drives advance(). */
    public function months(): ?int
    {
        return match ($this) {
            self::OneTime => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::SemiAnnual => 6,
            self::Annual => 12,
            self::Biennial => 24,
            self::Triennial => 36,
        };
    }

    /**
     * The next due date after $from for this cycle, or null for One-Time.
     * addMonthsNoOverflow: 31 Jan + 1 month is 28/29 Feb (a plain addMonths
     * overflows to early March and silently shifts every future cycle).
     */
    public function advance(CarbonInterface $from): ?Carbon
    {
        $months = $this->months();
        if ($months === null) {
            return null;
        }

        return Carbon::parse($from)->addMonthsNoOverflow($months);
    }

    public function isRecurring(): bool
    {
        return $this !== self::OneTime;
    }

    /** @return array<string, string> value => label, for Filament selects. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
