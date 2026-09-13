<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\Cmr\CmrResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Services\Cmr\CreateCmrFromSource;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\Delivery\DeliveryNotePdf;
use App\Services\Delivery\DeliveryNoteSubmitter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use RuntimeException;
use Throwable;

/**
 * View a Δελτίο Αποστολής + its lines + the myDATA / delivery state, with the
 * issue action.
 *
 * «Έκδοση» files a DRAFT note via DeliveryNoteSubmitter through the tenant's
 * electronic channel (direct myDATA or certified provider, 9.x type). Visible
 * only while local_status === 'draft' and the
 * note has not yet been filed (mydata_state is null). On success it shows the
 * MARK + QR URL; on RuntimeException the submitter's (Greek) message surfaces as
 * a danger notification.
 *
 * The e-transport lifecycle (D3, Β' φάση) rides ON TOP of a filed note via
 * DeliveryLifecycleService: «Έναρξη διακίνησης» (RegisterTransfer), «Δήλωση
 * παράδοσης» (ConfirmDeliveryOutcome), «Έλεγχος κατάστασης (ΑΑΔΕ)»
 * (RequestDeliveryNoteStatus) and «Ακύρωση» (CancelInvoice by MARK). Each
 * resolves the service for the record's own company, runs inside try/catch and
 * surfaces a Greek success/danger notification (never a 500), then refreshes.
 */
