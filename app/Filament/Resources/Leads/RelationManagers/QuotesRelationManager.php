<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Quotes\QuoteResource;
use App\Models\Lead;
use App\Models\Quote;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Προσφορές issued to this lead (Leads L1). Read-only here — a quote is
 * created/edited on its own pages; «Νέα προσφορά» opens the quote form
 * pre-filled from the lead (CreateQuote::afterFill).
 */
class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $title = 'Προσφορές';

    protected static ?string $recordTitleAttribute = 'code';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Κωδικός')
                    ->weight('medium'),

                TextColumn::make('subject')
                    ->label('Θέμα')
                    ->placeholder('—')
                    ->limit(60)
                    ->wrap(),

                TextColumn::make('status')
                    ->label('Κατάσταση')
                    ->badge(),

                TextColumn::make('issued_at')
                    ->label('Ημερομηνία')
                    ->date('d/m/Y'),

                TextColumn::make('gross_total')
                    ->label('Σύνολο')
                    ->money('EUR')
                    ->alignEnd(),

                TextColumn::make('customer.name')
                    ->label('Πελάτης')
                    ->placeholder('— (ακόμα lead)'),
            ])
            ->headerActions([
                Action::make('newQuote')
                    ->label('Νέα προσφορά')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => ! $this->getOwnerRecord()->isConverted())
                    ->url(fn (): string => QuoteResource::getUrl('create', ['lead' => $this->getOwnerRecord()->getKey()])),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Άνοιγμα')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Quote $record): string => QuoteResource::getUrl('view', ['record' => $record])),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Καμία προσφορά')
            ->emptyStateDescription('«Νέα προσφορά» ανοίγει τη φόρμα προσφοράς με τα στοιχεία του lead.');
    }

    public function getOwnerRecord(): Lead
    {
        /** @var Lead $record */
        $record = parent::getOwnerRecord();

        return $record;
    }
}
