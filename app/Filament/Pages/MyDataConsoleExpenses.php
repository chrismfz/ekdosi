<?php

namespace App\Filament\Pages;

use App\Enums\MyDataMode;
use App\Filament\Pages\Concerns\RemembersLastFetch;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\MyData\ExpenseImporter;
use App\Services\MyData\ExpenseReconciler;
use App\Services\MyData\ExpenseReconciliationResult;
use App\Services\MyData\ReconciliationRow;
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
 * Κονσόλα myDATA — Έξοδα (E4). The expense-side twin of MyDataConsole.
 *
 * Calls AADE (RequestDocs via App\Services\MyData\ExpenseReconciler) and
 * cross-checks the documents OTHERS filed against us (εισροές) vs our local
 * `expenses` for a window. Two directions:
 *   - 'compare' : OUR recorded expenses → myDATA (in sync?).
 *   - 'inbound' : myDATA → US, the actionable "αδέσποτα έξοδα" — supplier docs
 *                 with no local expense, importable in one click (ExpenseImporter).
 *
 * Read-only fetch; the import action is the only write, operator-gated and
 * idempotent. Hidden for non-gr-mydata / Off-mode tenants.
 */
class MyDataConsoleExpenses extends Page
{
    use RemembersLastFetch;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.pages.my-data-console-expenses';

    public ?string $fromLabel = null;

    public ?string $toLabel = null;

    /** Serialized ExpenseReconciliationResult for the blade (Livewire-safe). */
    public ?array $result = null;

    public ?string $resultMode = null;

    public bool $ran = false;

    public ?string $error = null;

    /**
     * Test seam: a Guzzle MockHandler passed through to the reconciler /
     * importer (which already accept one). STATIC (not a public Livewire
     * property — Livewire can't serialize a handler object). Null in
     * production → real AADE. Lets the import+refresh round-trip be exercised
     * without the network.
     */
    public static ?\GuzzleHttp\Handler\MockHandler $testHandler = null;

    public function mount(): void
    {
        $this->restoreFetch();
    }

    protected function cachedFetchProps(): array
    {
        return ['result', 'resultMode', 'fromLabel', 'toLabel', 'ran'];
    }

    public static function getNavigationLabel(): string
    {
        return 'Κονσόλα myDATA — Έξοδα';
    }

