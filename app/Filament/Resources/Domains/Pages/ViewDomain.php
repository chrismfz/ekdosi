<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\StageRenewalNowAction;
use App\Services\Domains\DomainImportService;
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
                        $result = app(DomainSyncService::class)->sync($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Ο συγχρονισμός απέτυχε.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    // The WHMCS per-domain flow: the same pull also refreshes
                    // the contacts on an ΑΔΕΣΠΟΤΟ row (assign-aid) — the
                    // service skips assigned rows (operator territory, §3.7)
                    // and contact failures warn without failing the sync.
                    $contacts = app(DomainImportService::class)->refreshContacts(
                        $this->record,
                        $result->contactHandles,
                        fn (string $m) => Notification::make()->title($m)->warning()->send(),
                    );
                    Notification::make()
                        ->title('Συγχρονίστηκε από τον registrar.')
                        ->body(match (true) {
                            $contacts === 1 => 'Ενημερώθηκε και 1 επαφή.',
                            $contacts > 1 => "Ενημερώθηκαν και {$contacts} επαφές.",
                            default => null,
                        })
                        ->success()->send();
                    $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'last_synced_at', 'sync_error']);
                }),
            EditAction::make(),
        ];
    }
}
