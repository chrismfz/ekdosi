<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Accounting\WithholdingsReport as WithholdingsReportService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Παρακρατήσεις» — the income tax customers withheld on our invoices for a year,
 * per customer (to tick off against their βεβαιώσεις παρακράτησης) and per document,
 * plus a CSV for the accountant. Same total as «Φορολογικά» (built on the same book —
 * {@see WithholdingsReportService}). Gated on View:WithholdingsReport (run
 * shield:generate + re-provision after deploy), like the other Λογιστικά reports.
 */
class WithholdingsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Παρακρατήσεις';

    protected static ?int $navigationSort = 26;

    protected string $view = 'filament.pages.withholdings-report';

    public int $year;

    private ?array $result = null;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return 'Παρακρατήσεις φόρου';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:WithholdingsReport');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function updated(): void
    {
        $this->result = null;
    }

    /** @return array<int, int> */
    public function availableYears(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $lo = Invoice::query()->where('company_id', $tenant->getKey())->where('withhold_amount', '>', 0)->min('issued_at');
        $years = range((int) now()->year, $lo ? min((int) Carbon::parse($lo)->year, (int) now()->year) : (int) now()->year);

        return array_combine($years, $years);
    }

    public function getResult(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->result ??= app(WithholdingsReportService::class)->build($tenant, $this->year);
    }

    public function invoiceUrl(?int $id): ?string
    {
        return $id ? InvoiceResource::getUrl('view', ['record' => $id]) : null;
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
        // Neutralise CSV formula injection in the free-text columns.
        $safe = function (?string $v): string {
            $v ??= '';
            $t = ltrim($v, " \t\r\n");

            return ($t !== '' && in_array($t[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        };
        $num = fn (float $v): string => number_format($v, 2, ',', '');

        return response()->streamDownload(function () use ($result, $safe, $num): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Παρακρατήσεις φόρου', (string) $result['year']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Ημερομηνία', 'Παραστατικό', 'Πελάτης', 'ΑΦΜ', 'Καθαρή αξία', 'Μεικτό', 'Παρακράτηση', '%'], ';', escape: '');
            foreach ($result['documents'] as $d) {
                fputcsv($h, [
                    $d['date'], $safe($d['doc']), $safe($d['customer']), $safe($d['afm']),
                    $num($d['net']), $num($d['gross']), $num($d['withheld']),
                    $d['rate'] !== null ? $num($d['rate']) : '',
                ], ';', escape: '');
            }
            fputcsv($h, ['ΣΥΝΟΛΟ', '', '', '', $num($result['total_net']), '', $num($result['total_withheld']), ''], ';', escape: '');
            fclose($h);
        }, 'parakratiseis-'.$result['year'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
