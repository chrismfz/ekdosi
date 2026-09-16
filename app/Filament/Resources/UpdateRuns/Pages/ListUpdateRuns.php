<?php

namespace App\Filament\Resources\UpdateRuns\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use App\Services\Updates\UpdateChecker;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * History of application updates. No «New» action — an update is triggered from
 * «Υγεία συστήματος» (SystemHealth), which locks the target release and creates
 * the run; the cron scheduler applies it. This page is the audit + live view.
 *
 * Two header actions bring the read-only update ergonomics here too (this page is
 * the natural home): «Έλεγχος ενημερώσεων» runs the SAME UpdateChecker the
 * SystemHealth button uses (busts the 6h cache, reports via toast, NEVER applies),
 * and «Ρυθμίσεις ενημερώσεων» jumps to the settings page that holds the repo /
 * token / check toggle.
 */
class ListUpdateRuns extends BaseListRecords
{
    protected static string $resource = UpdateRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Read-only: force a fresh GitHub check (busts the 6h cache). Never
            // applies an update — the upgrade stays with deploy/update.sh. Same
            // logic as SystemHealth::checkUpdates().
            Action::make('checkUpdates')
                ->label('Έλεγχος ενημερώσεων')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function (): void {
                    $update = app(UpdateChecker::class)->check(fresh: true);
                    $ok = ($update['ok'] ?? false) === true;
                    $available = ($update['update_available'] ?? false) === true;

                    Notification::make()
                        ->title(match (true) {
                            ! $ok => 'Ο έλεγχος ενημερώσεων απέτυχε',
                            $available => 'Διαθέσιμη νέα έκδοση: '.($update['latest_version'] ?? '—'),
                            default => 'Είσαι στην πιο πρόσφατη έκδοση',
                        })
                        ->body(match (true) {
                            ! $ok => $update['error'] ?? null,
                            $available => 'Η αναβάθμιση γίνεται από τον server (deploy/update.sh <tag>).',
                            default => null,
                        })
                        ->{$ok ? ($available ? 'warning' : 'success') : 'danger'}()
                        ->send();
                }),

            // Jump to the settings page that carries the update repo / token /
            // check toggle (same SettingsCluster).
            Action::make('updateSettings')
                ->label('Ρυθμίσεις ενημερώσεων')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->url(fn (): string => GeneralSettings::getUrl()),
        ];
    }
}
