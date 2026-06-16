<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\LedgerBook as LedgerBookService;
use App\Services\Accounting\LedgerBookExporter;
use App\Services\Accounting\LedgerBookResult;
use App\Support\MyData\Codes;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Firebed\AadeMyData\Enums\ExpenseClassificationCategory;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * «Βιβλίο Εσόδων-Εξόδων» — a read-only, chronological book of the tenant's
 * documents (invoices = έσοδα, expenses = έξοδα), classified by the myDATA
 * category we already store and totalled the way an απλογραφικό βιβλίο (Β'
 * κατηγορίας) reads. Built on top of LedgerBook (a pure read-model over the
 * existing tables — no new persistence, no effect on the money/myDATA path).
 *
 * Filters (period / book / category) are plain Livewire props bound live in the
 * Blade; the view pulls a fresh LedgerBookResult on every render. Admin-gated
 * on View:LedgerBook (run shield:generate after deploy), like Reports.
 */
class LedgerBook extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.ledger-book';

    /** Quick-period preset (this_month / last_month / quarter / prev_quarter / year / prev_year / custom). */
    public string $period = 'this_month';

    public ?string $from = null;

    public ?string $to = null;

    public string $book = 'all';

    public ?string $category = null;

    public static function getNavigationLabel(): string
    {
        return 'Βιβλίο Εσόδων-Εξόδων';
    }

    public function getTitle(): string
    {
        return 'Βιβλίο Εσόδων-Εξόδων';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:LedgerBook');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->applyPeriod();
    }

    /** Recompute the date window when the preset changes (Livewire hook). */
    public function updatedPeriod(): void
    {
        $this->applyPeriod();
    }

    /** A manual date edit means the operator wants a custom window. */
    public function updatedFrom(): void
    {
        $this->period = 'custom';
    }

    public function updatedTo(): void
    {
        $this->period = 'custom';
    }

    /**
     * Resolve the preset into a concrete from/to (calendar = φορολογικά boundaries).
     * 'custom' keeps whatever the operator typed (defaulting to the current month).
     * No-overflow month math so «προηγούμενος μήνας» from a 31st is correct.
     */
    private function applyPeriod(): void
    {
        $now = now();

        if ($this->period === 'custom') {
            $this->from ??= $now->copy()->startOfMonth()->toDateString();
            $this->to ??= $now->copy()->endOfMonth()->toDateString();

            return;
        }

        [$from, $to] = match ($this->period) {
            'last_month' => [
                $now->copy()->subMonthsNoOverflow(1)->startOfMonth(),
                $now->copy()->subMonthsNoOverflow(1)->endOfMonth(),
            ],
            'quarter' => [$now->copy()->startOfQuarter(), $now->copy()->endOfQuarter()],
            'prev_quarter' => [
                $now->copy()->subMonthsNoOverflow(3)->startOfQuarter(),
                $now->copy()->subMonthsNoOverflow(3)->endOfQuarter(),
            ],
            'year' => [$now->copy()->startOfYear(), $now->copy()->endOfYear()],
            'prev_year' => [
                $now->copy()->subYear()->startOfYear(),
                $now->copy()->subYear()->endOfYear(),
            ],
            default => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()], // this_month
        };

        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    /**
     * @return array<Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('export_csv')
                    ->label('CSV')
                    ->icon('heroicon-o-table-cells')
                    ->action(fn () => $this->export('csv')),
                Action::make('export_xlsx')
                    ->label('Excel (.xlsx)')
                    ->icon('heroicon-o-document-chart-bar')
                    ->action(fn () => $this->export('xlsx')),
                Action::make('export_json')
                    ->label('JSON')
                    ->icon('heroicon-o-code-bracket')
                    ->action(fn () => $this->export('json')),
            ])
                ->label('Εξαγωγή')
                ->icon('heroicon-o-arrow-down-tray')
                ->button(),
        ];
    }

    public function export(string $format): StreamedResponse
    {
        $result = $this->getResult();
        $exporter = app(LedgerBookExporter::class);
        $name = $exporter->filename($format, $this->from, $this->to);

        return match ($format) {
            'json' => response()->streamDownload(
                fn () => print ($exporter->json($result)),
                $name,
                ['Content-Type' => 'application/json; charset=UTF-8'],
            ),
            'xlsx' => response()->streamDownload(
                function () use ($exporter, $result): void {
                    $path = $exporter->xlsxFile($result);
                    try {
                        readfile($path);
                    } finally {
                        @unlink($path);
                    }
                },
                $name,
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ),
            default => response()->streamDownload(
                fn () => print ($exporter->csv($result)),
                $name,
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            ),
        };
    }

    public function getResult(): LedgerBookResult
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $start = Carbon::parse($this->from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($this->to ?: now()->endOfMonth()->toDateString())->endOfDay();

        return (new LedgerBookService($tenant))->forPeriod(
            $start,
            $end,
            in_array($this->book, ['income', 'expense'], true) ? $this->book : 'all',
            $this->category ?: null,
        );
    }

    /**
     * value => "code — Greek label" for the category filter: income
     * (category1_x) + expense (category2_x), both resolved to Greek.
     *
     * @return array<string, string>
     */
    public function getCategoryOptions(): array
    {
        $out = [];

        foreach (Codes::INCOME_CLASS_CATEGORIES as $code) {
            $label = Codes::e3CategoryLabel($code);
            $out[$code] = $code.($label ? ' — '.$label : '');
        }

        foreach (ExpenseClassificationCategory::cases() as $case) {
            $out[$case->value] = $case->value.' — '.$case->label();
        }

        return $out;
    }
}
