<?php

namespace App\Filament\Resources\UpdateRuns\Pages;

use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use App\Models\UpdateRun;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Read-only detail + live progress of one update run. Polling lives on the
 * Infolist sections (`->poll('3s')`), not this page — Filament 5's ViewRecord
 * has no native polling.
 *
 * The «Επαναφορά» action (Phase B) reverts a finished update: it queues a
 * rollback run that checks out the previous commit and RESTORES the pre-update DB
 * snapshot. Destructive — any data written since the update is lost.
 */
class ViewUpdateRun extends ViewRecord
{
    protected static string $resource = UpdateRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rollback')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => UpdateRun::inAppApplyEnabled()
                    && $this->record instanceof UpdateRun
                    && $this->record->canRollback())
                ->requiresConfirmation()
                ->modalHeading('Επαναφορά στην προηγούμενη έκδοση')
                ->modalDescription(fn (): string => sprintf(
                    'Θα γίνει checkout της έκδοσης %s ΚΑΙ επαναφορά της ΒΔ από το στιγμιότυπο πριν την ενημέρωση. ΠΡΟΣΟΧΗ: όποια δεδομένα καταχωρήθηκαν μετά την ενημέρωση (τιμολόγια, πληρωμές κ.λπ.) ΘΑ ΧΑΘΟΥΝ. Λαμβάνεται στιγμιότυπο ασφαλείας πρώτα.',
                    (string) ($this->record->from_ref ?? '?'),
                ))
                ->modalSubmitActionLabel('Επαναφορά τώρα')
                ->action(fn () => $this->rollback()),
        ];
    }

    public function rollback(): void
    {
        /** @var UpdateRun $original */
        $original = $this->record;

        // Hard guard as well as ->visible(): mountAction does not re-check
        // visibility (CLAUDE.md). canRollback() already includes the in-app-apply
        // flag, so a disarmed deploy cannot queue a rollback either — the host
        // path is deploy/rollback.sh.
        if (! UpdateRun::inAppApplyEnabled() || ! $original->canRollback()) {
            Notification::make()
                ->title('Δεν είναι δυνατή η επαναφορά αυτής της ενημέρωσης')
                ->body(UpdateRun::inAppApplyEnabled()
                    ? null
                    : 'Η εφαρμογή ενημερώσεων μέσα από το panel είναι απενεργοποιημένη. '
                      .'Η επαναφορά γίνεται από τον server με deploy/rollback.sh.')
                ->warning()
                ->send();

            return;
        }

        $rollback = UpdateRun::create([
            'status' => UpdateRun::STATUS_QUEUED,
            'kind' => UpdateRun::KIND_ROLLBACK,
            'strategy' => $original->strategy,
            'from_version' => $original->to_version,   // we're currently ON the update
            'from_ref' => null,                        // stamped by the worker at run time
            'to_version' => $original->from_version,   // reverting TO the pre-update build
            'to_ref' => $original->from_ref,
            'rollback_of_id' => $original->id,
            'restore_snapshot' => $original->snapshot_file,
            'triggered_by_user_id' => auth()->id(),
        ]);

        Notification::make()
            ->title('Η επαναφορά προγραμματίστηκε')
            ->body('Θα εκτελεστεί από τον scheduler. Παρακολούθησε την πρόοδο εδώ.')
            ->success()
            ->send();

        $this->redirect(UpdateRunResource::getUrl('view', ['record' => $rollback]));
    }
}
