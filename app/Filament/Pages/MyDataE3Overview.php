<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RemembersLastFetch;
use App\Models\Company;
use App\Services\MyData\E3Report;
use App\Services\MyData\E3Reporter;
use App\Support\Money;
use App\Support\MyData\Codes;
use App\Support\MyData\VatPictureCache;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use UnitEnum;

/**
 * Επισκόπηση Ε3 (E7) — read-only. Pulls AADE's `RequestE3Info` for a window
 * (the Ε3 classification figures AADE aggregated for our ΑΦΜ) and shows them
 * rolled up per classification type/category. No local write, no diff —
 * a quick "what does my Ε3 look like at AADE" view.
 *
 * Test seam: the static $testHandler (a public Livewire prop can't hold a
 * MockHandler) lets the fetch be exercised without the network.
 *
 * gr-mydata / non-Off tenants only.
 */
class MyDataE3Overview extends Page
{
    use RemembersLastFetch;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 93;

    protected string $view = 'filament.pages.my-data-e3-overview';

    public ?array $result = null;

    public bool $ran = false;

    public ?string $error = null;

    public static ?MockHandler $testHandler = null;

    /** Authoritative ΦΠΑ τριμήνου box, read from the same cache the dashboard uses. */
    public ?array $vatQuarter = null;

    public function mount(): void
    {
        $this->restoreFetch();
        $this->loadVatQuarter();
    }

    protected function cachedFetchProps(): array
    {
        return ['result', 'ran'];
    }

    /**
     * Pull the current-quarter VAT picture from VatPictureCache — the same
     * authoritative snapshot (RequestVatInfo, refreshed by the scheduler) the
     * dashboard widget reads. Cache-only: no live AADE call on this page.
     */
    private function loadVatQuarter(): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            return;
        }

        $picture = VatPictureCache::get($tenant, 'quarter');
        if ($picture === null) {
            return;
        }

        $this->vatQuarter = [
            'outputVat' => $picture->outputVat,
            'inputVat' => $picture->inputVat,
            'netVat' => $picture->netVat(),
            'payable' => $picture->isPayable(),
            'fetchedAt' => $picture->fetchedAt
                ? Carbon::parse($picture->fetchedAt)->diffForHumans()
                : null,
        ];
    }

    public static function getNavigationLabel(): string
    {
        return 'Επισκόπηση Ε3';
    }

    public function getTitle(): string
    {
        return 'Επισκόπηση Ε3 (myDATA)';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Admin-only Ε3 overview — gated on View:MyDataE3Overview (company_admin +
     * super_admin; operators excluded). Gate::can is 404-storm-safe; the tenant
     * must be a live myDATA tenant.
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->isLiveMyDataTenant()
            && (bool) auth()->user()?->can('View:MyDataE3Overview');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fetch')
                ->label('Λήψη Ε3 από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('primary')
                ->modalHeading('Επισκόπηση Ε3 από myDATA')
                ->modalDescription('Κατεβάζει τα αθροιστικά στοιχεία Ε3 (ανά τύπο/κατηγορία χαρακτηρισμού) που τηρεί το myDATA για το ΑΦΜ μας στο διάστημα.')
                ->modalSubmitActionLabel('Λήψη')
                ->schema([
                    DatePicker::make('from')
                        ->label('Από')
                        ->required()
                        ->default(now()->startOfQuarter()),
                    DatePicker::make('to')
                        ->label('Έως')
                        ->required()
                        ->default(now()),
                ])
                ->action(fn (array $data) => $this->runReport($data['from'], $data['to'])),
        ];
    }

    protected function runReport(string $from, string $to): void
    {
        $tenant = Filament::getTenant();

        $this->ran = true;
        $this->error = null;
        $this->result = null;

        try {
            $report = (new E3Reporter($tenant, static::$testHandler))->report(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->result = $this->serialize($report);
            $this->rememberFetch();

            Notification::make()
                ->title('Η λήψη Ε3 ολοκληρώθηκε')
                ->body($report->isEmpty()
                    ? 'Δεν επιστράφηκαν στοιχεία Ε3 για το διάστημα.'
                    : count($report->rows).' γραμμές, σύνολο '.Money::eur($report->total))
                ->{$report->isEmpty() ? 'warning' : 'success'}()
                ->send();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            Notification::make()->title('Η λήψη απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('myDATA E3 overview failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->error = 'Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.';
            Notification::make()->title('Η λήψη απέτυχε')->body($this->error)->danger()->send();
        }
    }

    private function serialize(E3Report $report): array
    {
        $rows = array_map(fn ($r) => [
            'classType' => $r->classType,
            'classCategory' => $r->classCategory,
            'typeLabel' => Codes::e3TypeLabel($r->classType),
            'categoryLabel' => $r->classCategory ? Codes::e3CategoryLabel($r->classCategory) : null,
            // Split the table into income (E3_56x) vs expense (E3_58x) — summing
            // both into one total is meaningless; each side gets its own subtotal.
            'direction' => Codes::e3Direction($r->classType),
            'value' => $r->value,
            'count' => $r->count,
        ], $report->rows);

        $sumWhere = fn (string $dir) => round(array_sum(
            array_map(fn ($r) => $r['direction'] === $dir ? $r['value'] : 0.0, $rows)
        ), 2);

        return [
            'from' => $report->from,
            'to' => $report->to,
            'docCount' => $report->docCount,
            'total' => $report->total,
            'incomeTotal' => $sumWhere('income'),
            'expenseTotal' => $sumWhere('expense'),
            'unknownTotal' => $sumWhere('unknown'),
            'rows' => $rows,
        ];
    }
}
