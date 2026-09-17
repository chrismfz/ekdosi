<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\CashJournalReport as CashJournalService;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Ταμειακό ημερολόγιο (Εισπράξεων–Πληρωμών)» (#10) — cash movements for a period,
 * grouped by ACCOUNT (bank account / ταμείο) → PAYMENT METHOD: εισπράξεις (money IN)
 * vs πληρωμές/επιστροφές (money OUT), subtotals + καθαρή ταμειακή ροή, CSV export.
 * Read-only, built on {@see CashJournalService}. Covers the `payments` table only
 * (customer receipts + refunds) — the Βιβλίο Εσόδων-Εξόδων covers Πωλήσεις/Αγορές.
 *
 * Gated on View:CashJournalReport (run shield:generate + re-provision after deploy),
 * like the other Λογιστικά reports (company_admin gets it, operator does not).
 */
class CashJournalReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.cash-journal-report';

    /** Period bounds (Y-m-d), bound to the date inputs in the view. */
    public ?string $from = null;

    public ?string $to = null;

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    public static function getNavigationLabel(): string
    {
        return 'Ταμειακό ημερολόγιο';
    }

    public function getTitle(): string
    {
        return 'Ταμειακό ημερολόγιο';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:CashJournalReport');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        // Default: the current month to date (a cash journal is naturally monthly).
        $this->from ??= Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->to ??= Carbon::now()->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        if ($this->result !== null) {
            return $this->result;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        [$start, $end] = $this->window();

        return $this->result = app(CashJournalService::class)->build($tenant, $start, $end);
    }

    public function fmt(float $value): string
    {
        return Money::eur($value);
    }

    /**
     * Resolve the from/to inputs into [start, end] Carbon bounds, defensively: a
     * blank/invalid value falls back to the current month, and a reversed range is
     * swapped so the report never runs on an empty window.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function window(): array
    {
        $start = $this->parseOr($this->from, Carbon::now()->startOfMonth())->startOfDay();
        $end = $this->parseOr($this->to, Carbon::now())->endOfDay();

        if ($start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    private function parseOr(?string $value, CarbonInterface $fallback): Carbon
    {
        try {
            return $value ? Carbon::parse($value) : Carbon::instance($fallback);
        } catch (\Throwable) {
            return Carbon::instance($fallback);
        }
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
        $name = 'tameiako-imerologio-'.now()->format('Y-m-d').'.csv';
        $num = fn (float $v): string => number_format($v, 2, ',', '');
        // Neutralise CSV formula injection in free-text fields (first non-whitespace
        // char =,+,-,@ → prefix with a quote).
        $safe = function (string $v): string {
            $t = ltrim($v, " \t\r\n");

            return ($t !== '' && in_array($t[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        };

        return response()->streamDownload(function () use ($result, $num, $safe): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Ταμειακό ημερολόγιο', $result['period_label']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Λογαριασμός', 'Τρόπος', 'Εισπράξεις', 'Πληρωμές', 'Καθαρό', 'Πλήθος'], ';', escape: '');
            foreach ($result['accounts'] as $acc) {
                foreach ($acc['methods'] as $m) {
                    fputcsv($h, [
                        $safe((string) $acc['account']), $safe((string) $m['method']),
                        $num($m['in']), $num($m['out']), $num($m['net']), (string) $m['count'],
                    ], ';', escape: '');
                }
                // Per-account subtotal.
                fputcsv($h, [
                    $safe((string) $acc['account']), 'Σύνολο λογαριασμού',
                    $num($acc['in']), $num($acc['out']), $num($acc['net']), (string) $acc['count'],
                ], ';', escape: '');
            }
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, [
                'ΣΥΝΟΛΟ', '',
                $num($result['total_in']), $num($result['total_out']), $num($result['total_net']), (string) $result['total_count'],
            ], ';', escape: '');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
