<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\Accounting\RevenueByItem;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Ισοζύγιο Ειδών/Υπηρεσιών» (#4) — net/VAT/gross turnover for a year grouped by
 * the item each line sold (product → normalised free-text description), with the
 * quantity, its dominant ekdosi category, % of turnover and a YoY delta. The
 * item-grain sibling of «Έσοδα ανά κατηγορία» (#2): same math, so Σ(items)
 * reconciles with the category totals. Read-only, built on {@see RevenueByItem}.
 * Gated on View:RevenueByItemReport (run shield:generate + re-provision after
 * deploy), like the other Λογιστικά reports.
 *
 * On-screen the table shows the top {@see DISPLAY_LIMIT} items by net plus a single
 * summed «Λοιπά είδη» row when there are more, so the footer always reconciles with
 * the visible rows; the CSV export carries EVERY item, uncapped.
 */
class RevenueByItemReport extends Page
{
    /** Top-N items rendered on screen; the rest fold into one «Λοιπά είδη» row. */
    private const DISPLAY_LIMIT = 200;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Ισοζύγιο Ειδών/Υπηρεσιών';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.revenue-by-item-report';

    public int $year;

    /** @var array{year:int,rows:array,total_net:float,total_vat:float,total_gross:float,prior_total_net:float}|null */
    private ?array $result = null;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return 'Ισοζύγιο Ειδών/Υπηρεσιών';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:RevenueByItemReport');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * The years the picker offers: the current year plus every year spanned by the
     * tenant's invoices, newest first. Derived from MIN/MAX(issued_at) rather than a
     * raw YEAR()/DISTINCT (which is MariaDB-only) so it's portable and testable; a
     * gap year with no invoices simply renders an empty report.
     *
     * @return array<int, int>
     */
    public function availableYears(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $bounds = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->selectRaw('MIN(issued_at) as lo, MAX(issued_at) as hi')
            ->first();

        $years = [(int) now()->year];
        if ($bounds?->lo) {
            $lo = (int) Carbon::parse($bounds->lo)->year;
            $hi = (int) Carbon::parse($bounds->hi)->year;
            for ($y = $hi; $y >= $lo; $y--) {
                $years[] = $y;
            }
        }

        $years = array_values(array_unique($years));
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

        return $this->result = app(RevenueByItem::class)->build($tenant, $this->year);
    }

    /**
     * The rows to render: the top DISPLAY_LIMIT items, plus a single summed
     * «Λοιπά είδη (n)» row when the report has more — so the on-screen table still
     * foots to the report totals without rendering thousands of rows.
     *
     * @return list<array<string, mixed>>
     */
    public function displayRows(): array
    {
        $rows = $this->getResult()['rows'];
        if (count($rows) <= self::DISPLAY_LIMIT) {
            return $rows;
        }

        $shown = array_slice($rows, 0, self::DISPLAY_LIMIT);
        $rest = array_slice($rows, self::DISPLAY_LIMIT);
        $result = $this->getResult();

        // The «Λοιπά είδη» row carries the RESIDUAL (report total − Σ shown), not the
        // sum of the tail, so the visible table foots EXACTLY to the footer totals
        // (the ±cent per-row rounding drift lands in this one row, as a remainder
        // row should). Same for prior/vat/gross; delta stays net − prior.
        $shownSum = fn (string $k): float => round(array_sum(array_map(fn (array $r): float => (float) $r[$k], $shown)), 2);
        $totalNet = (float) $result['total_net'];
        $restNet = round($totalNet - $shownSum('net'), 2);
        $restPrior = round((float) $result['prior_total_net'] - $shownSum('prior_net'), 2);

        $shown[] = [
            'key' => '__more__',
            'label' => 'Λοιπά είδη ('.count($rest).')',
            'product_id' => null,
            'sku' => null,
            'category_id' => null,
            'category' => '—',
            'qty' => null, // mixed items/units → no meaningful total
            'unit' => null,
            'net' => $restNet,
            'vat' => round((float) $result['total_vat'] - $shownSum('vat'), 2),
            'gross' => round((float) $result['total_gross'] - $shownSum('gross'), 2),
            'lines' => (int) array_sum(array_map(fn (array $r): int => (int) $r['lines'], $rest)),
            // From the residual net (not summed rounded pcts) so the column reconciles.
            'pct' => $totalNet != 0.0 ? round($restNet / $totalNet * 100, 1) : 0.0,
            'prior_net' => $restPrior,
            'delta' => round($restNet - $restPrior, 2),
            'is_more' => true,
        ];

        return $shown;
    }

    public function fmt(float $v): string
    {
        return Money::eur($v);
    }

    /**
     * A clean quantity label: no trailing decimal zeros («12» not «12,000»),
     * with the unit appended when known. The «Λοιπά είδη» row (mixed units) and a
     * unitless item pass null qty / null unit → «—» / bare number.
     */
    public function fmtQty(?float $qty, ?string $unit): string
    {
        if ($qty === null) {
            return '—';
        }

        $s = number_format($qty, 3, ',', '.');
        // Drop a trailing «,000» / superfluous zeros so integers read cleanly.
        if (str_contains($s, ',')) {
            $s = rtrim(rtrim($s, '0'), ',');
        }

        return $unit ? $s.' '.$unit : $s;
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
        $name = 'isozygio-eidon-'.$result['year'].'.csv';
        // Neutralise CSV formula injection in EVERY free-text column (label / sku /
        // category / unit). Inspect the first NON-whitespace char so a «  =…» with a
        // leading space/tab can't slip past. Numeric columns go through $num (a
        // well-formed number can't be a formula) so they're left unquoted.
        $safe = function (string $v): string {
            $t = ltrim($v, " \t\r\n");

            return ($t !== '' && in_array($t[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        };
        $num = fn (float $v): string => number_format($v, 2, ',', '');
        // Quantity: null (mixed-unit / «Λοιπά») → blank rather than a misleading 0.
        $qty = fn (?float $v): string => $v === null ? '' : number_format($v, 3, ',', '');

        return response()->streamDownload(function () use ($result, $safe, $num, $qty): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Ισοζύγιο Ειδών/Υπηρεσιών', (string) $result['year']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Είδος/Υπηρεσία', 'SKU', 'Κατηγορία', 'Ποσότητα', 'Μονάδα', 'Καθαρή αξία', 'ΦΠΑ', 'Μεικτό', 'Γραμμές', '% τζίρου', 'Πέρσι (καθαρό)', 'Μεταβολή'], ';', escape: '');
            foreach ($result['rows'] as $row) {
                fputcsv($h, [
                    $safe((string) $row['label']),
                    $safe((string) ($row['sku'] ?? '')),
                    $safe((string) $row['category']),
                    $qty($row['qty'] !== null ? (float) $row['qty'] : null),
                    $safe((string) ($row['unit'] ?? '')),
                    $num($row['net']), $num($row['vat']), $num($row['gross']),
                    (string) $row['lines'], $num($row['pct']),
                    $num($row['prior_net']), $num($row['delta']),
                ], ';', escape: '');
            }
            fputcsv($h, [
                'ΣΥΝΟΛΟ', '', '', '', '',
                $num($result['total_net']), $num($result['total_vat']), $num($result['total_gross']),
                // Match the on-screen footer: 100% only when there's a non-zero base.
                '', $result['total_net'] != 0.0 ? '100,00' : '', $num($result['prior_total_net']),
                $num($result['total_net'] - $result['prior_total_net']),
            ], ';', escape: '');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
