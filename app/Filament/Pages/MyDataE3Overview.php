<?php

namespace App\Filament\Pages;

use App\Enums\MyDataMode;
use App\Services\MyData\E3Report;
use App\Services\MyData\E3Reporter;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 93;

    protected string $view = 'filament.pages.my-data-e3-overview';

    public ?array $result = null;

    public bool $ran = false;

    public ?string $error = null;

    public static ?\GuzzleHttp\Handler\MockHandler $testHandler = null;

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

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return auth()->check()
            && $tenant
            && $tenant->einvoice_provider === 'gr-mydata'
            && $tenant->mydata_mode_enum !== MyDataMode::Off;
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

            Notification::make()
                ->title('Η λήψη Ε3 ολοκληρώθηκε')
                ->body($report->isEmpty()
                    ? 'Δεν επιστράφηκαν στοιχεία Ε3 για το διάστημα.'
                    : count($report->rows).' γραμμές, σύνολο '.\App\Support\Money::eur($report->total))
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
        return [
            'from' => $report->from,
            'to' => $report->to,
            'docCount' => $report->docCount,
            'total' => $report->total,
            'rows' => array_map(fn ($r) => [
                'classType' => $r->classType,
                'classCategory' => $r->classCategory,
                'value' => $r->value,
                'count' => $r->count,
            ], $report->rows),
        ];
    }
}
