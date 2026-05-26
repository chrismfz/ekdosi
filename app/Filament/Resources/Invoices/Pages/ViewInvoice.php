<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\MyDataSubmitter;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

/**
 * Read-only view page. The Submit / Cancel actions land in PR #26
 * (IssueInvoice). The "Preview submission XML (dry-run)" action
 * lives here because it's safe regardless of mode — it never
 * touches AADE, just persists a DRY_RUN audit row with the would-be
 * XML so operators can inspect what the submitter would actually
 * send.
 */
class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dry_run_submit')
                ->label('Preview submission XML')
                ->icon('heroicon-o-eye')
                ->color('gray')
                // Only meaningful for Greek tenants. Non-myDATA
                // providers don't have a payload shape to preview.
                ->visible(fn () => Filament::getTenant()?->einvoice_provider === 'gr-mydata')
                ->requiresConfirmation()
                ->modalHeading('Preview the XML this invoice would send to myDATA')
                ->modalDescription('Builds the full AADE payload and records it as a DRY_RUN row in the myDATA history below. Does NOT contact AADE. Safe to click on any mode (off / sandbox / production).')
                ->modalSubmitActionLabel('Generate preview')
                ->action(function () {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        Notification::make()->title('No tenant context.')->warning()->send();
                        return;
                    }

                    try {
                        // Use MyDataSubmitter directly even if the factory
                        // would return NullSubmitter for this tenant —
                        // the dry-run is exactly the case where we WANT
                        // to see the real payload regardless of mode.
                        // previewXml() is the always-dry-run method (split
                        // from submit() to eliminate the defaulted-arg
                        // footgun where a missing named arg would file).
                        $submitter = new MyDataSubmitter($tenant);
                        $submitter->previewXml($this->record);
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Dry-run failed')
                            ->body($e->getMessage())
                            ->danger()->send();
                        return;
                    }

                    Notification::make()
                        ->title('Dry-run recorded')
                        ->body('Open the new DRY_RUN row in the myDATA submission history (below) and click "Request XML" to see the payload.')
                        ->success()->send();

                    // Refresh the page so the RelationManager shows
                    // the new audit row.
                    $this->refreshFormData([]);
                }),
        ];
    }
}
