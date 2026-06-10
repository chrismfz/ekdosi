<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
