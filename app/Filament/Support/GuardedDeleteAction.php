<?php

namespace App\Filament\Support;

use App\Exceptions\DeletionBlocked;
use Closure;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\TrashedFilter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A DeleteAction that BLOCKS deletion of a lookup row still referenced by other
 * records, instead of the two bad outcomes today: a `nullOnDelete` FK silently
 * orphans the dependents, a `restrictOnDelete` FK hard-fails with a raw DB error,
 * and — since the lookups soft-delete — a plain delete leaves the dependents
 * showing a BLANK label (the deleted lookup row vanishes from every Select).
 *
 * Wire it on a lookup resource's delete action with a closure that returns the
 * human-labelled dependent counts:
 *
 *   GuardedDeleteAction::make(fn (VatCategory $r) => [
 *       'προϊόντα' => Product::where('vat_category_id', $r->id)->count(),
 *   ])
 *
 * Counts run in the panel (tenant scope applies), so they're this tenant's only.
 */
class GuardedDeleteAction
{
    /**
     * @param  Closure(Model): array<string, int>  $dependents  label => count
     */
    public static function make(Closure $dependents): DeleteAction
    {
        return DeleteAction::make()
            ->before(function (DeleteAction $action, Model $record) use ($dependents): void {
                $counts = array_filter($dependents($record), fn (int $n): bool => $n > 0);
                if ($counts === []) {
                    return;   // nothing references it → let the delete proceed
                }

                $parts = [];
                foreach ($counts as $label => $n) {
                    $parts[] = "{$label}: {$n}";
                }

                Notification::make()
                    ->title('Δεν διαγράφεται — χρησιμοποιείται')
                    ->body('Χρησιμοποιείται από — '.implode(' · ', $parts).'. Άλλαξε ή αφαίρεσε αυτές τις εγγραφές πρώτα.')
                    ->danger()
                    ->persistent()
                    ->send();

                $action->halt();
            });
    }

    /**
     * SET-2: the BULK counterpart. The stock DeleteBulkAction/ForceDeleteBulkAction
     * on the lookup tables were UNGUARDED — bulk-deleting an in-use lookup fell
     * through to the DB FK (a raw 500 on restrictOnDelete, or a silent null +
     * blank Select on nullOnDelete). This custom bulk action reuses the SAME
     * dependent map as the single-record guard and PARTIAL-skips the in-use rows
     * (deleting the free ones), reporting «Διαγράφηκαν: N · Παραλείφθηκαν: M» —
     * the app's established bulk-skip UX (WhmcsInbox / InvoicesTable).
     *
     * @param  Closure(Model): array<string, int>  $dependents  label => count
     */
    public static function bulk(Closure $dependents): BulkAction
    {
        return self::guardedBulk('delete', 'Διαγραφή επιλεγμένων', 'deleteAny', $dependents, fn (Model $r) => $r->delete())
            // Mirror the stock DeleteBulkAction: hidden on the ONLY-trashed view
            // (soft-deleting an already-trashed row is a no-op / dishonest count).
            ->hidden(function (HasTable $livewire): bool {
                $state = $livewire->getTableFilterState(TrashedFilter::class) ?? [];
                if (! array_key_exists('value', $state)) {
                    return false;
                }
                if ($state['value']) {
                    return false;
                }

                return filled($state['value']);
            });
    }

    /**
     * Force-delete twin of bulk() — same in-use guard, but permanently removes the
     * (soft-deleted) rows. Guards the ForceDeleteBulkAction path.
     *
     * @param  Closure(Model): array<string, int>  $dependents  label => count
     */
    public static function forceBulk(Closure $dependents): BulkAction
    {
        return self::guardedBulk('forceDelete', 'Οριστική διαγραφή επιλεγμένων', 'forceDeleteAny', $dependents, fn (Model $r) => $r->forceDelete())
            // CRITICAL (SET-2 review): mirror the stock ForceDeleteBulkAction —
            // permanent delete is HIDDEN unless the operator has switched to a
            // trashed view. Without this, hand-rolling the action re-exposed
            // one-click permanent deletion of LIVE lookup rows on the default
            // list (bypassing the soft-delete tombstone / restore path).
            ->hidden(function (HasTable $livewire): bool {
                $state = $livewire->getTableFilterState(TrashedFilter::class) ?? [];
                if (! array_key_exists('value', $state)) {
                    return false;
                }

                return blank($state['value']);
            });
    }

    /**
     * @param  Closure(Model): array<string, int>  $dependents
     * @param  Closure(Model): mixed  $delete
     */
    private static function guardedBulk(string $name, string $label, string $permission, Closure $dependents, Closure $delete): BulkAction
    {
        return BulkAction::make($name)
            ->label($label)
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->authorize($permission)
            ->requiresConfirmation()
            ->modalHeading($label)
            ->modalDescription('Όσες εγγραφές χρησιμοποιούνται από άλλα δεδομένα ΠΑΡΑΛΕΙΠΟΝΤΑΙ (δεν διαγράφονται) — άλλαξέ ή αφαίρεσέ τις πρώτα.')
            ->action(function (Collection $records) use ($dependents, $delete): void {
                $deleted = 0;
                $skipped = 0;
                foreach ($records as $record) {
                    $inUse = array_filter($dependents($record), fn (int $n): bool => $n > 0);
                    if ($inUse !== []) {
                        $skipped++;

                        continue;
                    }
                    try {
                        $done = $delete($record);
                    } catch (DeletionBlocked) {
                        // a model-level guard refused it (e.g. Customer::forceDeleting,
                        // a blocker that appeared after the pre-check) — skip, don't 500.
                        // Anything else (a DB error) still propagates and gets reported.
                        $done = false;
                    }
                    if ($done === false) {   // refused, or a listener returned false
                        $skipped++;

                        continue;
                    }
                    $deleted++;
                }

                Notification::make()
                    ->title("Διαγράφηκαν: {$deleted}".($skipped > 0 ? " · Παραλείφθηκαν (σε χρήση): {$skipped}" : ''))
                    ->{$skipped > 0 ? 'warning' : 'success'}()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Count rows of $model referencing $id via $column — INCLUDING soft-deleted
     * ones (a trashed invoice still references the lookup; ignoring it would let
     * the lookup be deleted and then surface blank if that invoice is restored).
     *
     * @param  class-string<Model>  $model
     */
    public static function count(string $model, string $column, int|string|null $id): int
    {
        if ($id === null) {
            return 0;
        }

        $query = $model::query()->where($column, $id);

        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        return $query->count();
    }
}
