<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Enums\DomainStatus;
use App\Filament\Resources\Domains\DomainResource;
use App\Filament\Support\StageRenewalNowAction;
use App\Models\DomainRegistrarLog;
use App\Services\Domains\DomainImportService;
use App\Services\Domains\DomainManagementService;
use App\Services\Domains\DomainRegistrationService;
use App\Services\Domains\DomainRenewalService;
use App\Services\Domains\DomainSyncService;
use App\Services\Domains\DomainTransferService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
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
            // A3b: register a NEW (pending) domain at the registrar — REAL
            // charge, operator-gated (§6.1 post-payment / §7.1 «Automatic
            // Registration = No» is the existing practice). Adopt-on-retry
            // lives in the service: a retry after a timeout-that-charged
            // adopts instead of paying twice.
            Action::make('registerAtRegistrar')
                ->label('Καταχώρηση στον registrar')
                ->icon('heroicon-o-rocket-launch')
                ->color('warning')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->trashed()
                    && $this->record->status === DomainStatus::PendingRegister
                    && app(DomainSyncService::class)->isSyncable($this->record))
                ->requiresConfirmation()
                ->modalHeading('Καταχώρηση στον registrar;')
                ->modalDescription(function (): string {
                    $years = $this->renewYears();

                    return "Θα καταχωρηθεί το {$this->record->fqdn} για {$years} ".($years === 1 ? 'έτος' : 'έτη')
                        .'. ΧΡΕΩΝΕΙ τον λογαριασμό σας στον registrar. Απαιτεί επαφή registrant και ≥2 nameservers.'
                        .' Αν έχει ήδη καταχωρηθεί από αλλού (retry), θα υιοθετηθεί χωρίς δεύτερη χρέωση.';
                })
                ->action(function (): void {
                    try {
                        $log = app(DomainRegistrationService::class)->register($this->record, $this->renewYears());
                    } catch (\Throwable $e) {
                        Notification::make()->title('Η καταχώρηση απέτυχε.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()
                        ->title($log->status === DomainRegistrarLog::STATUS_ADOPTED
                            ? 'Ήταν ήδη καταχωρημένο στον λογαριασμό — υιοθετήθηκε.'
                            : 'Καταχωρήθηκε στον registrar.')
                        ->body('Λήξη: '.($this->record->expires_at?->format('d/m/Y') ?? 'θα έρθει με το επόμενο sync').'.')
                        ->success()->send();
                    $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'registered_at', 'last_synced_at', 'sync_error']);
                }),
            // A3c: inbound transfer — REAL charge (gTLD transfers add a
            // renewal year). Async (§6.3): the row stays «Εκκρεμεί μεταφορά»
            // and the nightly sync completes/flags it.
            Action::make('transferInAtRegistrar')
                ->label('Μεταφορά στον registrar')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->trashed()
                    && $this->record->status === DomainStatus::PendingTransfer
                    && app(DomainSyncService::class)->isSyncable($this->record))
                ->schema([
                    TextInput::make('auth_code')
                        ->label('Κωδικός EPP/auth (από τον τρέχοντα registrar)')
                        ->required()
                        ->password()
                        ->revealable(),
                ])
                ->modalHeading('Έναρξη εισερχόμενης μεταφοράς;')
                ->modalDescription(fn (): string => "Θα ζητηθεί μεταφορά του {$this->record->fqdn} στον λογαριασμό σας."
                    .' ΧΡΕΩΝΕΙ τον registrar (οι gTLD μεταφορές προσθέτουν 1 έτος). Η ολοκλήρωση είναι ασύγχρονη —'
                    .' το nightly sync θα ενημερώσει την κατάσταση. Αν έχει ήδη ξεκινήσει (retry), θα υιοθετηθεί χωρίς δεύτερη χρέωση.')
                ->action(function (array $data): void {
                    try {
                        $log = app(DomainTransferService::class)->transferIn($this->record, (string) $data['auth_code']);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Η μεταφορά δεν ξεκίνησε.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->record->refresh();
                    Notification::make()
                        ->title($log->status === DomainRegistrarLog::STATUS_ADOPTED
                            ? 'Η μεταφορά ήταν ήδη σε εξέλιξη/ολοκληρωμένη — υιοθετήθηκε η κατάσταση.'
                            : 'Η μεταφορά ξεκίνησε.')
                        ->body('Το nightly sync θα παρακολουθεί την πορεία της (ή πατήστε «Συγχρονισμός»).')
                        ->success()->send();
                    $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'last_synced_at', 'sync_error']);
                }),
            // A3c: the transfer-OUT aid (§6.3 — outgoing stays operator-gated
            // v1): retrieve the EPP code, audit-logged WITHOUT the code.
            Action::make('eppCode')
                ->label('Κωδικός EPP')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->trashed()
                    && $this->record->status !== DomainStatus::PendingRegister
                    && $this->record->status !== DomainStatus::PendingTransfer
                    && app(DomainSyncService::class)->isSyncable($this->record))
                ->requiresConfirmation()
                ->modalHeading('Ανάκτηση κωδικού EPP;')
                ->modalDescription('Ο κωδικός EPP επιτρέπει τη ΜΕΤΑΦΟΡΑ του domain σε άλλον registrar. Η ανάκτηση καταγράφεται στο ιστορικό API (χωρίς τον κωδικό).')
                ->action(function (): void {
                    try {
                        $code = app(DomainTransferService::class)->eppCode($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Αποτυχία ανάκτησης κωδικού EPP.')->body($e->getMessage())->danger()->send();

                        return;
                    }
                    Notification::make()
                        ->title("Κωδικός EPP — {$this->record->fqdn}")
                        ->body($code)
                        ->info()
                        ->persistent()
                        ->send();
                }),
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
            // A3d: the management writes (NS/lock/privacy/contacts) + the
            // redemption restore — grouped so the header stays scannable.
            // Every operation goes through DomainManagementService (shared
            // write guards + per-operation audit log).
            ActionGroup::make([
                Action::make('pushNameservers')
                    ->label('Αποστολή nameservers')
                    ->icon('heroicon-o-server-stack')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->isManageable())
                    ->requiresConfirmation()
                    ->modalHeading('Αποστολή nameservers στον registrar;')
                    ->modalDescription(fn (): string => 'Οι nameservers της καρτέλας «Nameservers» θα ΑΝΤΙΚΑΤΑΣΤΗΣΟΥΝ πλήρως τους τρέχοντες στον registrar για το '.$this->record->fqdn.'.')
                    ->action(fn () => $this->runManagement(
                        fn (DomainManagementService $s) => $s->pushNameservers($this->record),
                        'Οι nameservers στάλθηκαν στον registrar.',
                    )),
                Action::make('toggleTransferLock')
                    ->label(fn (): string => $this->record->transfer_lock ? 'Ξεκλείδωμα μεταφοράς' : 'Κλείδωμα μεταφοράς')
                    ->icon(fn (): string => $this->record->transfer_lock ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->isManageable())
                    ->requiresConfirmation()
                    ->modalHeading(fn (): string => $this->record->transfer_lock ? 'Ξεκλείδωμα μεταφοράς;' : 'Κλείδωμα μεταφοράς;')
                    ->modalDescription(fn (): string => $this->record->transfer_lock
                        ? "Το {$this->record->fqdn} θα ΞΕΚΛΕΙΔΩΘΕΙ στον registrar — θα επιτρέπεται μεταφορά του σε άλλον registrar."
                        : "Το {$this->record->fqdn} θα κλειδωθεί στον registrar — δεν θα επιτρέπεται μεταφορά του.")
                    ->action(fn () => $this->runManagement(
                        fn (DomainManagementService $s) => $s->setTransferLock($this->record, ! $this->record->transfer_lock),
                        'Το κλείδωμα μεταφοράς ενημερώθηκε στον registrar.',
                    )),
                Action::make('toggleWhoisPrivacy')
                    ->label(fn (): string => $this->record->whois_privacy ? 'Απενεργοποίηση WHOIS privacy' : 'Ενεργοποίηση WHOIS privacy')
                    ->icon('heroicon-o-eye-slash')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->isManageable())
                    ->requiresConfirmation()
                    ->modalHeading('Αλλαγή WHOIS privacy;')
                    ->modalDescription(fn (): string => 'Το WHOIS privacy του '.$this->record->fqdn.' θα '
                        .($this->record->whois_privacy ? 'ΑΠΕΝΕΡΓΟΠΟΙΗΘΕΙ' : 'ενεργοποιηθεί').' στον registrar.')
                    ->action(fn () => $this->runManagement(
                        fn (DomainManagementService $s) => $s->setWhoisPrivacy($this->record, ! $this->record->whois_privacy),
                        'Το WHOIS privacy ενημερώθηκε στον registrar.',
                    )),
                Action::make('pushContacts')
                    ->label('Αποστολή επαφών')
                    ->icon('heroicon-o-identification')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->isManageable())
                    ->requiresConfirmation()
                    ->modalHeading('Αποστολή επαφών στον registrar;')
                    ->modalDescription(fn (): string => 'Οι επαφές της καρτέλας «Επαφές» θα σταλούν στον registrar για το '.$this->record->fqdn.' (WHOIS στοιχεία — δημιουργούνται handles όπου λείπουν).')
                    ->action(fn () => $this->runManagement(
                        fn (DomainManagementService $s) => $s->pushContacts($this->record),
                        'Οι επαφές στάλθηκαν στον registrar.',
                    )),
            ])
                ->label('Registrar')
                ->icon('heroicon-o-wrench-screwdriver')
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->isManageable()),
            // A3d: redemption restore — REAL (συνήθως μεγάλη) χρέωση, so it
            // stands alone in the header (never buried in a submenu) with the
            // adopt guard spelled out.
            Action::make('restoreFromRedemption')
                ->label('Επαναφορά από redemption')
                ->icon('heroicon-o-lifebuoy')
                ->color('danger')
                ->authorize('update')
                ->visible(fn (): bool => ! $this->record->trashed()
                    && in_array($this->record->status, [DomainStatus::Redemption, DomainStatus::Deleted], true)
                    && app(DomainSyncService::class)->isSyncable($this->record))
                ->requiresConfirmation()
                ->modalHeading('Επαναφορά από redemption;')
                ->modalDescription(fn (): string => "Θα ζητηθεί επαναφορά του {$this->record->fqdn} από redemption."
                    .' ΧΡΕΩΝΕΙ τον λογαριασμό σας στον registrar — τα restore fees είναι συνήθως ΠΟΛΛΑΠΛΑΣΙΑ της ανανέωσης.'
                    .' Αν το domain έχει ήδη επανέλθει από αλλού, θα υιοθετηθεί χωρίς χρέωση. Η χρέωση του πελάτη γίνεται χειροκίνητα.')
                ->action(fn () => $this->runManagement(
                    fn (DomainManagementService $s) => $s->restore($this->record),
                    'Η επαναφορά ζητήθηκε από τον registrar.',
                    adopted: 'Ήταν ήδη ενεργό στον λογαριασμό — υιοθετήθηκε χωρίς χρέωση.',
                )),
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

    /** The A3d management writes fire only on a live, API-routed registrar object. */
    private function isManageable(): bool
    {
        return ! $this->record->trashed()
            && in_array($this->record->status, [DomainStatus::Active, DomainStatus::Expired, DomainStatus::Grace], true)
            && app(DomainSyncService::class)->isSyncable($this->record);
    }

    /** Shared run/notify/refresh wrapper for the A3d management actions. */
    private function runManagement(\Closure $operation, string $success, ?string $adopted = null): void
    {
        try {
            $log = $operation(app(DomainManagementService::class));
        } catch (\Throwable $e) {
            Notification::make()->title('Η ενέργεια στον registrar απέτυχε.')->body($e->getMessage())->danger()->send();

            return;
        }
        $this->record->refresh();
        Notification::make()
            ->title($log->status === DomainRegistrarLog::STATUS_ADOPTED ? ($adopted ?? $success) : $success)
            ->success()->send();
        $this->refreshFormData(['expires_at', 'status', 'registrar_domain_id', 'transfer_lock', 'whois_privacy', 'last_synced_at', 'sync_error']);
    }

    /** The term the button renews for: the SC's cycle, else the TLD minimum. */
    private function renewYears(): int
    {
        $contract = $this->record->serviceContract;
        $fromCycle = $contract !== null ? app(DomainRenewalService::class)->yearsFor($contract) : null;

        return $fromCycle ?? max(1, (int) ($this->record->tldRule?->min_years ?? 1));
    }
}
