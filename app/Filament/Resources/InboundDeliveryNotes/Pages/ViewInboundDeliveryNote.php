<?php

namespace App\Filament\Resources\InboundDeliveryNotes\Pages;

use App\Filament\Resources\InboundDeliveryNotes\InboundDeliveryNoteResource;
use App\Models\Company;
use App\Models\InboundDeliveryNote;
use App\Services\Delivery\InboundDeliveryService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Throwable;

/**
 * Slice 4b — view a staged inbound movement + its lifecycle, with the recipient
 * actions. See docs/delivery-inbound-design.md §5.
 *
 * «Απόρριψη» (RejectDeliveryNote by MARK) and «Έλεγχος κατάστασης»
 * (RequestDeliveryNoteStatus by MARK) are desk-actionable — the inbox holds the
 * MARK. Confirm-outcome is NOT here (qrUrl/physical-QR-scan only → Slice 4c).
 * «Παραλήφθηκε» is a local-only worklist convenience.
 *
 * Each action resolves the service for the record's own company, runs inside
 * try/catch, surfaces a Greek success/danger notification (never a 500), then
 * refreshes.
 */
class ViewInboundDeliveryNote extends ViewRecord
{
    protected static string $resource = InboundDeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // «Απόρριψη» — RejectDeliveryNote by MARK. Available while not terminal.
            Action::make('reject')
                ->label('Απόρριψη')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (InboundDeliveryNote $record): bool => ! $record->isTerminal()
                    && ! $record->aadeIsTerminal()
                    && ! empty($record->mydata_mark))
                ->authorize(fn (InboundDeliveryNote $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Απόρριψη εισερχόμενης διακίνησης')
                ->modalDescription('Δηλώνεται στην ΑΑΔΕ η ΟΛΙΚΗ απόρριψη των ειδών του δελτίου. Το δελτίο περνά στην τελική κατάσταση «Απορρίφθηκε». Μη αναστρέψιμο.')
                ->modalSubmitActionLabel('Απόρριψη')
                ->schema([
                    Textarea::make('reason')
                        ->label('Αιτιολογία (προαιρετικό)')
                        ->rows(2),
                ])
                ->action(function (InboundDeliveryNote $record, array $data): void {
                    try {
                        $this->service()->reject($record, $data['reason'] ?? null);

                        Notification::make()
                            ->title('Το εισερχόμενο απορρίφθηκε')
                            ->body('Δηλώθηκε η απόρριψη στην ΑΑΔΕ.')
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        $this->actionError($e);
                    }
                }),

            // «Έλεγχος κατάστασης» — RequestDeliveryNoteStatus by MARK (read-only).
            Action::make('refresh_status')
                ->label('Έλεγχος κατάστασης (ΑΑΔΕ)')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (InboundDeliveryNote $record): bool => ! empty($record->mydata_mark))
                // Gated on UPDATE (not view): refresh WRITES — it persists the AADE
                // status/lifecycle and can flip local_state to a terminal
                // cancelled_by_issuer. Same class as the «Λήψη νέων» fetch action.
                ->authorize(fn (InboundDeliveryNote $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->action(function (InboundDeliveryNote $record): void {
                    try {
                        $result = $this->service()->refreshStatus($record);

                        Notification::make()
                            ->title('Κατάσταση ΑΑΔΕ: '.($result['label'] ?? '—'))
                            ->body(($result['changed'] ?? false) ? 'Η κατάσταση ενημερώθηκε.' : 'Καμία αλλαγή.')
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        $this->actionError($e);
                    }
                }),

            // «Παραλήφθηκε» — local-only acknowledge (no AADE call). Only from `new`.
            Action::make('acknowledge')
                ->label('Παραλήφθηκε')
                ->icon('heroicon-o-check')
                ->color('info')
                ->visible(fn (InboundDeliveryNote $record): bool => $record->local_state === InboundDeliveryNote::STATE_NEW)
                ->authorize(fn (InboundDeliveryNote $record): bool => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Επισήμανση ως παραληφθέν')
                ->modalDescription('Τοπική ένδειξη ότι το εισερχόμενο ελέγχθηκε/παραλήφθηκε. Καμία αλλαγή στην ΑΑΔΕ.')
                ->modalSubmitActionLabel('Παραλήφθηκε')
                ->action(function (InboundDeliveryNote $record): void {
                    try {
                        $this->service()->acknowledge($record);

                        Notification::make()->title('Επισημάνθηκε ως παραληφθέν')->success()->send();

                        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        $this->actionError($e);
                    }
                }),
        ];
    }

    /**
     * Resolve the service for the ACTING tenant (Filament::getTenant()), not the
     * record's own company — so InboundDeliveryService::assertTenant is a real
     * backstop (verifies the row belongs to the tenant we're acting as) and the
     * AADE call runs under the acting tenant's credentials. Refuses (→ danger
     * notification via the callers' try/catch) if there's no tenant context.
     */
    private function service(): InboundDeliveryService
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            throw new RuntimeException('Δεν έχει επιλεγεί εταιρεία.');
        }

        return new InboundDeliveryService($tenant);
    }

    private function actionError(Throwable $e): void
    {
        Notification::make()
            ->title('Η ενέργεια απέτυχε')
            ->body($e->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
