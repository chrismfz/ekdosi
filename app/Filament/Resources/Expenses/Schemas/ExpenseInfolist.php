<?php

namespace App\Filament\Resources\Expenses\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only display of a single Expense (supplier doc). Header fields come
 * from the myDATA RequestDocs document; the lines are shown via the
 * LinesRelationManager on the view page.
 */
class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Παραστατικό')
                    ->schema([
                        TextEntry::make('invoice_type')
                            ->label('Τύπος')
                            ->placeholder('—'),
                        TextEntry::make('series')
                            ->label('Σειρά')
                            ->placeholder('—'),
                        TextEntry::make('aa')
                            ->label('ΑΑ')
                            ->placeholder('—'),
                        TextEntry::make('issue_date')
                            ->label('Ημ/νία έκδοσης')
                            ->date('d/m/Y')
                            ->placeholder('—'),
                        TextEntry::make('mydata_mark')
                            ->label('ΜΑΡΚ')
                            ->copyable()
                            ->placeholder('—'),
                        TextEntry::make('mydata_state')
                            ->label('Κατάσταση myDATA')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'CANCELLED' ? 'danger' : 'success')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Προμηθευτής')
                    ->schema([
                        TextEntry::make('supplier.name')
                            ->label('Επωνυμία')
                            ->placeholder('—'),
                        TextEntry::make('supplier_afm')
                            ->label('ΑΦΜ')
                            ->placeholder('—'),
                    ])
                    ->columns(2),

                Section::make('Σύνολα')
                    ->schema([
                        TextEntry::make('net_total')
                            ->label('Καθαρή αξία')
                            ->money('EUR'),
                        TextEntry::make('vat_total')
                            ->label('ΦΠΑ')
                            ->money('EUR'),
                        TextEntry::make('gross_total')
                            ->label('Σύνολο')
                            ->money('EUR')
                            ->weight('bold'),
                    ])
                    ->columns(3),
            ]);
    }
}
