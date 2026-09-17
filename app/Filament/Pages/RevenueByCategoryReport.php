<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Accounting\RevenueByCategory;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Έσοδα ανά κατηγορία» (#2) — net/VAT/gross revenue for a year grouped by the
 * ekdosi business ProductCategory each line resolves to (line stamp → product →
 * «Αταξινόμητα»), with % of turnover and a YoY delta vs the prior year. Read-only,
 * built on {@see RevenueByCategory}. Gated on View:RevenueByCategoryReport
 * (run shield:generate + re-provision after deploy), like the other Λογιστικά reports.
 */
class RevenueByCategoryReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Έσοδα ανά κατηγορία';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.pages.revenue-by-category-report';

    public int $year;

    /** @var array{year:int,rows:array,total_net:float,total_vat:float,total_gross:float,prior_total_net:float}|null */
    private ?array $result = null;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return 'Έσοδα ανά κατηγορία';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:RevenueByCategoryReport');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Years that actually have invoices, newest first (+ always the current year),
     * for the period picker.
     *
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $years = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->selectRaw('DISTINCT YEAR(issued_at) as y')
            ->orderByDesc('y')
            ->pluck('y')
            ->map(fn ($y) => (int) $y)
            ->all();

        $years = array_values(array_unique(array_merge([(int) now()->year], $years)));
        rsort($years);

        return array_combine($years, $years);
    }

    /** @return array{year:int,rows:array,total_net:float,total_vat:float,total_gross:float,prior_total_net:float} */
    public function getResult(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->result = app(RevenueByCategory::class)->build($tenant, $this->year);
    }

    public function fmt(float $v): string
    {
        return Money::eur($v);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Εξαγωγή CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $result = $this->getResult();
        $name = 'esoda-ana-katigoria-'.$result['year'].'.csv';
        // Neutralise CSV formula injection in the (free-text) category name.
        $safe = fn (string $v): string => ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        $num = fn (float $v): string => number_format($v, 2, ',', '');

        return response()->streamDownload(function () use ($result, $safe, $num): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Έσοδα ανά κατηγορία', (string) $result['year']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Κατηγορία', 'Καθαρή αξία', 'ΦΠΑ', 'Μεικτό', 'Γραμμές', '% τζίρου', 'Πέρσι (καθαρό)', 'Μεταβολή'], ';', escape: '');
            foreach ($result['rows'] as $row) {
                fputcsv($h, [
                    $safe((string) $row['name']),
                    $num($row['net']), $num($row['vat']), $num($row['gross']),
                    (string) $row['lines'], $num($row['pct']),
                    $num($row['prior_net']), $num($row['delta']),
                ], ';', escape: '');
            }
            fputcsv($h, [
                'ΣΥΝΟΛΟ', $num($result['total_net']), $num($result['total_vat']),
                $num($result['total_gross']), '', '100,00', $num($result['prior_total_net']),
                $num($result['total_net'] - $result['prior_total_net']),
            ], ';', escape: '');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
