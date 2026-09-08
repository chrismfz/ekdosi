<?php

namespace App\Filament\Reports\Widgets;

use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Money;
use Filament\Facades\Filament;
use Filament\Widgets\ChartWidget;

/**
 * Πρόβλεψη επόμενου έτους (seasonal-naive). NOW-anchored, not filter-driven
 * — "next year" is a fixed concept — so it deliberately does NOT use the
 * page year filter. Plots the forecast against last year for shape, with
 * the projected total + growth assumption in the description. Clearly an
 * εκτίμηση, never a commitment (DashboardMetrics::projectNextYear).
 */
class ProjectionChart extends ChartWidget
{
    protected static ?int $sort = 8;

    private const MONTHS = ['Ιαν', 'Φεβ', 'Μάρ', 'Απρ', 'Μάι', 'Ιούν', 'Ιούλ', 'Αύγ', 'Σεπ', 'Οκτ', 'Νοέ', 'Δεκ'];

    public function getHeading(): ?string
    {
        $tenant = Filament::getTenant();
        $next = $tenant instanceof Company
            ? (new DashboardMetrics($tenant))->projectNextYear()['nextYear']
            : (int) now()->year + 1;

        return "Πρόβλεψη {$next} (εκτίμηση)";
    }

    public function getDescription(): ?string
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return null;
        }

        $p = (new DashboardMetrics($tenant))->projectNextYear();
        if (! $p['hasHistory']) {
            return 'Δεν υπάρχει αρκετό ιστορικό για πρόβλεψη.';
        }

        return sprintf(
            'Εκτιμώμενος τζίρος %d: %s • υπόθεση ανάπτυξης %+.1f%% επί του %d (%s) • εκτίμηση βάσει ιστορικού, όχι δέσμευση.',
            $p['nextYear'],
            Money::eur($p['total']),
            $p['growthPct'],
            $p['baseYear'],
            Money::eur($p['baseTotal']),
        );
    }

    protected function getData(): array
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return ['datasets' => [], 'labels' => []];
        }

        $metrics = new DashboardMetrics($tenant);
        $p = $metrics->projectNextYear();
        $lastYear = array_column($metrics->monthlyForYear($p['baseYear']), 'net');

        return [
            'datasets' => [
                [
                    'label' => 'Πρόβλεψη '.$p['nextYear'],
                    'data' => $p['monthly'],
                    'borderColor' => '#16a34a',
                    'borderDash' => [6, 4],
                    'fill' => false,
                ],
                [
                    'label' => (string) $p['baseYear'],
                    'data' => $lastYear,
                    'borderColor' => '#9ca3af',
                    'fill' => false,
                ],
            ],
            'labels' => self::MONTHS,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
