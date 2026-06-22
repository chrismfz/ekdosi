<?php

namespace App\Filament\Resources\Cmr\Tables;

use App\Models\CmrNote;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\Cmr\CmrPdf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CmrTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_no')->label('Reference')->searchable()->sortable(),
                TextColumn::make('consignee_text')->label('Παραλήπτης')->limit(40)->wrap(),
                TextColumn::make('source_type')->label('Πηγή')
                    // Check source_id too: an imported CMR keeps source_type but its
                    // source_id is nulled on restore → that's a Standalone now.
                    ->formatStateUsing(fn (?string $state, CmrNote $record): string => $record->source_id === null
                        ? 'Standalone'
                        : match ($state) {
                            Invoice::class => 'Τιμολόγιο',
                            DeliveryNote::class => 'Δελτίο Αποστ.',
                            default => 'Standalone',
                        })
                    ->badge(),
                TextColumn::make('status')->label('Κατάσταση')->badge()
                    ->color(fn (string $state): string => $state === CmrNote::STATUS_FINALIZED ? 'success' : 'gray'),
                TextColumn::make('issued_at')->label('Ημ/νία')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                Action::make('print_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-document-arrow-down')
                    ->authorize(fn (CmrNote $record) => auth()->user()?->can('view', $record) ?? false)
                    ->action(function (CmrNote $record) {
                        $bytes = app(CmrPdf::class)->render($record);
                        $record->markPrinted();

                        return response()->streamDownload(
                            fn () => print ($bytes),
                            'cmr-'.$record->code().'.pdf',
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
