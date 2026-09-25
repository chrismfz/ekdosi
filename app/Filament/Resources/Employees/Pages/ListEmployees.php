<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Widgets\StaffSetupChecklist;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Ergani\ErganiEmployeeImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class ListEmployees extends BaseListRecords
{
    protected static string $resource = EmployeeResource::class;

    /**
     * Per-request memo of the ΕΡΓΑΝΗ roster (the modal's options and the submit
     * share ONE fresh read). Protected → never dehydrated to the browser.
     *
     * @var array{rows: list<array<string, mixed>>, missing: list<string>}|null
     */
    protected ?array $erganiPlan = null;

    protected ?string $erganiError = null;

    protected function getHeaderActions(): array
    {
        return [
            $this->importFromErganiAction(),
            CreateAction::make()->label('Νέος εργαζόμενος'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [StaffSetupChecklist::class];
    }

    protected function importFromErganiAction(): Action
    {
        return Action::make('importFromErgani')
            ->label('Εισαγωγή από ΕΡΓΑΝΗ')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->visible(fn (): bool => filled($this->tenant()->ergani_username) && filled($this->tenant()->ergani_password)
                && (auth()->user()?->can('create', Employee::class) ?? false))
            ->authorize(fn (): bool => auth()->user()?->can('create', Employee::class) ?? false)
            ->modalHeading('Εισαγωγή εργαζομένων από το ΕΡΓΑΝΗ')
            ->modalDescription('Διαβάζει το τρέχον δυναμικό της εταιρείας από το ΕΡΓΑΝΗ (Παραγωγή — μόνο ανάγνωση, δεν δηλώνεται τίποτα). Κρατάμε μόνο ΑΦΜ, ονοματεπώνυμο, παράρτημα και ημ/νία πρόσληψης. Κανείς δεν διαγράφεται.')
            ->modalSubmitActionLabel('Εισαγωγή επιλεγμένων')
            ->modalSubmitAction(fn (Action $action) => $this->plan() === null || $this->plan()['rows'] === [] ? false : $action)
            ->fillForm(fn (): array => ['afms' => collect($this->plan()['rows'] ?? [])
                ->where('status', ErganiEmployeeImporter::NEW)->pluck('afm')->values()->all()])
            ->schema(fn (): array => $this->importSchema())
            ->action(function (array $data, Action $action): void {
                if ($this->plan() === null) {
                    Notification::make()->title('Δεν έγινε εισαγωγή')->body($this->erganiError ?? 'Το ΕΡΓΑΝΗ δεν απάντησε.')->danger()->send();
                    $action->halt();
                }
                try {
                    // Fresh server-side read at submit (the preview may be up to a minute old).
                    $r = app(ErganiEmployeeImporter::class)->apply($this->tenant(), array_values((array) ($data['afms'] ?? [])));
                } catch (UniqueConstraintViolationException|QueryException) {
                    Notification::make()->title('Κάποιος άλλος εισήγαγε ταυτόχρονα τους ίδιους εργαζόμενους — ανανεώστε και ξαναδοκιμάστε.')->warning()->send();

                    return;
                } catch (\RuntimeException|ConnectionException $e) {
                    Notification::make()->title('Δεν έγινε εισαγωγή')->body($e instanceof ConnectionException ? 'Το ΕΡΓΑΝΗ δεν απαντά — δοκιμάστε ξανά σε λίγο.' : $e->getMessage())->danger()->send();

                    return;
                }
                Notification::make()
                    ->title('Εισαγωγή από ΕΡΓΑΝΗ: '.$r['created'].' νέοι, '.$r['updated'].' ενημερώθηκαν'.($r['skipped'] ? ', '.$r['skipped'].' χωρίς αλλαγή' : ''))
                    ->body($r['created'] ? 'Συμπληρώστε ημέρες άδειας, λογαριασμό και (αν χρειάζεται) κάρτα/PIN στον καθένα.' : null)
                    ->success()->send();
            });
    }

    /** @return array<int, mixed> */
    private function importSchema(): array
    {
        $plan = $this->plan();
        if ($plan === null) {
            return [Placeholder::make('error')->hiddenLabel()->content('Δεν ήταν δυνατή η ανάγνωση από το ΕΡΓΑΝΗ: '.$this->erganiError)];
        }
        if ($plan['rows'] === []) {
            return [Placeholder::make('empty')->hiddenLabel()->content('Το ΕΡΓΑΝΗ δεν επέστρεψε εργαζόμενους για την εταιρεία.')];
        }

        $options = $descriptions = [];
        foreach ($plan['rows'] as $row) {
            $options[$row['afm']] = $row['last_name'].' '.$row['first_name'].' · ΑΦΜ '.$row['afm'];
            $descriptions[$row['afm']] = match ($row['status']) {
                ErganiEmployeeImporter::NEW => 'Νέος — θα προστεθεί'.($row['hired_at'] ? ' (πρόσληψη '.date('d/m/Y', strtotime($row['hired_at'])).')' : ''),
                ErganiEmployeeImporter::DELETED => 'Υπάρχει στους διαγραμμένους — δεν αλλάζει (επαναφορά χειροκίνητα)',
                default => 'Υπάρχει ως «'.$row['local_name'].'»'.($row['local_inactive'] ? ' (ανενεργός εδώ)' : '').' — ενημερώνεται μόνο παράρτημα/ημ. πρόσληψης',
            }.($row['branch'] ? ' · παράρτημα '.$row['branch'] : '')
                .($row['multi_branch'] ? ' · ⚠ δηλωμένος σε πολλά παραρτήματα — ελέγξτε το παράρτημα στην καρτέλα του' : '');
        }

        $schema = [
            CheckboxList::make('afms')
                ->label('Εργαζόμενοι στο ΕΡΓΑΝΗ')
                ->options($options)
                ->descriptions($descriptions)
                ->disableOptionWhen(fn (string $value): bool => collect($plan['rows'])->firstWhere('afm', $value)['status'] === ErganiEmployeeImporter::DELETED)
                ->bulkToggleable(),
        ];
        if ($plan['missing'] !== []) {
            $schema[] = Placeholder::make('missing')
                ->label('Ενεργοί εδώ αλλά όχι στο ΕΡΓΑΝΗ')
                ->content(new HtmlString(e(implode(', ', $plan['missing'])).'<br><small>Δεν αλλάζει τίποτα — αν έφυγαν, ορίστε «Ενεργός: όχι». Αν λείπει ο ΑΦΜ τους, συμπληρώστε τον.</small>'));
        }

        return $schema;
    }

    /**
     * One fresh ΕΡΓΑΝΗ read per request (null + $erganiError on failure) — and ONLY
     * while the import modal is mounted: Filament evaluates the action's schema /
     * submit closures when merely rendering the header, which must never reach
     * ΕΡΓΑΝΗ production on every list view.
     */
    private function plan(): ?array
    {
        // Raw state, not getMountedAction(): resolving the action would evaluate this schema again (recursion).
        // mountedActions is client-writable, so re-check who may import before any ΕΡΓΑΝΗ call.
        if ((Arr::last($this->mountedActions)['name'] ?? null) !== 'importFromErgani'
            || ! (auth()->user()?->can('create', Employee::class) ?? false)
            || blank($this->tenant()->ergani_username) || blank($this->tenant()->ergani_password)) {
            return null;
        }
        if ($this->erganiPlan === null && $this->erganiError === null) {
            try {
                $importer = app(ErganiEmployeeImporter::class);
                // The modal re-renders on every Livewire round-trip (open, cancel, submit):
                // cache the READ for a minute per user+tenant so that's one ΕΡΓΑΝΗ call,
                // not one per click. The submit itself re-reads fresh (apply()).
                $rows = Cache::remember('ergani.ex05.'.$this->tenant()->getKey().'.'.auth()->id(), 60,
                    fn (): array => $importer->fetch($this->tenant()));
                $this->erganiPlan = $importer->plan($this->tenant(), $rows);
            } catch (\RuntimeException|ConnectionException $e) {
                $this->erganiError = $e instanceof ConnectionException ? 'Το ΕΡΓΑΝΗ δεν απαντά — δοκιμάστε ξανά σε λίγο.' : $e->getMessage();
            }
        }

        return $this->erganiPlan;
    }

    private function tenant(): Company
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        return $tenant;
    }
}
