<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\CustomerTrialBalanceReport;
use App\Services\Accounting\CustomerTrialBalanceResult;
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
 * «Ισοζύγιο Πελατών» (#4) — customer trial balance for a period: per customer,
 * `Υπόλοιπο μεταφοράς | Χρέωση | Πίστωση | Τελικό`, footed totals, CSV export.
 * Read-only.
 *
 * Built on CustomerTrialBalanceReport → CustomerLedgerBuilder::periodBalances, so
 * each row equals that customer's Καρτέλα balance and Σ(Τελικό) reconciles with
 * the dashboard / aged-receivables receivables. Admin-gated on
 * View:CustomerTrialBalance (new permission → shield:generate after deploy).
 */
class CustomerTrialBalance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.customer-trial-balance';

    /** Period bounds (Y-m-d), bound to the date inputs in the view. */
    public ?string $from = null;

    public ?string $to = null;

    private ?CustomerTrialBalanceResult $result = null;

    public static function getNavigationLabel(): string
    {
        return 'Ισοζύγιο Πελατών';
    }

    public function getTitle(): string
    {
        return 'Ισοζύγιο Πελατών';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:CustomerTrialBalance');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        // Default: year-to-date (Jan 1 → today).
        $this->from ??= Carbon::now()->startOfYear()->format('Y-m-d');
        $this->to ??= Carbon::now()->format('Y-m-d');
    }

    /** Memoised per request (a private prop isn't persisted → fresh each Livewire request). */
    public function getResult(): CustomerTrialBalanceResult
    {
        if ($this->result !== null) {
            return $this->result;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        [$start, $end] = $this->window();

        return $this->result = app(CustomerTrialBalanceReport::class)->build($tenant, $start, $end);
    }

    public function fmt(float $value): string
    {
        return Money::eur($value);
    }

    /**
     * Resolve the from/to inputs into [start, end] Carbon bounds, defensively:
     * a blank/invalid value falls back to year-to-date, and a reversed range is
     * swapped so the report never runs on an empty window.
     *
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function window(): array
    {
        $start = $this->parseOr($this->from, Carbon::now()->startOfYear())->startOfDay();
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
        $name = 'isozygio-pelaton-'.now()->format('Y-m-d').'.csv';
        $num = fn ($v): string => number_format((float) $v, 2, ',', '');
        // Neutralise CSV formula injection in free-text fields (=,+,-,@ → quote).
        $safe = fn (string $v): string => ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;

        return response()->streamDownload(function () use ($result, $num, $safe): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Πελάτης', 'ΑΦΜ', 'Υπόλοιπο μεταφοράς', 'Χρέωση', 'Πίστωση', 'Τελικό υπόλοιπο'], ';', escape: '');
            foreach ($result->rows as $row) {
                fputcsv($h, [
                    $safe($row->customerName), $safe($row->afm ?? ''),
                    $num($row->opening), $num($row->debit), $num($row->credit), $num($row->closing),
                ], ';', escape: '');
            }
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, [
                'Σύνολα', '',
                $num($result->totalOpening()), $num($result->totalDebit()),
                $num($result->totalCredit()), $num($result->totalClosing()),
            ], ';', escape: '');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
