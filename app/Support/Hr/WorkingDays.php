<?php

namespace App\Support\Hr;

use App\Models\CompanyHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A tenant's non-working-day calendar: weekends + national holidays
 * (GreekHolidays) + the company's own active holidays (CompanyHoliday).
 * One instance per company; holiday lookups are memoised per year.
 */
final class WorkingDays
{
    /** @var array<int, array<string, string>> year => ['Y-m-d' => name] */
    private array $years = [];

    public function __construct(private readonly int $companyId) {}

    public static function for(int $companyId): self
    {
        return new self($companyId);
    }

    /** @return array<string, string> 'Y-m-d' => name (national + company) */
    public function holidays(int $year): array
    {
        if (isset($this->years[$year])) {
            return $this->years[$year];
        }

        $days = GreekHolidays::forYear($year);

        CompanyHoliday::query()
            ->where('company_id', $this->companyId)
            ->where('is_active', true)
            ->get()
            ->each(function (CompanyHoliday $h) use ($year, &$days): void {
                $date = $h->dateInYear($year);
                if ($date !== null) {
                    $days[$date] = isset($days[$date]) ? $days[$date].' · '.$h->name : $h->name;
                }
            });
        ksort($days);

        return $this->years[$year] = $days;
    }

    public function holidayName(CarbonInterface $date): ?string
    {
        return $this->holidays((int) $date->format('Y'))[$date->toDateString()] ?? null;
    }

    public function isWorkingDay(CarbonInterface $date): bool
    {
        return ! $date->isWeekend() && $this->holidayName($date) === null;
    }

    /** Inclusive working-day count between two dates (0 if $to < $from). */
    public function count(CarbonInterface $from, CarbonInterface $to): int
    {
        $cursor = CarbonImmutable::parse($from->toDateString());
        $end = CarbonImmutable::parse($to->toDateString());
        $n = 0;

        while ($cursor->lte($end)) {
            if ($this->isWorkingDay($cursor)) {
                $n++;
            }
            $cursor = $cursor->addDay();
        }

        return $n;
    }
}
