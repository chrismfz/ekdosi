<?php

namespace App\Filament\Resources\ServiceContracts\Pages;

use App\Actions\StageServiceRenewal;
use App\Enums\ServiceContractStatus;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Resources\ServiceContracts\ServiceContractResource;
use App\Models\Invoice;
use App\Models\ServiceContract;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Throwable;

/**
 * View + lifecycle for a service contract. Each transition action is gated by
 * ServiceContractStatus::canTransitionTo() (the single source for what's
 * allowed) AND mutates only the lifecycle columns — never money. Cancellation
 * and termination CASCADE to the contract's UNISSUED draft renewals (drafts
 * with no MARK): those get local_status='cancelled'. A renewal that already
 * has a MARK or is no longer a draft is a legal document the operator handles
 * separately and is left untouched.
 */
class ViewServiceContract extends ViewRecord
{
    protected static string $resource = ServiceContractResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->schema([
                    TextEntry::make('customer.name')->label('Πελάτης'),
                    TextEntry::make('description')->label('Περιγραφή'),
                    TextEntry::make('billing_cycle')
                        ->label('Κύκλος')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state?->label()),
                    TextEntry::make('status')
                        ->label('Κατάσταση')
                        ->badge()
                        ->formatStateUsing(fn (ServiceContractStatus $state) => $state->label())
                        ->color(fn (ServiceContractStatus $state) => $state->color()),
                    TextEntry::make('amount')->label('Ποσό (καθαρό)')->money('EUR'),
                    TextEntry::make('vat_percent')->label('ΦΠΑ %')->suffix('%'),
                    TextEntry::make('invoiceType.code')->label('Τύπος ανανέωσης')->placeholder('— (δεν έχει οριστεί)'),
                    TextEntry::make('next_due_date')->label('Επόμενη χρέωση')->date('Y-m-d'),
                    TextEntry::make('start_date')->label('Έναρξη')->date('Y-m-d'),
                    TextEntry::make('end_date')->label('Λήξη')->date('Y-m-d'),
                    TextEntry::make('last_invoiced_at')->label('Τελευταία χρέωση')->dateTime('Y-m-d H:i'),
                    TextEntry::make('server.name')->label('Server')->placeholder('—'),
                    TextEntry::make('domain')->label('Domain')->placeholder('—'),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('Επεξεργασία'),

            // Ενεργοποίηση (Pending → Active). If no next_due_date set, seed
            // it from start_date or today so the contract starts billing.
            Action::make('activate')
                ->label('Ενεργοποίηση')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Active)
                    && $record->status === ServiceContractStatus::Pending)
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'next_due_date' => $record->next_due_date
                            ?? $record->start_date
                            ?? today(),
                    ]);
                    Notification::make()->title('Η υπηρεσία ενεργοποιήθηκε')->success()->send();
                    $this->redirectToView($record);
                }),

            // Αναστολή (Active → Suspended).
            Action::make('suspend')
                ->label('Αναστολή')
                ->icon('heroicon-o-pause')
                ->color('warning')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Suspended))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Suspended,
                        'suspended_at' => now(),
                    ]);
                    Notification::make()->title('Η υπηρεσία τέθηκε σε αναστολή')->success()->send();
                    $this->redirectToView($record);
                }),

            // Επαναφορά λειτουργίας (Suspended → Active).
            Action::make('unsuspend')
                ->label('Επαναφορά λειτουργίας')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Suspended
                    && $record->status->canTransitionTo(ServiceContractStatus::Active))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'suspended_at' => null,
                        'dunning_suspended_at' => null,
                    ]);
                    Notification::make()->title('Η υπηρεσία επανήλθε σε λειτουργία')->success()->send();
                    $this->redirectToView($record);
                }),

            // Ακύρωση (→ Cancelled). Clears the billing cursor + cascades to
            // unissued draft renewals.
            Action::make('cancel')
                ->label('Ακύρωση')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Cancelled))
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση υπηρεσίας')
                ->modalDescription('Σταματά η χρέωση. Τυχόν πρόχειρα παραστατικά ανανέωσης (χωρίς ΜΑΡΚ) ακυρώνονται. Εκδομένα/υποβληθέντα παραστατικά ΔΕΝ θίγονται.')
                ->schema([
                    Textarea::make('reason')->label('Αιτία (προαιρετικό)')->rows(2),
                ])
                ->action(function (ServiceContract $record, array $data) {
                    $cancelled = static::cancelUnissuedDrafts($record);
                    $record->update([
                        'status' => ServiceContractStatus::Cancelled,
                        'next_due_date' => null,
                        'cancel_reason' => $data['reason'] ?? null,
                    ]);
                    static::notifyCascade('Η υπηρεσία ακυρώθηκε', $cancelled);
                    $this->redirectToView($record);
                }),

            // Τερματισμός (→ Terminated, terminal). Stamps terminated_at +
            // same draft cascade.
            Action::make('terminate')
                ->label('Τερματισμός')
                ->icon('heroicon-o-stop-circle')
                ->color('danger')
                ->visible(fn (ServiceContract $record) => $record->status->canTransitionTo(ServiceContractStatus::Terminated))
                ->requiresConfirmation()
                ->modalHeading('Τερματισμός υπηρεσίας')
                ->modalDescription('Οριστικός τερματισμός (δεν επαναφέρεται). Τυχόν πρόχειρα παραστατικά ανανέωσης (χωρίς ΜΑΡΚ) ακυρώνονται.')
                ->action(function (ServiceContract $record) {
                    $cancelled = static::cancelUnissuedDrafts($record);
                    $record->update([
                        'status' => ServiceContractStatus::Terminated,
                        'next_due_date' => null,
                        'terminated_at' => now(),
                    ]);
                    static::notifyCascade('Η υπηρεσία τερματίστηκε', $cancelled);
                    $this->redirectToView($record);
                }),

            // Επαναφορά (Cancelled → Active revive).
            Action::make('revive')
                ->label('Επαναφορά')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Cancelled
                    && $record->status->canTransitionTo(ServiceContractStatus::Active))
                ->requiresConfirmation()
                ->action(function (ServiceContract $record) {
                    $record->update([
                        'status' => ServiceContractStatus::Active,
                        'cancel_reason' => null,
                        // Cancel nulled the cursor — reseed it (no surprise
                        // back-bill) so the revived service bills again from
                        // here. Mirrors «Ενεργοποίηση».
                        'next_due_date' => $record->next_due_date
                            ?? $record->start_date
                            ?? now()->toDateString(),
                    ]);
                    Notification::make()->title('Η υπηρεσία επανήλθε')->success()->send();
                    $this->redirectToView($record);
                }),

            // «Δημιουργία παραστατικού τώρα» — manual bill-now. Stages a draft
            // renewal immediately via the same action the scheduler will use.
            Action::make('bill_now')
                ->label('Δημιουργία παραστατικού τώρα')
                ->icon('heroicon-o-document-plus')
                ->color('primary')
                ->visible(fn (ServiceContract $record) => $record->status === ServiceContractStatus::Active)
                ->requiresConfirmation()
                ->modalHeading('Δημιουργία πρόχειρου παραστατικού ανανέωσης')
                ->modalDescription('Δημιουργείται πρόχειρο παραστατικό για τον τρέχοντα κύκλο. Δεν υποβάλλεται στο myDATA — εκδίδετε εσείς από τα Παραστατικά.')
                ->action(function (ServiceContract $record) {
                    try {
                        $invoice = app(StageServiceRenewal::class)($record);
                        if ($invoice === null) {
                            Notification::make()
                                ->title('Δεν δημιουργήθηκε παραστατικό')
                                ->body('Δεν υπάρχει εκκρεμής χρέωση για τον τρέχοντα κύκλο (ή υπάρχει ήδη ανοιχτό πρόχειρο).')
                                ->warning()->send();

                            return;
                        }
                        Notification::make()
                            ->title('Δημιουργήθηκε πρόχειρο παραστατικό')
                            ->body('Κωδικός: '.$invoice->invcode.' — εκδώστε το από τα Παραστατικά.')
                            ->success()->send();
                        $this->redirect(InvoiceResource::getUrl('view', ['record' => $invoice, 'tenant' => $record->company]));
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Αποτυχία δημιουργίας παραστατικού')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),
        ];
    }

    /**
     * Cascade: cancel the contract's UNISSUED draft renewals (draft +
     * no MARK). Returns the number cancelled. Legal documents (MARK'd or
     * non-draft) are left untouched. Explicit company_id scope for safety.
     */
    public static function cancelUnissuedDrafts(ServiceContract $record): int
    {
        return Invoice::query()
            ->where('company_id', $record->company_id)
            ->where('service_contract_id', $record->id)
            ->where('local_status', 'draft')
            ->whereNull('mydata_mark')
            ->update(['local_status' => 'cancelled']);
    }

    protected static function notifyCascade(string $title, int $cancelled): void
    {
        $body = $cancelled > 0
            ? "Ακυρώθηκαν $cancelled πρόχειρα παραστατικά ανανέωσης."
            : 'Δεν υπήρχαν πρόχειρα παραστατικά ανανέωσης προς ακύρωση.';
        Notification::make()->title($title)->body($body)->success()->send();
    }

    protected function redirectToView(ServiceContract $record): void
    {
        $this->redirect(static::getResource()::getUrl('view', ['record' => $record, 'tenant' => $record->company]));
    }
}
