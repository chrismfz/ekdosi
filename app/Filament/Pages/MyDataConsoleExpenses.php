<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\MyDataCluster;
use App\Filament\Pages\Concerns\RefreshesAllMyData;
use App\Filament\Pages\Concerns\RemembersLastFetch;
use App\Filament\Pages\Concerns\ResolvesReconcileWindow;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Company;
use App\Models\Expense;
use App\Models\Supplier;
use App\Services\MyData\ExpenseImporter;
use App\Services\MyData\ExpenseReconciler;
use App\Services\MyData\ExpenseReconciliationResult;
use App\Services\MyData\ReconciliationRow;
use App\Services\MyData\SyncExpenseStateFromAade;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

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
    use RefreshesAllMyData;
    use RemembersLastFetch;
    use ResolvesReconcileWindow;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-arrow-down';

    protected static ?string $cluster = MyDataCluster::class;

    protected static ?string $slug = 'expenses';

    protected static ?int $navigationSort = 2;

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
    public static ?MockHandler $testHandler = null;

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
        return 'Έξοδα';
    }

    public function getTitle(): string
    {
        return 'Κονσόλα myDATA — Έξοδα';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * Admin-only like the sales console — gated on View:MyDataConsoleExpenses
     * (company_admin + super_admin; operators excluded). Gate::can is
     * 404-storm-safe; the tenant must be able to READ from myDATA (direct
     * gr-mydata OR a gr-provider tenant with its own read credentials).
     */
    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->canReadMyData()
            && (bool) auth()->user()?->can('View:MyDataConsoleExpenses');
    }

    protected function getHeaderActions(): array
    {
        return [
            // Primary: one click refreshes ALL console tabs + the ΦΠΑ box.
            $this->refreshAllAction(),

            // Secondary: just THIS tab. ONE fetch (RequestDocs), BOTH directions
            // together: «τα δικά μας έξοδα» (συμφωνούν;) + «αδέσποτα έξοδα» (μας
            // υπέβαλε προμηθευτής αλλά δεν τα έχουμε).
            Action::make('reconcile')
                ->label('Μόνο έξοδα')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('gray')
                ->modalHeading('Έλεγχος myDATA — Έξοδα')
                ->modalDescription('Κατεβάζει ό,τι μας υπέβαλαν προμηθευτές στο διάστημα και δείχνει μαζί: αν τα δικά μας έξοδα συμφωνούν, ΚΑΙ τυχόν «αδέσποτα έξοδα» (στο myDATA αλλά όχι στο ekdosi). Δεν τροποποιεί τίποτα.')
                ->modalSubmitActionLabel('Έλεγχος')
                ->schema($this->windowSchema())
                ->action(function (array $data): void {
                    [$from, $to] = $this->resolveWindow($data);
                    $this->runReconciliation($from, $to);
                }),

            // OUR OWN non-income docs (RequestTransmittedDocs): αποδείξεις (13.x),
            // ενδοκοινοτικά/VIES/ΕΦΚΑ (14.x), μισθοδοσία/πάγια/τακτοποιήσεις (17.x).
            // Different endpoint + a WRITE → stays its own action. Idempotent.
            Action::make('import_self_declared')
                ->label('Λήψη δικών μας εξόδων')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('gray')
                ->modalHeading('Λήψη δικών μας εξόδων από myDATA')
                ->modalDescription('Κατεβάζει ΟΣΑ έχουμε δηλώσει εμείς ΚΑΙ δεν είναι έσοδα — αποδείξεις, ενδοκοινοτικά/VIES, ΕΦΚΑ, μισθοδοσία, πάγια, τακτοποιήσεις — και τα καταχωρίζει στα Έξοδα (με κατηγορία). Οι πωλήσεις αγνοούνται. Ήδη καταχωρημένα παραλείπονται.')
                ->modalSubmitActionLabel('Λήψη')
                ->schema($this->windowSchema())
                ->action(function (array $data): void {
                    [$from, $to] = $this->resolveWindow($data);
                    $this->importSelfDeclared($from, $to);
                }),

            // Import every αδέσποτο of the queried window locally. Visible only
            // after a fetch that found orphans.
            Action::make('import_orphans')
                ->label('Καταχώριση αδέσποτων εξόδων')
                ->icon('heroicon-o-arrow-down-on-square-stack')
                ->color('success')
                ->visible(fn (): bool => $this->ran && ! empty($this->result['missingLocally']))
                ->requiresConfirmation()
                ->modalHeading('Καταχώριση αδέσποτων εξόδων')
                ->modalDescription(fn (): string => 'Θα καταχωριστούν τοπικά τα αδέσποτα έξοδα του διαστήματος '
                    .$this->fromLabel.' – '.$this->toLabel
                    .' (δημιουργία εξόδων + γραμμών + προμηθευτών). Ήδη καταχωρημένα παραλείπονται.')
                ->modalSubmitActionLabel('Καταχώριση')
                ->action(fn () => $this->importOrphans()),

            // Apply AADE's state to our EXISTING expenses that diverge (chiefly a
            // supplier cancellation we can't re-import). Visible only when the last
            // fetch found state mismatches. Audited via ExpenseMark (MYD-014).
            Action::make('sync_states')
                ->label('Συγχρονισμός κατάστασης από ΑΑΔΕ')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->ran && ! empty($this->result['stateMismatch']))
                ->requiresConfirmation()
                ->modalHeading('Συγχρονισμός κατάστασης εξόδων από ΑΑΔΕ')
                ->modalDescription('Τα τοπικά έξοδα με ασυμφωνία κατάστασης θα πάρουν την κατάσταση που δηλώνει η ΑΑΔΕ (π.χ. ακύρωση από τον προμηθευτή). Δεν δημιουργεί διπλότυπα· καταγράφεται στο ιστορικό.')
                ->modalSubmitActionLabel('Συγχρονισμός')
                ->action(fn () => $this->syncStates()),
        ];
    }

    protected function runReconciliation(string $from, string $to): void
    {
        $tenant = Filament::getTenant();

        // NB: do NOT wipe $this->result here — keep the last cached result on a
        // failed fetch (e.g. AADE 429) instead of blanking the page; it's
        // overwritten only on success.
        $this->error = null;

        try {
            $result = (new ExpenseReconciler($tenant, static::$testHandler))->reconcile(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            $this->ran = true;
            $this->resultMode = 'both';
            $this->result = self::serializeFor($tenant, $result);
            $this->fromLabel = $result->from;
            $this->toLabel = $result->to;
            $this->rememberFetch();

            // One combined toast: our-expense discrepancies + αδέσποτα έξοδα.
            $discrepancies = $result->discrepancyCount();
            $orphans = count($result->missingLocally);

            $parts = [];
            $parts[] = $discrepancies > 0 ? "{$discrepancies} ασυμφωνίες" : 'καμία ασυμφωνία';
            $parts[] = $orphans > 0 ? "{$orphans} αδέσποτα έξοδα" : 'κανένα αδέσποτο έξοδο';

            Notification::make()
                ->title('Ο έλεγχος ολοκληρώθηκε')
                ->body(ucfirst(implode(' · ', $parts)).'.')
                ->{($discrepancies > 0 || $orphans > 0) ? 'warning' : 'success'}()
                ->send();
        } catch (RateLimitExceededException $e) {
            $this->error = $this->rateLimitMessage($e->getMessage());
            Notification::make()->title('Προσωρινό όριο myDATA')->body($this->error)->warning()->send();
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

    protected function importSelfDeclared(string $from, string $to): void
    {
        $tenant = Filament::getTenant();

        try {
            $result = (new ExpenseImporter($tenant, static::$testHandler))->importSelfDeclared(
                Carbon::parse($from)->startOfDay(),
                Carbon::parse($to)->endOfDay(),
            );

            Notification::make()
                ->title("Καταχωρήθηκαν {$result->created} δικά μας έξοδα")
                ->body($result->summary())
                ->{$result->created > 0 ? 'success' : 'warning'}()
                ->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Η λήψη απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('myDATA self-declared expense import failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Η λήψη απέτυχε')->body('Σφάλμα κατά τη λήψη/καταχώριση από το AADE.')->danger()->send();
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
            $this->runReconciliation($from->format('Y-m-d'), $to->format('Y-m-d'));
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

    protected function syncStates(): void
    {
        $tenant = Filament::getTenant();

        if (! $this->ran || empty($this->result['stateMismatch'])) {
            return;
        }

        $sync = app(SyncExpenseStateFromAade::class);
        $changed = 0;

        try {
            foreach ($this->result['stateMismatch'] as $row) {
                $expenseId = $row['expenseId'] ?? null;
                $aadeState = $row['aadeState'] ?? null;
                if ($expenseId === null || $aadeState === null) {
                    continue;
                }

                // Tenant-scoped load (the console runs inside the panel tenant, but
                // scope explicitly — CLASS invariant, not the ambient no-op).
                $expense = Expense::query()
                    ->where('company_id', $tenant?->getKey())
                    ->find($expenseId);
                if ($expense === null) {
                    continue;
                }

                if ($sync->sync($expense, $aadeState, $row['cancelledByMark'] ?? null)['changed']) {
                    $changed++;
                }
            }

            Notification::make()
                ->title($changed > 0 ? "Συγχρονίστηκαν {$changed} έξοδα" : 'Καμία αλλαγή')
                ->{$changed > 0 ? 'success' : 'warning'}()
                ->send();

            // Re-fetch so the synced rows move from mismatch → matched.
            if ($this->fromLabel && $this->toLabel) {
                $from = Carbon::createFromFormat('d/m/Y', $this->fromLabel)->startOfDay();
                $to = Carbon::createFromFormat('d/m/Y', $this->toLabel)->endOfDay();
                $this->runReconciliation($from->format('Y-m-d'), $to->format('Y-m-d'));
            }
        } catch (Throwable $e) {
            Log::warning('myDATA expense state-sync failed', [
                'company_id' => $tenant?->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Ο συγχρονισμός απέτυχε')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * Run the reconcile for a tenant and write the snapshot into the SAME cache
     * this page restores on mount — so the read-only cron (`mydata:refresh-expenses`)
     * and the «Άντληση από myDATA» button on the Έξοδα list seed exactly what the
     * console shows. Static + tenant-explicit (no Filament tenant context needed).
     */
    public static function refreshSnapshot(Company $tenant, Carbon $from, Carbon $to, ?MockHandler $handler = null): ExpenseReconciliationResult
    {
        // Fall back to the static test seam so the CLI refresh + the Έξοδα-list
        // button (neither passes a handler) are exercisable without the network.
        $result = (new ExpenseReconciler($tenant, $handler ?? static::$testHandler))->reconcile(
            $from->copy()->startOfDay(),
            $to->copy()->endOfDay(),
        );

        static::putFetchState($tenant->getKey(), [
            'result' => self::serializeFor($tenant, $result),
            'resultMode' => 'both',
            'fromLabel' => $result->from,
            'toLabel' => $result->to,
            'ran' => true,
        ]);

        return $result;
    }

    /** Count of αδέσποτα (importable orphan expenses) in the last cached fetch. */
    public static function lastOrphanCount(int|string|null $tenantKey): int
    {
        return count(static::lastFetchState($tenantKey)['result']['missingLocally'] ?? []);
    }

    public static function serializeFor(Company $tenant, ExpenseReconciliationResult $r): array
    {
        // GR issuers' names are forbidden in myDATA ([219]/[220]) — only the
        // AFM arrives. Resolve those AFMs against our synced suppliers so the
        // αδέσποτα worklist shows a name, not a blank. Batch-load once.
        $names = self::supplierNamesByAfm($tenant, $r);

        $rows = fn (array $rows) => array_map(fn (ReconciliationRow $row) => self::rowToArray($tenant, $row, $names), $rows);

        return [
            'from' => $r->from,
            'to' => $r->to,
            'aadeTotal' => $r->aadeTotal,
            'localTotal' => $r->localTotal,
            'discrepancyCount' => $r->discrepancyCount(),
            'matched' => $rows($r->matched),
            'stateMismatch' => $rows($r->stateMismatch),
            'contentMismatch' => $rows($r->contentMismatch),
            'missingAtAade' => $rows($r->missingAtAade),
            'missingLocally' => $rows($r->missingLocally),
            'duplicateLocal' => $rows($r->duplicateLocal),
        ];
    }

    /**
     * @param  array<string, string>  $names  afm => supplier name
     */
    private static function rowToArray(Company $tenant, ReconciliationRow $row, array $names = []): array
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
            'url' => $row->expenseId ? self::expenseUrl($tenant, $row->expenseId) : null,
        ];
    }

    /**
     * Batch-load supplier names for every AFM the result references, scoped to
     * the tenant (Supplier has no global scope). One query, no N+1.
     *
     * @return array<string, string> afm => name
     */
    private static function supplierNamesByAfm(Company $tenant, ExpenseReconciliationResult $r): array
    {
        $afms = collect([
            ...$r->matched, ...$r->stateMismatch, ...$r->contentMismatch, ...$r->missingAtAade,
            ...$r->missingLocally, ...$r->duplicateLocal,
        ])->map(fn (ReconciliationRow $row) => $row->counterpartVat)
            ->filter()
            ->unique()
            ->values();

        if ($afms->isEmpty()) {
            return [];
        }

        return Supplier::query()
            ->where('company_id', $tenant->getKey())
            ->whereIn('afm', $afms->all())
            ->whereNotNull('name')
            ->pluck('name', 'afm')
            ->all();
    }

    private static function expenseUrl(Company $tenant, int $expenseId): ?string
    {
        return ExpenseResource::getUrl('view', [
            'record' => $expenseId,
            'tenant' => $tenant,
        ]);
    }
}
