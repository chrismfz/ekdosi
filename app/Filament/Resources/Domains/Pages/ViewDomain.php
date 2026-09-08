<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Enums\DomainStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\StageRenewalNowAction;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainImportService;
use App\Services\Domains\DomainRenewalService;
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
            // A3a: the registrar-side renew (REAL charge at the registrar) —
            // goes through DomainRenewalService (§6.6 sync-first adopt guard,
            // audit-logged). Billing stays separate: the cursor advances at
            // invoice ISSUE, and an issue after this button ADOPTS (no second
            // registrar renew) — the WHMCS double-renew bug, by construction.
            Action::make('renewAtRegistrar')
                ->label('Ανανέωση στον registrar')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('warning')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->trashed()
                    && $this->record->status instanceof DomainStatus
                    && $this->record->status->isRenewable()
                    && $this->record->expires_at !== null
                    && app(DomainSyncService::class)->isSyncable($this->record))
                ->requiresConfirmation()
                ->modalHeading('Ανανέωση στον registrar;')
                ->modalDescription(function (): string {
                    $years = $this->renewYears();
                    $expiry = $this->record->expires_at?->format('d/m/Y') ?? '—';

                    return "Θα ζητηθεί ανανέωση {$years} ".($years === 1 ? 'έτους' : 'ετών')
                        ." για το {$this->record->fqdn} (τρέχουσα λήξη: {$expiry}). ΧΡΕΩΝΕΙ τον λογαριασμό σας στον registrar."
                        .' Αν το domain έχει ήδη ανανεωθεί από αλλού, η ενέργεια θα το εντοπίσει και ΔΕΝ θα ξαναχρεώσει.';
                })
                ->action(function (): void {
                    try {
                        $log = app(DomainRenewalService::class)->renew($this->record, $this->renewYears());
                    } catch (\Throwable $e) {
                        Notification::make()->title('Η ανανέωση απέτυχε.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->record->refresh();
                    $expiry = $this->record->expires_at?->format('d/m/Y') ?? '—';
                    Notification::make()
                        ->title($log->status === DomainRegistrarLog::STATUS_ADOPTED
                            ? 'Ήταν ήδη ανανεωμένο — υιοθετήθηκε η λήξη του registrar.'
                            : 'Ανανεώθηκε στον registrar.')
                        ->body("Νέα λήξη: {$expiry}.")
                        ->success()->send();
                    $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'last_synced_at', 'sync_error']);
                }),
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

    /** The term the button renews for: the SC's cycle, else the TLD minimum. */
    private function renewYears(): int
    {
        $contract = $this->record->serviceContract;
        $fromCycle = $contract !== null ? app(DomainRenewalService::class)->yearsFor($contract) : null;

        return $fromCycle ?? max(1, (int) ($this->record->tldRule?->min_years ?? 1));
    }
}
