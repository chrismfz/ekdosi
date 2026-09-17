<?php

namespace App\Filament\Pages;

use App\Filament\Reports\Widgets\ProjectionChart;
use App\Filament\Reports\Widgets\ReceiptsByMonthChart;
use App\Filament\Reports\Widgets\ReportKpis;
use App\Filament\Reports\Widgets\RevenueByMonthChart;
use App\Filament\Reports\Widgets\RevenueByYearChart;
use App\Filament\Reports\Widgets\SeasonalCurveChart;
use App\Filament\Reports\Widgets\SeasonalityHeatmap;
use App\Filament\Reports\Widgets\VatByRateQuarterTable;
use App\Filament\Reports\Widgets\YearVsYearChart;
use App\Models\Company;
use App\Services\Dashboard\DashboardMetrics;
use App\Support\Dashboard\DashboardMetricsCache;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * «Αναφορές & Στατιστικά» — a second dashboard dedicated to the operator's
 * own books (local-first): KPI scorecard + turnover trends. Built on
 * Filament's Dashboard so the year filter drives the widgets through the
 * SAME proven InteractsWithPageFilters wiring as the main dashboard.
 *
 * Its widgets live in App\Filament\Reports\Widgets (NOT the auto-discovered
 * app/Filament/Widgets), so they appear ONLY here — getWidgets() lists them
 * explicitly. The myDATA / ΑΑΔΕ section is a later phase.
 */
class Reports extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'reports';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 40;

    public static function getNavigationLabel(): string
    {
        return 'Αναφορές';
    }

    public function getTitle(): string
    {
        return 'Αναφορές & Στατιστικά';
    }

    /**
     * Financial analytics → admin territory: gated on View:Reports (company_admin
     * + super_admin; operators excluded). Gate::can is 404-storm-safe (missing
     * permission → false, not a throw).
     */
    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:Reports');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    /**
     * «Ανανέωση» — bust this tenant's cached metric slices (version bump) and
     * reload, so the figures reflect just-issued documents immediately instead
     * of waiting for the TTL / the scheduled warm. The reload preserves the URL
     * (and its year filters); the widgets then rebuild fresh on their next lazy
     * load. The escape hatch for the «TTL + scheduled warm» freshness model.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshMetrics')
                ->label('Ανανέωση')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    $tenant = Filament::getTenant();
                    if ($tenant instanceof Company) {
                        DashboardMetricsCache::bump($tenant);
                    }

                    Notification::make()
                        ->title('Τα δεδομένα των αναφορών ανανεώθηκαν.')
                        ->success()
                        ->send();

                    return redirect(url()->full());
                }),
        ];
    }

    /**
     * @return array<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        return [
            ReportKpis::class,
            RevenueByMonthChart::class,
            ReceiptsByMonthChart::class,
            YearVsYearChart::class,
            RevenueByYearChart::class,
            // ΦΠΑ εκροών ανά συντελεστή × τρίμηνο (βοηθητικό για την περιοδική δήλωση).
            VatByRateQuarterTable::class,
            // Phase 2 — εποχικότητα + πρόβλεψη.
            SeasonalCurveChart::class,
            ProjectionChart::class,
            SeasonalityHeatmap::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $options = $this->yearOptions();

        return $schema->components([
            Select::make('year')
                ->label('Έτος')
                ->options($options)
                ->default((int) Carbon::now()->year)
                ->selectablePlaceholder(false)
                ->live(),

            Select::make('compare_year')
                ->label('Σύγκριση με')
                ->options($options)
                ->default((int) Carbon::now()->year - 1)
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Για το γράφημα σωρευτικής σύγκρισης.'),
        ]);
    }

    /**
     * Year Select options: the years that actually have invoices, plus the
     * current and previous year so the defaults always resolve. Newest first.
     *
     * @return array<int, string>
     */
    private function yearOptions(): array
    {
        $tenant = Filament::getTenant();
        $years = $tenant instanceof Company
            ? (new DashboardMetrics($tenant))->availableYears()
            : [];

        $now = (int) Carbon::now()->year;
        $years = array_values(array_unique([...$years, $now, $now - 1]));
        rsort($years);

        return array_combine($years, array_map('strval', $years));
    }
}
