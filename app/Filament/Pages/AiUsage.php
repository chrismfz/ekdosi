<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Assistant\AiUsageReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * AI «Βοηθός» Phase 2c (ε): «Χρήση & κόστος AI» — the usage/billing surface over
 * `ai_usage_log` («ποιος πληρώνει, ποιος κοντά στο όριο»). Sits INSIDE the «AI
 * Βοηθός» area (group «Σύστημα», next to «Βοηθός AI»), NOT the central dashboard.
 *
 * CROSS-TENANT (all companies), so it is hard-gated to a SYSTEM super_admin —
 * NEVER a Shield-grantable permission, because granting a company_admin a
 * cross-tenant cost view would leak other tenants' spend. Read-only; the numbers
 * come from {@see AiUsageReport} (a thin aggregation, no new data).
 */
class AiUsage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Σύστημα';

    protected static ?int $navigationSort = 51; // right after «Βοηθός AI» (50)

    protected string $view = 'filament.pages.ai-usage';

    /** Selected calendar month (`Y-m`); defaults to the current month. */
    public string $month = '';

    /** @var array<string,mixed>|null */
    private ?array $report = null;

    public static function getNavigationLabel(): string
    {
        return 'Χρήση & κόστος AI';
    }

    public function getTitle(): string
    {
        return 'Χρήση & κόστος AI';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->isSystemSuperAdmin();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        if (! CarbonImmutable::hasFormat($this->month, 'Y-m')) {
            $this->month = CarbonImmutable::now()->format('Y-m');
        }
    }

    /** @return array<string,mixed> */
    public function getReport(): array
    {
        return $this->report ??= app(AiUsageReport::class)->forMonth($this->month);
    }

    public function updatedMonth(): void
    {
        $this->report = null; // recompute for the newly-picked month
    }

    /**
     * The last 12 months as `Y-m` => Greek label, newest first, for the picker.
     *
     * @return array<string,string>
     */
    public function getMonthOptions(): array
    {
        $months = [
            1 => 'Ιαν', 2 => 'Φεβ', 3 => 'Μαρ', 4 => 'Απρ', 5 => 'Μάι', 6 => 'Ιουν',
            7 => 'Ιουλ', 8 => 'Αυγ', 9 => 'Σεπ', 10 => 'Οκτ', 11 => 'Νοε', 12 => 'Δεκ',
        ];
        $out = [];
        $m = CarbonImmutable::now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $key = $m->format('Y-m');
            $out[$key] = ($months[(int) $m->format('n')] ?? '').' '.$m->format('Y');
            $m = $m->subMonth();
        }

        return $out;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')
                ->label('Εξαγωγή CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->button()
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $report = $this->getReport();
        $name = 'ai-usage-'.$report['month'].'.csv';
        // Neutralise CSV formula injection in the free-text name columns.
        $safe = fn (?string $v): string => (($v = (string) $v) !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        $num = fn ($v): string => number_format((float) $v, 4, '.', '');

        return response()->streamDownload(function () use ($report, $safe, $num): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

            fputcsv($h, ['Χρήση & κόστος AI', $report['monthLabel']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');

            fputcsv($h, ['Εταιρεία', 'Αιτήματα', 'Tokens (in)', 'Tokens (out)', 'Cache read', 'Cache write', 'Χρεώσιμα tokens', 'Όριο μήνα', '% ορίου', 'Κόστος (USD)'], ';', escape: '');
            foreach ($report['companies'] as $r) {
                fputcsv($h, [
                    $safe($r['name']), $r['requests'], $r['input'], $r['output'], $r['cacheRead'], $r['cacheWrite'],
                    $r['billable'], $r['cap'] ?? '', $r['pct'] !== null ? round($r['pct'] * 100, 1) : '', $num($r['cost']),
                ], ';', escape: '');
            }
            $t = $report['totals'];
            fputcsv($h, ['Σύνολα', $t['requests'], $t['input'], $t['output'], $t['cacheRead'], $t['cacheWrite'], $t['billable'], '', '', $num($t['cost'])], ';', escape: '');

            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Χρήστης', 'Εταιρεία', 'Αιτήματα', 'Χρεώσιμα tokens', 'Κόστος (USD)'], ';', escape: '');
            foreach ($report['users'] as $r) {
                fputcsv($h, [$safe($r['name']), $safe($r['company']), $r['requests'], $r['billable'], $num($r['cost'])], ';', escape: '');
            }
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
