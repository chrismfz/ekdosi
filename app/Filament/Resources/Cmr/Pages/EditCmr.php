<?php

namespace App\Filament\Resources\Cmr\Pages;

use App\Filament\Resources\Cmr\CmrResource;
use App\Models\CmrNote;
use App\Services\Cmr\CmrPdf;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit a CMR (the editable draft — incl. the pre-filled one created from an
 * invoice / delivery note). Print is available any time (a CMR isn't fiscal, so
 * it's never locked); printing flips status→finalized + printed as a marker.
 */
class EditCmr extends EditRecord
{
    protected static string $resource = CmrResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print_pdf')
                ->label('Εκτύπωση CMR (PDF)')
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function () {
                    /** @var CmrNote $record */
                    $record = $this->getRecord();
                    $bytes = app(CmrPdf::class)->render($record);

                    if ($record->status === CmrNote::STATUS_DRAFT) {
                        $record->forceFill([
                            'status' => CmrNote::STATUS_FINALIZED,
                            'printed' => true,
                        ])->save();
                    }

                    return response()->streamDownload(
                        fn () => print ($bytes),
                        'cmr-'.$record->code().'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }
}
