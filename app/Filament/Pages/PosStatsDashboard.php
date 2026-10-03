<?php

namespace App\Filament\Pages;

use App\Filament\PosStats\Widgets\PosBusyHours;
use App\Filament\PosStats\Widgets\PosCashierShareChart;
use App\Filament\PosStats\Widgets\PosCumulativeChart;
use App\Filament\PosStats\Widgets\PosDailyChart;
use App\Filament\PosStats\Widgets\PosMonthlyChart;
use App\Filament\PosStats\Widgets\PosStatsKpis;
use App\Models\Company;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * «Ταμεία — Στατιστικά» — the «Αναφορές & Στατιστικά» of the physical till: turnover
 * per day (a month) and per month (a year), against a comparison year; today vs the
 * same weekday last week; busy hours; share per cashier. Only TILL documents
 * (App\Services\Pos\PosStats). Same Dashboard + page-filters wiring as Reports; its
 * widgets live in App\Filament\PosStats\Widgets so they appear ONLY here.
 * company_admin / super_admin (View:PosStatsDashboard).
 */
class PosStatsDashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'pos-stats';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static string|UnitEnum|null $navigationGroup = 'Καθημερινά';

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return 'Ταμεία — στατιστικά';
    }

    public function getTitle(): string
    {
        return 'Ταμεία — στατιστικά';
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->hasPos()
            && (bool) auth()->user()?->can('View:PosStatsDashboard');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            PosStatsKpis::class,
            PosDailyChart::class,
            PosMonthlyChart::class,
            PosCumulativeChart::class,
            PosCashierShareChart::class,
            PosBusyHours::class,
        ];
    }

    public function filtersForm(Schema $schema): Schema
    {
        $years = $this->yearOptions();
        $months = ['Ιανουάριος', 'Φεβρουάριος', 'Μάρτιος', 'Απρίλιος', 'Μάιος', 'Ιούνιος', 'Ιούλιος', 'Αύγουστος', 'Σεπτέμβριος', 'Οκτώβριος', 'Νοέμβριος', 'Δεκέμβριος'];

        return $schema->components([
            Select::make('year')->label('Έτος')->options($years)
                ->default((int) Carbon::now()->year)->selectablePlaceholder(false)->live(),
            Select::make('month')->label('Μήνας (ανά ημέρα)')->options(array_combine(range(1, 12), $months))
                ->default((int) Carbon::now()->month)->selectablePlaceholder(false)->live(),
            Select::make('compare_year')->label('Σύγκριση με')->options($years)
                ->default((int) Carbon::now()->year - 1)->selectablePlaceholder(false)->live(),
        ]);
    }

    /** @return array<int, string> years with till documents + this and last year, newest first */
    private function yearOptions(): array
    {
        $tenant = Filament::getTenant();
        $span = $tenant instanceof Company
            ? DB::table('invoices')->where('company_id', $tenant->getKey())->whereNotNull('pos_session_id')
                ->selectRaw('MIN(issued_at) AS first, MAX(issued_at) AS last')->first()
            : null;
        $years = $span?->first ? range((int) Carbon::parse($span->first)->year, (int) Carbon::parse($span->last)->year) : [];
        $now = (int) Carbon::now()->year;
        $years = array_values(array_unique([...$years, $now, $now - 1]));
        rsort($years);

        return array_combine($years, array_map('strval', $years));
    }
}
