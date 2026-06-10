<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Models\Expense;
use App\Support\MyData\Codes;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Read-only display of a single Expense (supplier doc OR a self-declared doc).
 * Header from the myDATA document; lines from the imported `expense_lines`; the
 * raw doc XML at the bottom for cross-referencing what AADE actually sent.
 */
class ExpenseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Παραστατικό')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('invoice_type')->label('Τύπος')->badge()->placeholder('—'),
                        TextEntry::make('series')->label('Σειρά')->placeholder('—'),
                        TextEntry::make('aa')->label('ΑΑ')->placeholder('—'),
                        TextEntry::make('issue_date')->label('Ημ/νία έκδοσης')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('mydata_mark')->label('ΜΑΡΚ')->copyable()->placeholder('—'),
                        TextEntry::make('mydata_state')
                            ->label('Κατάσταση myDATA')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'CANCELLED' ? 'danger' : 'success')
                            ->placeholder('—'),
                        // Self-declared bucket (πάγια/μισθοδοσία/ενδοκοινοτικά…);
                        // blank for supplier docs.
                        TextEntry::make('category')
                            ->label('Κατηγορία')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?string $state): string => $state === null
                                ? '—'
                                : (Codes::selfDeclaredVatCategoryLabel($state) ?? $state)),
                    ]),

                Section::make('Προμηθευτής / Αντισυμβαλλόμενος')
                    ->columns(2)
                    ->schema([
                        // Prefer the synced Supplier row's name (GSIS-enriched);
                        // myDATA strips the GR issuer name on import, so the
                        // denormalised supplier_name is often blank. Fall back to it.
                        TextEntry::make('supplier.name')
                            ->label('Επωνυμία')
                            ->placeholder('—')
                            ->default(fn (Expense $record): ?string => $record->supplier_name),
                        TextEntry::make('supplier_afm')->label('ΑΦΜ')->placeholder('—'),
                    ]),

                Section::make('Σύνολα')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('net_total')->label('Καθαρή αξία')->money('EUR'),
                        TextEntry::make('vat_total')->label('ΦΠΑ')->money('EUR'),
                        TextEntry::make('gross_total')->label('Σύνολο')->money('EUR')->weight('bold'),
                    ]),

                // Header E5 classification (set by the ViewExpense «Χαρακτηρισμός»
                // action). Surfaced here so the operator can read back what they
                // assigned — the per-line classification below is a separate field.
                Section::make('Χαρακτηρισμός')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('classification_type')
                            ->label('Τύπος (E3)')
                            ->placeholder('— (αχαρακτήριστο)')
                            ->state(fn (\App\Models\Expense $record): ?string => $record->classificationIsMixed()
                                ? 'Μικτός — βλ. ανά γραμμή'
                                : ($record->classification_type === null ? null
                                    : trim($record->classification_type.' — '.(Codes::e3TypeLabel($record->classification_type) ?? ''), ' —'))),
                        TextEntry::make('classification_category')
                            ->label('Κατηγορία')
                            ->placeholder('—')
                            ->state(fn (\App\Models\Expense $record): ?string => $record->classificationIsMixed()
                                ? 'Μικτός — βλ. ανά γραμμή'
                                : ($record->classification_category === null ? null
                                    : trim($record->classification_category.' — '.(Codes::e3CategoryLabel($record->classification_category) ?? ''), ' —'))),
                        TextEntry::make('classification_state')
                            ->label('Κατάσταση')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): string => \App\Models\Expense::classificationStateLabel($state))
                            ->color(fn (?string $state): string => \App\Models\Expense::classificationStateColor($state)),
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
