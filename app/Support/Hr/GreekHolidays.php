<?php

namespace App\Support\Hr;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Greek national public holidays (αργίες) — the fixed-date ones plus the
 * Orthodox-Easter-relative ones (Καθαρά Δευτέρα, Μ. Παρασκευή, Δευτέρα του
 * Πάσχα, Αγίου Πνεύματος). Local ones (πολιούχος, απελευθέρωση) have no
 * official source — the tenant keeps them as CompanyHoliday rows; WorkingDays
 * merges both.
 */
final class GreekHolidays
{
    /** @var array<int, array<string, string>> */
    private static array $cache = [];

    /**
     * Orthodox Easter Sunday (Meeus Julian algorithm + Julian→Gregorian offset,
     * valid 1900–2099).
     */
    public static function orthodoxEaster(int $year): CarbonImmutable
    {
        $a = $year % 4;
        $b = $year % 7;
        $c = $year % 19;
        $d = (19 * $c + 15) % 30;
        $e = (2 * $a + 4 * $b - $d + 34) % 7;
        $month = intdiv($d + $e + 114, 31);
        $day = (($d + $e + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->addDays(13);
    }

    /**
     * @return array<string, string> 'Y-m-d' => Greek name
     */
    public static function forYear(int $year): array
    {
        if (isset(self::$cache[$year])) {
            return self::$cache[$year];
        }

        $easter = self::orthodoxEaster($year);

        $days = [
            sprintf('%d-01-01', $year) => 'Πρωτοχρονιά',
            sprintf('%d-01-06', $year) => 'Θεοφάνεια',
            $easter->subDays(48)->toDateString() => 'Καθαρά Δευτέρα',
            sprintf('%d-03-25', $year) => '25η Μαρτίου',
            $easter->subDays(2)->toDateString() => 'Μεγάλη Παρασκευή',
            $easter->addDay()->toDateString() => 'Δευτέρα του Πάσχα',
            sprintf('%d-05-01', $year) => 'Πρωτομαγιά',
            $easter->addDays(50)->toDateString() => 'Αγίου Πνεύματος',
            sprintf('%d-08-15', $year) => 'Κοίμηση της Θεοτόκου',
            sprintf('%d-10-28', $year) => '28η Οκτωβρίου',
            sprintf('%d-12-25', $year) => 'Χριστούγεννα',
            sprintf('%d-12-26', $year) => 'Σύναξη της Θεοτόκου',
        ];
        ksort($days);

        return self::$cache[$year] = $days;
    }

    public static function name(CarbonInterface $date): ?string
    {
        return self::forYear((int) $date->format('Y'))[$date->toDateString()] ?? null;
    }

    public static function isHoliday(CarbonInterface $date): bool
    {
        return self::name($date) !== null;
    }
}