class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        $tenant = Filament::getTenant();
        $tenantSupportsElectronic = (bool) $tenant?->submitsElectronically();
        $isProviderChannel = (bool) $tenant?->isLiveProviderTenant();
        $channelLabel = $tenant?->einvoiceChannelLabel() ?? 'myDATA';

        return [
            // «Δημιουργία CMR» — international consignment note for this note's goods
            // (cross-border). Pre-fills a DRAFT CMR (Greek→Latin) the operator edits
            // to English, then prints. NOT a myDATA document.
            Action::make('create_cmr')
                ->label('Δημιουργία CMR')
                ->icon('heroicon-o-clipboard-document-list')
                ->color('gray')
                ->visible(fn () => auth()->user()?->can('View:CmrNote') ?? false)
                ->requiresConfirmation()
                ->modalHeading('Δημιουργία CMR από το δελτίο')
                ->modalDescription('Δημιουργείται ΠΡΟΧΕΙΡΟ CMR στα Αγγλικά (μεταγραφή από τα ελληνικά). Διορθώστε το πριν την εκτύπωση.')
                ->action(function (DeliveryNote $record) {
                    $cmr = app(CreateCmrFromSource::class)->fromDeliveryNote($record);
                    Notification::make()->success()->title('Δημιουργήθηκε προσχέδιο CMR')->send();

                    return redirect(CmrResource::getUrl('edit', ['record' => $cmr]));
                }),

            Action::make('issue')
                ->label($isProviderChannel ? 'Έκδοση μέσω Παρόχου' : 'Έκδοση (διαβίβαση στο myDATA)')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                // Draft + never-filed only. The submitter also hard-guards an
                // already-filed/cancelled note, but visibility keeps the button
                // off the screen entirely once it's done.
                ->visible(fn (DeliveryNote $record) => $tenantSupportsElectronic
                    && $record->local_status === 'draft'
                    && $record->mydata_state === null)
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Αποστολή δελτίου — '.$channelLabel)
                ->modalDescription(fn () => $isProviderChannel
                    ? ('Αποστολή μέσω '.$channelLabel.'. Ο πάροχος υποβάλλει το παραστατικό διακίνησης στο myDATA και επιστρέφει MARK + QR. '
                        .(($tenant?->einvoice_provider_mode === 'production') ? '⚠ ΠΑΡΑΓΩΓΗ — πραγματική, νομικά δεσμευτική έκδοση.' : 'Δοκιμαστικό περιβάλλον.'))
                    : 'Το δελτίο αποστέλλεται στην ΑΑΔΕ (Παραστατικό Διακίνησης 9.x). Επιστρέφεται MARK· μετά την έκδοση το δελτίο κλειδώνει για επεξεργασία.')
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
                            ->title($record->company->isLiveProviderTenant() ? 'Το δελτίο εκδόθηκε μέσω παρόχου' : 'Το δελτίο εκδόθηκε στο myDATA')
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

            // ---- e-transport lifecycle (D3, Β' φάση) ----------------------

            // «Έναρξη διακίνησης» — RegisterTransfer. VALID + delivery_state=registered.
            Action::make('register_transfer')
                ->label('Έναρξη διακίνησης')
                ->icon('heroicon-o-truck')
                ->color('primary')
                ->visible(fn (DeliveryNote $record) => $record->mydata_state === 'VALID'
                    && $record->delivery_state === 'registered')
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Έναρξη διακίνησης (myDATA)')
                ->modalDescription('Δηλώνεται η παραλαβή των αγαθών και η έναρξη της διακίνησης. Το δελτίο περνά σε κατάσταση «Σε διακίνηση».')
                ->modalSubmitActionLabel('Έναρξη')
                ->action(fn (DeliveryNote $record) => $this->runLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->registerTransfer($record),
                    'Δηλώθηκε η έναρξη διακίνησης',
                )),

            // «Δήλωση παράδοσης» — ConfirmDeliveryOutcome. delivery_state=in_transit.
            Action::make('confirm_delivery')
                ->label('Δήλωση παράδοσης')
                ->icon('heroicon-o-check-badge')
                ->color('success')
                ->visible(fn (DeliveryNote $record) => $record->delivery_state === 'in_transit')
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->modalHeading('Δήλωση αποτελέσματος παράδοσης (myDATA)')
                ->modalSubmitActionLabel('Δήλωση')
                ->schema([
                    Select::make('outcome')
                        ->label('Αποτέλεσμα παράδοσης')
                        ->options([
                            'FULL' => 'Πλήρης παράδοση',
                            'PARTIAL' => 'Μερική παράδοση',
                            'NONE' => 'Καμία παράδοση',
                        ])
                        ->default('FULL')
                        ->required(),
                ])
                ->action(fn (DeliveryNote $record, array $data) => $this->runLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->confirmDelivery($record, $data['outcome'] ?? 'FULL'),
                    'Δηλώθηκε το αποτέλεσμα παράδοσης',
                )),

            // «Δήλωση επιστροφής» — ConfirmDeliveryReturn (myDATA v2.0.2 §3.2.7): ο
            // εκδότης κλείνει τη διακίνηση με επιστροφή. Πηγές (plain 9.3):
            // rejected/partial/failed (+ in_transit_return, carrier return leg)· το
            // in_transit αφαιρέθηκε — η ΑΑΔΕ το απορρίπτει [828] (sandbox 2026-09-13,
            // βλ. DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES).
            Action::make('confirm_return')
                ->label('Δήλωση επιστροφής')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (DeliveryNote $record) => in_array($record->delivery_state, DeliveryLifecycleService::CONFIRM_RETURN_FROM_STATES, true))
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Δήλωση επιστροφής (myDATA)')
                ->modalDescription('Δηλώνεται ότι ο μεταφορέας δεν παρέδωσε το σύνολο των αγαθών και τα επέστρεψε στον εκδότη. Η διακίνηση ολοκληρώνεται ως «Επιστράφηκε».')
                ->modalSubmitActionLabel('Δήλωση επιστροφής')
                ->action(fn (DeliveryNote $record) => $this->runLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->confirmReturn($record),
                    'Δηλώθηκε η επιστροφή',
                )),

            // «Έλεγχος κατάστασης (ΑΑΔΕ)» — RequestDeliveryNoteStatus (read-only).
            Action::make('refresh_status')
                ->label('Έλεγχος κατάστασης (ΑΑΔΕ)')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (DeliveryNote $record) => ! empty($record->mydata_mark))
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('view', $record) ?? false)
                ->action(function (DeliveryNote $record) {
                    try {
                        $svc = app(DeliveryLifecycleService::class, ['tenant' => $record->company]);
                        $result = $svc->refreshStatus($record);

                        $eventsLine = ($result['events_synced'] ?? 0) > 0
                            ? ' Ιστορικό διακίνησης: '.$result['events_synced'].' γεγονότα.'
                            : '';

                        if ($result['state_synced'] ?? false) {
                            // MYD-019: AADE reports the δελτίο CANCELLED — it was cancelled
                            // outside ekdosi and we synced ALL local state + returned stock.
                            Notification::make()
                                ->title('Το δελτίο ΑΚΥΡΩΘΗΚΕ στην ΑΑΔΕ')
                                ->body('Εντοπίστηκε ακύρωση εκτός ekdosi και συγχρονίστηκε: κατάσταση → Ακυρώθηκε '
                                    .'(τοπικά + myDATA), το απόθεμα επιστράφηκε. Δες το «Ιστορικό myDATA».'.$eventsLine)
                                ->warning()
                                ->persistent()
                                ->send();
                        } else {
                            $stateLine = $result['changed']
                                ? 'Η τοπική κατάσταση ενημερώθηκε.'
                                : 'Καμία αλλαγή — η τοπική κατάσταση συμφωνεί με την ΑΑΔΕ.';

                            Notification::make()
                                ->title('Κατάσταση ΑΑΔΕ: '.($result['aade_label'] ?? '—'))
                                ->body($stateLine.$eventsLine)
                                ->success()
                                ->send();
                        }

                        $this->refreshFormData(['delivery_state', 'mydata_state', 'local_status']);
                    } catch (Throwable $e) {
                        $this->lifecycleError($e);
                    }
                }),

            // «Ακύρωση» — CancelInvoice by the issue MARK. Filed + not cancelled.
            Action::make('cancel_delivery')
                ->label('Ακύρωση')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (DeliveryNote $record) => ! empty($record->mydata_mark)
                    && $record->mydata_state !== 'CANCELLED')
                ->authorize(fn (DeliveryNote $record) => auth()->user()?->can('update', $record) ?? false)
                ->requiresConfirmation()
                ->modalHeading('Ακύρωση δελτίου στο myDATA')
                ->modalDescription('Το δελτίο ακυρώνεται στην ΑΑΔΕ. Η ενέργεια είναι μη αναστρέψιμη.')
                ->modalSubmitActionLabel('Επιβεβαίωση ακύρωσης')
                ->schema([
                    Textarea::make('reason')
                        ->label('Αιτιολογία (προαιρετικό)')
                        ->rows(2),
                ])
                ->action(fn (DeliveryNote $record, array $data) => $this->runLifecycle(
                    $record,
                    fn (DeliveryLifecycleService $svc) => $svc->cancel($record, (string) ($data['reason'] ?? '')),
                    'Το δελτίο ακυρώθηκε στο myDATA',
                )),

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

    /**
     * Run a lifecycle call (resolved for the record's own company), show a Greek
     * success notification, and refresh the view. Any RuntimeException/Throwable
     * from the service surfaces as a persistent danger notification (no 500).
     *
     * @param  callable(DeliveryLifecycleService):mixed  $call
     */
    private function runLifecycle(DeliveryNote $record, callable $call, string $successTitle): void
    {
        try {
            $svc = app(DeliveryLifecycleService::class, ['tenant' => $record->company]);
            $call($svc);

            Notification::make()
                ->title($successTitle)
                ->body('Κατάσταση: '.(DeliveryLifecycleService::stateLabel($record->fresh()->delivery_state) ?? '—'))
                ->success()
                ->send();

            $this->redirect(static::getResource()::getUrl('view', [
                'record' => $record,
                'tenant' => $record->company,
            ]));
        } catch (Throwable $e) {
            $this->lifecycleError($e);
        }
    }

    private function lifecycleError(Throwable $e): void
    {
        Notification::make()
            ->title('Η ενέργεια απέτυχε')
            ->body($e->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
