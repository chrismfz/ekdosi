<?php

namespace Tests\Feature\Hr;

use App\Enums\HolidayRule;
use App\Models\CompanyHoliday;
use App\Support\Hr\GreekHolidays;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;

class WorkingDaysTest extends HrTestCase
{
    public function test_orthodox_easter_known_years(): void
    {
        foreach (['2024' => '2024-05-05', '2025' => '2025-04-20', '2026' => '2026-04-12', '2027' => '2027-05-02', '2030' => '2030-04-28'] as $y => $date) {
            $this->assertSame($date, GreekHolidays::orthodoxEaster((int) $y)->toDateString(), "Easter {$y}");
        }
    }

    public function test_moveable_national_holidays_2026(): void
    {
        $h = GreekHolidays::forYear(2026);
        $this->assertSame('Καθαρά Δευτέρα', $h['2026-02-23']);
        $this->assertSame('Μεγάλη Παρασκευή', $h['2026-04-10']);
        $this->assertSame('Δευτέρα του Πάσχα', $h['2026-04-13']);
        $this->assertSame('Αγίου Πνεύματος', $h['2026-06-01']);
        $this->assertCount(12, $h);
    }

    public function test_counts_exclude_weekends_national_and_company_holidays(): void
    {
        $days = WorkingDays::for($this->company->id);
        // 21/12/2026 – 08/01/2027: 15 weekdays minus 25/12, 1/1, 6/1 = 12.
        $this->assertSame(12, $days->count(CarbonImmutable::parse('2026-12-21'), CarbonImmutable::parse('2027-01-08')));

        // Xanthi's πολιούχος (Sat 29/8/2026 → no effect) and a Monday closure.
        CompanyHoliday::create(['company_id' => $this->company->id, 'name' => 'Πολιούχος', 'rule' => HolidayRule::Fixed, 'month' => 8, 'day' => 29]);
        CompanyHoliday::create(['company_id' => $this->company->id, 'name' => 'Κλειστά', 'rule' => HolidayRule::Once, 'date' => '2026-08-24']);
        CompanyHoliday::create(['company_id' => $this->company->id, 'name' => 'Ανενεργή', 'rule' => HolidayRule::Once, 'date' => '2026-08-25', 'is_active' => false]);

        $days = WorkingDays::for($this->company->id);
        $this->assertSame(4, $days->count(CarbonImmutable::parse('2026-08-24'), CarbonImmutable::parse('2026-08-28')));
        $this->assertSame('Κλειστά', $days->holidayName(CarbonImmutable::parse('2026-08-24')));
        $this->assertNull($days->holidayName(CarbonImmutable::parse('2026-08-25')), 'inactive holiday ignored');

        // Another tenant's holidays never leak in.
        $this->assertSame(5, WorkingDays::for($this->company->id + 999)->count(CarbonImmutable::parse('2026-08-24'), CarbonImmutable::parse('2026-08-28')));
    }

    public function test_easter_relative_company_holiday(): void
    {
        $h = CompanyHoliday::create(['company_id' => $this->company->id, 'name' => 'Τοπική', 'rule' => HolidayRule::Easter, 'easter_offset' => 3]);
        $this->assertSame('2026-04-15', $h->dateInYear(2026));
        $this->assertSame('2027-05-05', $h->dateInYear(2027));
    }
}
