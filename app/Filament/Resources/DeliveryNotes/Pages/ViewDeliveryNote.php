<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Services\Delivery\DeliveryNotePdf;
use App\Services\Delivery\DeliveryNoteSubmitter;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Throwable;

/**
 * View a Δελτίο Αποστολής + its lines + the myDATA / delivery state, with the
 * issue action.
 *
 * «Έκδοση (διαβίβαση στο myDATA)» files a DRAFT note via DeliveryNoteSubmitter
 * (SendInvoices, 9.x type). Visible only while local_status === 'draft' and the
 * note has not yet been filed (mydata_state is null). On success it shows the
 * MARK + QR URL; on RuntimeException the submitter's (Greek) message surfaces as
 * a danger notification.
 *
 * The e-transport lifecycle actions (RegisterTransfer / ConfirmDeliveryOutcome /
 * Reject) are intentionally NOT built here — that is D3, a separate task.
 */
class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Έκδοση (διαβίβαση στο myDATA)')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                // Draft + never-filed only. The submitter also hard-guards an
                // already-filed/cancelled note, but visibility keeps the button
                // off the screen entirely once it's done.
                ->visible(fn (DeliveryNote $record) => $record->local_status === 'draft'
                    && $record->mydata_state === null)
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Διαβίβαση δελτίου στο myDATA')
                ->modalDescription('Το δελτίο αποστέλλεται στην ΑΑΔΕ (Παραστατικό Διακίνησης 9.x). Επιστρέφεται MARK· μετά την έκδοση το δελτίο κλειδώνει για επεξεργασία.')
                ->modalSubmitActionLabel('Έκδοση')
                ->action(function (DeliveryNote $record) {
                    try {
                        // Resolve the tenant from the record's own company FK
                        // (always present) — Filament::getTenant() is nullable
                        // outside a tenant-bound page context, and the submitter
                        // ctor takes a non-nullable Company.
                        $submitter = app(DeliveryNoteSubmitter::class, ['tenant' => $record->company]);
                        $mark = $submitter->submit($record);

                        Notification::make()
                            ->title('Το δελτίο εκδόθηκε στο myDATA')
                            ->body('MARK: '.($mark->mark ?? 'pending')
                                .($record->fresh()->mydata_url ? ' — δες το QR στη σελίδα.' : ''))
                            ->success()
                            ->send();

                        $this->redirect(static::getResource()::getUrl('view', [
                            'record' => $record,
                            'tenant' => $record->company,
                        ]));
                    } catch (RuntimeException $e) {
                        Notification::make()
                            ->title('Η έκδοση απέτυχε')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Η έκδοση απέτυχε')
                            ->body($e->getMessage())
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            // PDF print. Works for any note regardless of state — a draft
            // prints with the «ΠΡΟΧΕΙΡΟ» marker and no QR; a filed (VALID)
            // note carries the MARK + QR. Mirrors ViewInvoice::download_pdf:
            // render UP-FRONT so an error surfaces as a Filament notification
            // instead of a half-streamed corrupt download.
            Action::make('print_pdf')
                ->label('Εκτύπωση (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('view', $record) ?? false)
                ->action(function (DeliveryNote $record) {
                    $pdfBytes = app(DeliveryNotePdf::class)->render($record);

                    return response()->streamDownload(
                        function () use ($pdfBytes): void {
                            echo $pdfBytes;
                        },
                        'deltio-'.$record->invcode.'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }
}
