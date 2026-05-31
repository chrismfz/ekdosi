<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Στοιχεία εξόδου')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('supplier_name')->label('Προμηθευτής'),
                        TextEntry::make('supplier_afm')->label('ΑΦΜ'),
                        TextEntry::make('invoice_type')->label('Τύπος'),
                        TextEntry::make('series')->label('Σειρά'),
                        TextEntry::make('aa')->label('ΑΑ'),
                        TextEntry::make('issue_date')->label('Ημερομηνία')->date(),
                        TextEntry::make('net_total')->label('Καθαρή')->money('EUR'),
                        TextEntry::make('vat_total')->label('ΦΠΑ')->money('EUR'),
                        TextEntry::make('gross_total')->label('Σύνολο')->money('EUR'),
                        TextEntry::make('mydata_mark')->label('MARK')->copyable(),
                        // Self-declared bucket (πάγια/μισθοδοσία/ενδοκοινοτικά…);
                        // blank for supplier docs.
                        TextEntry::make('category')
                            ->label('Κατηγορία')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => $state === null
                                ? '—'
                                : (\App\Support\MyData\Codes::selfDeclaredVatCategoryLabel($state) ?? $state)),
                    ]),

                RepeatableEntry::make('lines')
                    ->label('Γραμμές')
                    ->schema([
                        TextEntry::make('line_number')->label('Γραμμή'),
                        // myDATA omits free-text on most expense docs, so this is
                        // often empty — the raw XML below shows the full line.
                        TextEntry::make('item_descr')
                            ->label('Περιγραφή')
                            ->placeholder('— (η ΑΑΔΕ δεν στέλνει περιγραφή)'),
                        TextEntry::make('quantity')
                            ->label('Ποσότητα')
                            ->placeholder('—'),
                        TextEntry::make('vat_category')->label('Κατ. ΦΠΑ')->placeholder('—'),
                        TextEntry::make('net_value')->label('Καθαρή')->money('EUR'),
                        TextEntry::make('vat_amount')->label('ΦΠΑ')->money('EUR'),
                    ]),

                // Raw myDATA XML for cross-referencing what AADE actually sent vs.
                // what we parsed/stored. Sourced from the stored expense mark
                // (expense_marks.response = $doc->toXml()); collapsed by default.
                Section::make('myDATA XML (διασταύρωση)')
                    ->description('Το ακριβές XML που λήφθηκε από την ΑΑΔΕ για αυτό το παραστατικό.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        TextEntry::make('mydata_xml')
                            ->hiddenLabel()
                            ->state(fn (Expense $record): string => self::latestMarkXml($record))
                            ->copyable()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * The raw response XML of the most recent mark for this expense, or a
     * friendly placeholder when none was stored (e.g. a manually-created row).
     */
    private static function latestMarkXml(Expense $record): string
    {
        $xml = $record->marks()->latest('id')->value('response');

        return is_string($xml) && trim($xml) !== ''
            ? $xml
            : 'Δεν υπάρχει αποθηκευμένο XML για αυτό το έξοδο.';
    }
}
