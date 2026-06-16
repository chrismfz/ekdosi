<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\AgedReceivablesReport;
use App\Services\Accounting\AgedReceivablesResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Ηλικίωση οφειλών» — aged-receivables report. Read-only: per-customer
 * outstanding balance bucketed 0-30 / 31-60 / 61-90 / 90+ days, biggest debtors
 * first, with footed column totals and a drill to each customer's Καρτέλα.
 *
 * Built on AgedReceivablesReport (which reuses the Καρτέλα FIFO ageing), so the
 * numbers agree with the per-customer view. Admin-gated on View:AgedReceivables.
 */
class AgedReceivables extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 96;

    protected string $view = 'filament.pages.aged-receivables';

    private ?AgedReceivablesResult $result = null;

    public static function getNavigationLabel(): string
    {
        return 'Ηλικίωση οφειλών';
    }

    public function getTitle(): string
    {
        return 'Ηλικίωση οφειλών';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:AgedReceivables');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Memoised per request (no filters; one build per page load / action). */
    public function getResult(): AgedReceivablesResult
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $this->result ??= app(AgedReceivablesReport::class)->build($tenant);
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
        $name = 'ilikiosi-ofeilon-'.now()->format('Y-m-d').'.csv';
        $num = fn ($v): string => number_format((float) $v, 2, ',', '');

        return response()->streamDownload(function () use ($result, $num): void {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($h, ['Πελάτης', 'ΑΦΜ', '0-30', '31-60', '61-90', '90+', 'Σύνολο', 'Παλαιότερο (ημέρες)'], ';');
            foreach ($result->rows as $row) {
                fputcsv($h, [
                    $row->customerName, $row->afm ?? '',
                    $num($row->b0_30), $num($row->b31_60), $num($row->b61_90), $num($row->b90plus),
                    $num($row->total), $row->oldestDays ?? '',
                ], ';');
            }
            fputcsv($h, [], ';');
            fputcsv($h, [
                'Σύνολα', '',
                $num($result->total0_30()), $num($result->total31_60()), $num($result->total61_90()),
                $num($result->total90plus()), $num($result->grandTotal()), '',
            ], ';');
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
