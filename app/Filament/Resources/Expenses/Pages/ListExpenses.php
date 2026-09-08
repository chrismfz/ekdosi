<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseSource;
use App\Filament\BaseListRecords;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\ExpensePickerWindow;
use App\Filament\Support\Tags\TagControls;
use App\Models\Company;
use App\Models\Expense;
use App\Services\MyData\ExpenseImporter;
use App\Support\Money;
use App\Support\MyData\Codes;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ListExpenses extends BaseListRecords
{
    protected static string $resource = ExpenseResource::class;

    /** Fetched αδέσποτα (mark => label) for the «Άντληση» picker modal. */
    public array $orphanOptions = [];

    public ?string $orphanFrom = null;

    public ?string $orphanTo = null;

    public ?string $orphanError = null;

    /** Selected period preset for the picker (see App\Filament\Support\ExpensePickerWindow). */
    public ?string $orphanPeriod = 'quarter';

    /**
     * «Άντληση από myDATA» — self-contained on the Έξοδα list: one click fetches
     * the current quarter's supplier docs and opens a picker modal of the
     * αδέσποτα (στο myDATA, όχι τοπικά), each with a checkbox; the operator keeps
     * exactly the ones they want and they're imported in place — no bounce to the
     * console, no all-or-nothing. Import stays a deliberate, reviewed write
     * (creates expenses + suppliers). Shown only to users who can read myDATA.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [
            // Manual entry: a supplier doc that isn't in myDATA (foreign supplier,
            // cash receipt…). The myDATA-sourced rows stay read-only.
            CreateAction::make()
                ->label('Νέα δαπάνη (χειροκίνητη)')
                ->icon('heroicon-o-plus'),
        ];

        if ($this->canFetchMyData()) {
            // The button itself has NO schema → clicking runs immediately: fetch
            // the αδέσποτα, then chain into the picker modal. (Filament builds an
            // action's schema BEFORE its mountUsing runs, so we can't fetch in
            // mountUsing and read it in the schema — the fetch must happen first,
            // here, then replaceMountedAction opens the picker whose schema reads
            // the now-populated options.)
            $actions[] = Action::make('fetchFromMyData')
                ->label('Άντληση από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('primary')
                ->tooltip('Κατεβάζει τα έξοδα του τρέχοντος τριμήνου από το myDATA και ανοίγει λίστα επιλογής (με δυνατότητα αλλαγής διαστήματος).')
                ->action(function (): void {
                    // Fetch the current period (default: τρέχον τρίμηνο; retains the
                    // last picked one within the session) then open the picker.
                    $this->loadOrphans();
                    $this->replaceMountedAction('pickMyDataOrphans');
                });
        }

        return $actions;
    }

    /**
     * The picker modal mounted after «Άντληση»: a checkbox list of the fetched
     * αδέσποτα (all pre-ticked), importing exactly the kept ones. Resolved by name
     * via Filament's {name}Action() convention when chained from the button.
     */
    public function pickMyDataOrphansAction(): Action
    {
        return Action::make('pickMyDataOrphans')
            ->modalHeading('Άντληση εξόδων από myDATA')
            ->modalDescription('Έξοδα που υπέβαλαν προμηθευτές και δεν τα έχουμε τοπικά. Επιλέξτε ποια να καταχωριστούν — ήδη καταχωρημένα παραλείπονται. Αν δεν βρεθεί κάτι, άλλαξε διάστημα.')
            ->modalSubmitActionLabel('Καταχώριση επιλεγμένων')
            ->schema(fn (): array => $this->orphanSchema())
            ->action(fn (array $data) => $this->importSelectedOrphans($data['marks'] ?? []));
    }

    /**
     * Picker modal body: the period selector (always shown, so an empty window can
     * be widened in place), then either an error/empty note or the αδέσποτα list.
     */
    private function orphanSchema(): array
    {
        $components = [$this->periodSelect()];

        if ($this->orphanError !== null) {
            $components[] = Placeholder::make('orphanError')->hiddenLabel()->content($this->orphanError);

            return $components;
        }

        $window = ($this->orphanFrom && $this->orphanTo) ? " ({$this->orphanFrom} – {$this->orphanTo})" : '';

        if ($this->orphanOptions === []) {
            $components[] = Placeholder::make('orphanNone')->hiddenLabel()
                ->content('Δεν βρέθηκαν αδέσποτα έξοδα στο επιλεγμένο διάστημα'.$window.'. Δοκίμασε μεγαλύτερο διάστημα παραπάνω.');

            return $components;
        }

        $components[] = CheckboxList::make('marks')
            ->label('Αδέσποτα έξοδα προς καταχώριση'.$window)
            ->options(fn (): array => $this->orphanOptions) // closure → refreshes on a live re-fetch
            ->default(array_keys($this->orphanOptions)) // all pre-checked; untick to skip
            ->bulkToggleable()
            ->columns(1);

        return $components;
    }

    /**
     * The period preset selector. Live: changing it re-fetches the chosen window
     * in place (one AADE call per pick) and re-ticks the new αδέσποτα.
     */
    private function periodSelect(): Select
    {
        return Select::make('period')
            ->label('Διάστημα')
            ->options(ExpensePickerWindow::options())
            ->default($this->orphanPeriod)
            ->selectablePlaceholder(false)
            ->live()
            ->afterStateUpdated(function ($state, Set $set): void {
                $this->orphanPeriod = (string) $state;
                $this->loadOrphans();
                // Re-tick everything in the new window (default() only fires once).
                $set('marks', array_keys($this->orphanOptions));
            })
            ->helperText('Ξεκινά από το τρέχον τρίμηνο. Αν δεν βρεθεί κάτι, διάλεξε μεγαλύτερο διάστημα.');
    }

    /** Read-only fetch of the selected period's αδέσποτα into the picker options. */
    private function loadOrphans(): void
    {
        $this->orphanOptions = [];
        $this->orphanError = null;

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        [$from, $to] = ExpensePickerWindow::resolve($this->orphanPeriod ?? 'quarter');
        $this->orphanFrom = $from->format('d/m/Y');
        $this->orphanTo = $to->format('d/m/Y');

        try {
            // Reuse the console snapshot (writes the same cache the subheading
            // reads) — its serialized αδέσποτα already have supplier names resolved.
            MyDataConsoleExpenses::refreshSnapshot($tenant, $from, $to);
        } catch (RateLimitExceededException) {
            $this->orphanError = 'Προσωρινό όριο myDATA — δοκιμάστε ξανά σε λίγο.';

            return;
        } catch (RuntimeException $e) {
            $this->orphanError = $e->getMessage();

            return;
        } catch (Throwable $e) {
            Log::warning('Expenses list myDATA fetch failed', [
                'company_id' => $tenant->getKey(), 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            $this->orphanError = 'Σφάλμα σύνδεσης με το AADE.';

            return;
        }

        foreach (MyDataConsoleExpenses::lastFetchState($tenant->getKey())['result']['missingLocally'] ?? [] as $row) {
            $name = $row['counterpartName'] ?: ($row['afm'] ?? '—');
            $code = $row['invcode'] ?: $row['mark'];
            $this->orphanOptions[$row['mark']] = trim("{$code} · {$name} · ".Money::eur($row['gross']).' · '.($row['issuedAt'] ?? ''));
        }
    }

    /** Import exactly the MARKs the operator kept ticked, then stay on the list. */
    private function importSelectedOrphans(array $marks): void
    {
        $marks = array_values(array_filter($marks));
        if ($marks === []) {
            Notification::make()->title('Δεν επιλέχθηκε κανένα έξοδο')->warning()->send();

            return;
        }

        // Defensive (like MyDataConsoleExpenses::importOrphans): the picker only
        // submits when options are filled, and loadOrphans() sets the dates before
        // the options — but guard the coupling so a future refactor can't TypeError
        // on createFromFormat(null).
        if (! $this->orphanFrom || ! $this->orphanTo) {
            return;
        }

        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $from = Carbon::createFromFormat('d/m/Y', $this->orphanFrom)->startOfDay();
        $to = Carbon::createFromFormat('d/m/Y', $this->orphanTo)->endOfDay();

        try {
            // Share the console's MockHandler test seam so the round-trip is
            // exercisable without the network (null in production → real AADE).
            $result = (new ExpenseImporter($tenant, MyDataConsoleExpenses::$testHandler))->importMarks($from, $to, $marks);
            // Drop the just-imported MARKs from the cached αδέσποτα so the «X
            // αδέσποτα» subheading updates WITHOUT a third AADE fetch (loadOrphans +
            // importMarks already hit the window twice).
            $this->dropImportedFromSnapshot($tenant, $result->createdMarks);

            Notification::make()
                ->title("Καταχωρήθηκαν {$result->created} έξοδα")
                ->body($result->summary())
                ->{$result->created > 0 ? 'success' : 'warning'}()
                ->send();
        } catch (RuntimeException $e) {
            Notification::make()->title('Η καταχώριση απέτυχε')->body($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            Log::warning('Expenses list myDATA import failed', [
                'company_id' => $tenant->getKey(), 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Η καταχώριση απέτυχε')->body('Σφάλμα κατά τη λήψη/καταχώριση από το AADE.')->danger()->send();
        }
    }

    /**
     * Remove the just-imported MARKs from the cached expenses snapshot so the
     * subheading «X αδέσποτα» reflects the import without re-fetching from AADE.
     *
     * @param  list<string|int>  $createdMarks
     */
    private function dropImportedFromSnapshot(Company $tenant, array $createdMarks): void
    {
        $state = MyDataConsoleExpenses::lastFetchState($tenant->getKey());
        if ($state === null || ! isset($state['result']['missingLocally'])) {
            return;
        }

        $imported = array_map('strval', $createdMarks);
        $state['result']['missingLocally'] = array_values(array_filter(
            $state['result']['missingLocally'],
            fn (array $row): bool => ! in_array((string) $row['mark'], $imported, true),
        ));

        MyDataConsoleExpenses::putFetchState($tenant->getKey(), $state);
    }

    /** A «τελευταία άντληση myDATA … · X αδέσποτα» line under the title. */
    public function getSubheading(): ?string
    {
        if (! $this->canFetchMyData()) {
            return null;
        }

        $tenantKey = Filament::getTenant()?->getKey();
        $at = MyDataConsoleExpenses::lastFetchAt($tenantKey);
        if ($at === null) {
            return 'myDATA: δεν έχει γίνει άντληση εξόδων ακόμη.';
        }

        $line = 'Τελευταία άντληση myDATA: '.$at->diffForHumans();
        $orphans = MyDataConsoleExpenses::lastOrphanCount($tenantKey);

        return $orphans > 0 ? $line." · {$orphans} αδέσποτα έξοδα προς καταχώριση" : $line;
    }

    private function canFetchMyData(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->canReadMyData()
            && (bool) auth()->user()?->can('View:MyDataConsoleExpenses');
    }

    /**
     * Three buckets so supplier invoices, our own real expense documents
     * (13/14), and our accounting entries (μισθοδοσία/πάγια, 17.x) don't read
     * as one undifferentiated list — a €5k payroll must not look like a
     * τιμολόγιο. The "accounting" category set comes from Codes
     * (ACCOUNTING_EXPENSE_CATEGORIES) so the taxonomy lives in one place, not
     * hand-typed here.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $accounting = Codes::ACCOUNTING_EXPENSE_CATEGORIES;

        // The fixed economic buckets, then the operator's pinned-tag tabs
        // (appended after — same Έξοδα-style fast filters, tag-driven).
        $needsClassification = Expense::query()->needsClassification()->count();

        return [
            'all' => Tab::make('Όλα'),

            // Worklist: AADE-pulled expenses still awaiting a χαρακτηρισμός (#5).
            'needs_classification' => Tab::make('Προς χαρακτηρισμό')
                ->icon('heroicon-o-tag')
                ->badge($needsClassification ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->needsClassification()),

            'suppliers' => Tab::make('Προμηθευτών')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('source', ExpenseSource::Sync->value)),

            'ours' => Tab::make('Δικά μας παραστατικά')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->where(fn (Builder $w) => $w
                        ->whereNull('category')
                        ->orWhereNotIn('category', $accounting))),

            'accounting' => Tab::make('Λοιπά (πάγια/μισθοδοσία)')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->whereIn('category', $accounting)),

            'manual' => Tab::make('Χειροκίνητα')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('source', ExpenseSource::Manual->value)),
        ] + TagControls::tagTabs(Expense::class);
    }
}
