<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Support\MyData\Codes;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Manual expense entry (Expenses polish) — for a supplier document that is NOT
 * in myDATA (foreign supplier, cash receipt…). The myDATA-sourced expenses stay
 * read-only; this form drives only `source=manual` records (header totals are
 * recomputed from the lines by the Create/Edit pages, which also stamp
 * company_id + line_number — `BelongsToCompany` doesn't auto-fill those).
 */
class ExpenseForm
{
    /** @return array<int|string, string> §8.2 vat-category options. */
    private static function vatCategoryOptions(): array
    {
        return collect(Codes::VAT_CATEGORY_LABELS)
            ->mapWithKeys(fn (string $label, int $code): array => [$code => "{$code} — {$label}"])
            ->put(8, '8 — Εγγραφές χωρίς ΦΠΑ')
            ->all();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Στοιχεία παραστατικού')
                    ->columns(2)
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Προμηθευτής')
                            ->relationship('supplier', 'name')
                            ->searchable()
                            ->preload()
                            ->helperText('Από τους καταχωρημένους προμηθευτές. Αλλιώς συμπλήρωσε το όνομα δίπλα.'),
                        TextInput::make('supplier_name')
                            ->label('Όνομα προμηθευτή (αν δεν υπάρχει στη λίστα)')
                            ->maxLength(191),
                        DatePicker::make('issue_date')
                            ->label('Ημερομηνία')
                            ->required()
                            ->default(now()),
                        TextInput::make('invoice_type')
                            ->label('Τύπος (προαιρετικό)')
                            ->placeholder('π.χ. 1.1')
                            ->maxLength(10),
                        TextInput::make('series')->label('Σειρά')->maxLength(60),
                        TextInput::make('aa')->label('Α/Α')->maxLength(60),
                        TextInput::make('currency')
                            ->label('Νόμισμα')
                            ->default('EUR')
                            ->maxLength(3),
                    ]),

                Section::make('Γραμμές')
                    ->schema([
                        Repeater::make('lines')
                            ->label('')
                            ->addActionLabel('Προσθήκη γραμμής')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->columns(12)
                            ->schema([
                                TextInput::make('item_descr')
                                    ->label('Περιγραφή')
                                    ->required()
                                    ->columnSpan(5),
                                TextInput::make('quantity')
                                    ->label('Ποσότητα')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(1)
                                    ->columnSpan(1),
                                Select::make('vat_category')
                                    ->label('ΦΠΑ (§8.2)')
                                    ->options(self::vatCategoryOptions())
                                    ->default(1)
                                    ->required()
                                    ->columnSpan(2),
                                TextInput::make('net_value')
                                    ->label('Καθαρή αξία')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->prefix('€')
                                    ->columnSpan(2),
                                TextInput::make('vat_amount')
                                    ->label('ΦΠΑ')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required()
                                    ->prefix('€')
                                    ->columnSpan(2),
                            ]),
                    ]),

                Section::make('Λοιπά')
                    ->schema([
                        FileUpload::make('document_path')
                            ->label('Παραστατικό (PDF/εικόνα)')
                            ->disk('local')
                            ->directory('expense-documents')
                            ->visibility('private')
                            ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                            ->maxSize(10240)
                            ->helperText('Ιδιωτικό αρχείο — η λήψη γίνεται μόνο με υπογεγραμμένο σύνδεσμο.'),
                        Textarea::make('notes')->label('Σημειώσεις')->rows(2),
                    ]),
            ]);
    }
}