    public function getTitle(): string
    {
        return 'Κονσόλα myDATA — Έξοδα';
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
            // Direction 1 — OUR recorded expenses → myDATA.
            Action::make('reconcile')
                ->label('Έλεγχος δικών μας εξόδων στο myDATA')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->modalHeading('Έλεγχος δικών μας εξόδων στο myDATA')
                ->modalDescription('Παίρνει τα έξοδα που μας υπέβαλαν προμηθευτές και επιβεβαιώνει ότι όσα έχουμε καταχωρίσει τοπικά συμφωνούν με το myDATA.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema($this->windowSchema())
                ->action(fn (array $data) => $this->runReconciliation($data['from'], $data['to'], 'compare')),

            // Direction 2 — myDATA → US ("αδέσποτα έξοδα").
            Action::make('find_orphans')
                ->label('Αδέσποτα έξοδα από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('warning')
                ->modalHeading('Αδέσποτα έξοδα από myDATA')
                ->modalDescription('Κατεβάζει ό,τι έχει υποβάλει προμηθευτής σε βάρος μας και εντοπίζει έξοδα που υπάρχουν στο myDATA αλλά ΟΧΙ στο ekdosi.')
                ->modalSubmitActionLabel('Λήψη')
                ->schema($this->windowSchema())
                ->action(fn (array $data) => $this->runReconciliation($data['from'], $data['to'], 'inbound')),

            // Import: record every αδέσποτο of the queried window locally.
            // Visible only after an inbound fetch that found orphans.
            Action::make('import_orphans')
                ->label('Καταχώριση αδέσποτων εξόδων')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('success')
                ->visible(fn (): bool => $this->ran
                    && $this->resultMode === 'inbound'
                    && ! empty($this->result['missingLocally']))
                ->requiresConfirmation()
                ->modalHeading('Καταχώριση αδέσποτων εξόδων')
                ->modalDescription(fn (): string => 'Θα καταχωριστούν τοπικά τα αδέσποτα έξοδα του διαστήματος '
                    .$this->fromLabel.' – '.$this->toLabel
                    .' (δημιουργία εξόδων + γραμμών + προμηθευτών). Ήδη καταχωρημένα παραλείπονται.')
                ->modalSubmitActionLabel('Καταχώριση')
                ->action(fn () => $this->importOrphans()),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    private function windowSchema(): array
    {
        return [
            DatePicker::make('from')
                ->label('Από')
                ->required()
                ->default(now()->subMonth()->startOfMonth()),
            DatePicker::make('to')
                ->label('Έως')
                ->required()
                ->default(now()),
        ];
    }

    protected function runReconciliation(string $from, string $to, string $mode = 'compare'): void
    {
        $tenant = Filament::getTenant();

        $this->ran = true;
        $this->error = null;
        $this->result = null;
        $this->resultMode = $mode;

        try {
            $result = (new ExpenseReconciler($tenant, static::$testHandler))->reconcile(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->result = $this->serialize($result);
            $this->fromLabel = $result->from;
            $this->toLabel = $result->to;
            $this->rememberFetch();

            if ($mode === 'inbound') {
                $orphans = count($result->missingLocally);
                Notification::make()
                    ->title('Η λήψη από myDATA ολοκληρώθηκε')
                    ->body($orphans > 0
                        ? $orphans.' αδέσποτα έξοδα (στο myDATA, όχι στο ekdosi)'
                        : 'Δεν βρέθηκαν αδέσποτα έξοδα — όλα όσα έχει το myDATA είναι καταχωρημένα.')
                    ->{$orphans > 0 ? 'warning' : 'success'}()
                    ->send();
            } else {
                Notification::make()
                    ->title('Ο έλεγχος ολοκληρώθηκε')
                    ->body($result->hasDiscrepancies()
                        ? $result->discrepancyCount().' ασυμφωνίες βρέθηκαν'
                        : 'Όλα συμφωνούν με το AADE')
                    ->{$result->hasDiscrepancies() ? 'warning' : 'success'}()
                    ->send();
            }
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
            Notification::make()->title('Ο έλεγχος απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('myDATA expenses reconciliation failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            $this->error = 'Η σύνδεση με το AADE απέτυχε. Ελέγξτε τα διαπιστευτήρια και προσπαθήστε ξανά.';
            Notification::make()->title('Ο έλεγχος απέτυχε')->body($this->error)->danger()->send();
        }
    }

    protected function importOrphans(): void
    {
        $tenant = Filament::getTenant();

        if (! $this->fromLabel || ! $this->toLabel) {
            return;
        }

        // fromLabel/toLabel are the reconciler's d/m/Y output. Parse them with
        // an EXPLICIT format — Carbon::parse() reads '/' as m/d/Y and would
        // throw (day > 12) or silently swap day/month.
        $from = Carbon::createFromFormat('d/m/Y', $this->fromLabel)->startOfDay();
        $to = Carbon::createFromFormat('d/m/Y', $this->toLabel)->endOfDay();

        try {
            $result = (new ExpenseImporter($tenant, static::$testHandler))->import($from, $to);

            Notification::make()
                ->title("Καταχωρήθηκαν {$result->created} έξοδα")
                ->body($result->summary())
                ->{$result->created > 0 ? 'success' : 'warning'}()
                ->send();

            // Refresh the worklist so imported docs leave the αδέσποτα list.
            // Pass Y-m-d so runReconciliation's Carbon::parse is unambiguous.
            $this->runReconciliation($from->format('Y-m-d'), $to->format('Y-m-d'), 'inbound');
        } catch (RuntimeException $e) {
            Notification::make()->title('Η καταχώριση απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('myDATA expense import failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Η καταχώριση απέτυχε')->body('Σφάλμα κατά τη λήψη/καταχώριση από το AADE.')->danger()->send();
        }
    }

    private function serialize(ExpenseReconciliationResult $r): array
    {
        // GR issuers' names are forbidden in myDATA ([219]/[220]) — only the
        // AFM arrives. Resolve those AFMs against our synced suppliers so the
        // αδέσποτα worklist shows a name, not a blank. Batch-load once.
        $names = $this->supplierNamesByAfm($r);

        $rows = fn (array $rows) => array_map(fn (ReconciliationRow $row) => $this->rowToArray($row, $names), $rows);

        return [
            'from' => $r->from,
            'to' => $r->to,
            'aadeTotal' => $r->aadeTotal,
            'localTotal' => $r->localTotal,
            'discrepancyCount' => $r->discrepancyCount(),
            'matched' => $rows($r->matched),
            'stateMismatch' => $rows($r->stateMismatch),
            'missingAtAade' => $rows($r->missingAtAade),
            'missingLocally' => $rows($r->missingLocally),
            'duplicateLocal' => $rows($r->duplicateLocal),
        ];
    }

    /**
     * @param  array<string, string>  $names  afm => supplier name
     */
    private function rowToArray(ReconciliationRow $row, array $names = []): array
    {
        $afm = $row->counterpartVat;
        // Name from the doc → else our synced supplier (by AFM) → else null.
        $name = $row->counterpartName ?: ($afm !== null ? ($names[$afm] ?? null) : null);

        return [
            'mark' => $row->mark,
            'uid' => $row->uid,
            'expenseId' => $row->expenseId,
            'invcode' => $row->invcode,
            'issuedAt' => $row->issuedAt,
            'counterpartName' => $name,
            'afm' => $afm,
            'gross' => $row->gross,
            'localState' => $row->localState,
            'localStatus' => $row->localStatus,
            'aadeState' => $row->aadeState,
            'cancelledByMark' => $row->cancelledByMark,
            'problem' => $row->problem,
            'url' => $row->expenseId ? $this->expenseUrl($row->expenseId) : null,
        ];
    }

    /**
     * Batch-load supplier names for every AFM the result references, scoped to
     * the tenant (Supplier has no global scope). One query, no N+1.
     *
     * @return array<string, string>  afm => name
     */
    private function supplierNamesByAfm(ExpenseReconciliationResult $r): array
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            return [];
        }

        $afms = collect([
            ...$r->matched, ...$r->stateMismatch, ...$r->missingAtAade,
            ...$r->missingLocally, ...$r->duplicateLocal,
        ])->map(fn (ReconciliationRow $row) => $row->counterpartVat)
            ->filter()
            ->unique()
            ->values();

        if ($afms->isEmpty()) {
            return [];
        }

        return \App\Models\Supplier::query()
            ->where('company_id', $tenant->getKey())
            ->whereIn('afm', $afms->all())
            ->whereNotNull('name')
            ->pluck('name', 'afm')
            ->all();
    }

    private function expenseUrl(int $expenseId): ?string
    {
        return ExpenseResource::getUrl('view', [
            'record' => $expenseId,
            'tenant' => Filament::getTenant(),
        ]);
    }
}
