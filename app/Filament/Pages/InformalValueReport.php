<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\Accounting\InformalValue;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Αξία άτυπων» — what the informal series (ΕΣΩ/ΔΟΚ: our own services, friends,
 * tests) delivered without charging, per customer / item / series for a year, plus
 * the per-document list (CSV) for the accountant. Built on {@see InformalValue};
 * docs/non-billable-services.md §8.5. Gated on View:InformalValueReport (run
 * shield:generate + re-provision after deploy), like the other Λογιστικά reports.
 */
class InformalValueReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?string $navigationLabel = 'Αξία άτυπων';

    protected static ?int $navigationSort = 25;

    protected string $view = 'filament.pages.informal-value-report';

    public int $year;

    /** '' = every informal series. */
    public string $series = '';

    private ?array $result = null;

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    public function getTitle(): string
    {
        return 'Αξία άτυπων';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:InformalValueReport');
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

        $lo = Invoice::query()->where('company_id', $tenant->getKey())->min('issued_at');
        $years = range((int) now()->year, $lo ? min((int) Carbon::parse($lo)->year, (int) now()->year) : (int) now()->year);

        return array_combine($years, $years);
    }

    /** @return array<int, string> */
    public function seriesOptions(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return InvoiceType::query()
            ->where('company_id', $tenant->getKey())
            ->where('is_informal', true)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name])
            ->all();
    }

    public function getResult(): array
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->result ??= app(InformalValue::class)->build($tenant, $this->year, $this->series !== '' ? (int) $this->series : null);
    }

    public function fmt(float $v): string
    {
        return Money::eur($v);
    }

    public function fmtQty(float $qty): string
    {
        $s = number_format($qty, 3, ',', '.');

        return str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;
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
        $safe = function (string $v): string {
            $t = ltrim($v, " \t\r\n");

            return ($t !== '' && in_array($t[0], ['=', '+', '-', '@'], true)) ? "'".$v : $v;
        };
        $num = fn (float $v): string => number_format($v, 2, ',', '');

        return response()->streamDownload(function () use ($result, $safe, $num): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Αξία άτυπων', (string) $result['year']], ';', escape: '');
            fputcsv($h, [], ';', escape: '');
            fputcsv($h, ['Ημερομηνία', 'Παραστατικό', 'Σειρά', 'Πελάτης', 'Καθαρή αξία', 'ΦΠΑ', 'Μεικτό', 'Μετατράπηκε σε'], ';', escape: '');
            foreach ($result['documents'] as $d) {
                fputcsv($h, [
                    $d['date'], $safe($d['invcode']), $safe($d['series']), $safe($d['customer']),
                    $num($d['net']), $num($d['vat']), $num($d['gross']), $safe((string) ($d['converted_to'] ?? '')),
                ], ';', escape: '');
            }
            fputcsv($h, ['ΣΥΝΟΛΟ ΧΩΡΙΣ ΧΡΕΩΣΗ', '', '', '', $num($result['total_net']), $num($result['total_vat']), $num($result['total_gross']), ''], ';', escape: '');
            fclose($h);
        }, 'axia-atypon-'.$result['year'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
