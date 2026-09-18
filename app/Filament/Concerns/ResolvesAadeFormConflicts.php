<?php

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;

/**
 * The per-field conflict picker that «Άντληση από ΑΑΔΕ» chains into when the
 * registry disagrees with values the operator has ALREADY typed.
 *
 * The button (a suffix action on the ΑΦΜ field, see CustomerForm) fills every
 * EMPTY field silently — nothing is lost — then, if any filled field differs
 * from AADE, it calls
 *   `$livewire->replaceMountedAction('resolveAadeConflicts', arguments: ['conflicts' => …])`.
 * This trait supplies that action: a checkbox list showing «δικό μας → ΑΑΔΕ» per
 * field, so the operator picks exactly which typed values to replace. Unpicked
 * fields are left untouched — the «Άντληση» promise («η ΑΑΔΕ γεμίζει τα κενά, δεν
 * σβήνει ό,τι έγραψες») is kept even when there ARE differences.
 *
 * The conflicts travel as the mounted action's ARGUMENTS (not a public
 * property): Filament discards them when the modal closes — submit OR cancel —
 * so no stale conflict data (which includes address values) lingers on the
 * component's serialized state after the picker is dismissed.
 *
 * Mirrors the ListExpenses «Άντληση από myDATA» → picker pattern (a modal-less
 * action that fetches, then chains a picker modal). Used by CreateCustomer +
 * EditCustomer so the two pages can't drift.
 *
 * The chosen AADE values are written straight into the page's form state
 * (`$this->data`), NOT the database — the operator still reviews and Saves the
 * form as usual (draft-safe; on the create page there is no record yet). This
 * assumes the target fields sit at the form ROOT (as every customer field
 * does); a nested statePath would need the write path adjusted to match.
 *
 * @property array<string, mixed>|null $data the page form state (from InteractsWithForms)
 */
trait ResolvesAadeFormConflicts
{
    public function resolveAadeConflictsAction(): Action
    {
        return Action::make('resolveAadeConflicts')
            ->modalHeading('Διαφορές με το μητρώο ΑΑΔΕ')
            ->modalDescription('Τα κενά πεδία συμπληρώθηκαν ήδη αυτόματα. Τα παρακάτω έχουν ΗΔΗ τιμή διαφορετική από την ΑΑΔΕ — τσέκαρε όσα θέλεις να αντικατασταθούν με τα επίσημα στοιχεία. Όσα αφήσεις άτσεκα μένουν ως έχουν. (Οι αλλαγές εφαρμόζονται στη φόρμα· θα οριστικοποιηθούν με την Αποθήκευση.)')
            ->modalSubmitActionLabel('Ενημέρωση επιλεγμένων')
            ->modalCancelActionLabel('Καμία αλλαγή')
            ->schema(fn (array $arguments): array => [
                CheckboxList::make('fields')
                    ->hiddenLabel()
                    ->options($this->aadeConflictOptions($arguments['conflicts'] ?? []))
                    ->descriptions($this->aadeConflictDescriptions($arguments['conflicts'] ?? []))
                    ->bulkToggleable()
                    ->columns(1),
            ])
            ->action(function (array $data, array $arguments): void {
                $chosen = $data['fields'] ?? [];
                $conflicts = $arguments['conflicts'] ?? [];

                $applied = 0;
                foreach ($chosen as $field) {
                    if (! isset($conflicts[$field]['aade'])) {
                        continue;   // stale/unknown key — ignore
                    }
                    // Write the AADE value into the form state. Filament binds
                    // each (root-level) field to $this->data via its state path,
                    // so mutating the array updates the rendered field on the
                    // next round-trip.
                    data_set($this->data, $field, $conflicts[$field]['aade']);
                    $applied++;
                }

                Notification::make()
                    ->title($applied > 0
                        ? "Ενημερώθηκαν {$applied} πεδίο/α από την ΑΑΔΕ"
                        : 'Δεν επιλέχθηκε κανένα πεδίο — καμία αλλαγή')
                    ->{$applied > 0 ? 'success' : 'info'}()
                    ->send();
            });
    }

    /**
     * field ⇒ Greek label, for the checkbox list.
     *
     * @param  array<string, array{label: string, current: string, aade: string}>  $conflicts
     * @return array<string, string>
     */
    protected function aadeConflictOptions(array $conflicts): array
    {
        $options = [];
        foreach ($conflicts as $field => $pair) {
            $options[$field] = $pair['label'] ?? $field;
        }

        return $options;
    }

    /**
     * field ⇒ «δικό μας → ΑΑΔΕ» so the operator sees the actual change.
     *
     * @param  array<string, array{label: string, current: string, aade: string}>  $conflicts
     * @return array<string, string>
     */
    protected function aadeConflictDescriptions(array $conflicts): array
    {
        $descriptions = [];
        foreach ($conflicts as $field => $pair) {
            $current = ($pair['current'] ?? '') !== '' ? $pair['current'] : '—';
            $descriptions[$field] = "«{$current}» → «".($pair['aade'] ?? '').'»';
        }

        return $descriptions;
    }
}
