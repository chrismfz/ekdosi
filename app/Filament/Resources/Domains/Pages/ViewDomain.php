<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\StageRenewalNowAction;
use App\Services\Domains\DomainSyncService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * The per-domain view (identity/two-clocks header + contacts/NS/notes/
 * attachments/activity relation tabs). A2b docks the first registrar command
 * here: «Συγχρονισμός» (registrar-truth pull). The A3 write actions follow.
 * docs/domains/README.md §8.2.
 */
class ViewDomain extends ViewRecord
{
    protected static string $resource = DomainResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StageRenewalNowAction::forDomain(),
            Action::make('sync')
                ->label('Συγχρονισμός από registrar')
                ->icon('heroicon-o-arrow-path')
                ->authorize('update')
                ->visible(fn (): bool => app(DomainSyncService::class)->isSyncable($this->record))
                ->action(function (): void {
                    try {
                        app(DomainSyncService::class)->sync($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Ο συγχρονισμός απέτυχε.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()->title('Συγχρονίστηκε από τον registrar.')->success()->send();
                    $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'last_synced_at', 'sync_error']);
                }),
            EditAction::make(),
        ];
    }
}
