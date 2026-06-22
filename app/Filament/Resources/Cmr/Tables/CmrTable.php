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
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
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
                    ->action(fn (CmrNote $record) => response()->streamDownload(
                        fn () => print (app(CmrPdf::class)->render($record)),
                        'cmr-'.$record->code().'.pdf',
                        ['Content-Type' => 'application/pdf'],
                    )),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
