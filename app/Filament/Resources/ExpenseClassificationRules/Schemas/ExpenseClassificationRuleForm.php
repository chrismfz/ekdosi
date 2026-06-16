<?php

namespace App\Filament\Resources\ExpenseClassificationRules\Schemas;

use App\Models\Supplier;
use App\Support\MyData\Codes;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ExpenseClassificationRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('supplier_afm')
                    ->label('Προμηθευτής (ΑΦΜ)')
                    ->options(fn (): array => Supplier::query()
                        ->whereNotNull('afm')
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Supplier $s) => [$s->afm => trim(($s->name ?: '—').' — '.$s->afm)])
                        ->all())
                    ->searchable()
                    ->required()
                    ->helperText('Ο προμηθευτής στον οποίο εφαρμόζεται ο κανόνας.'),

                Select::make('invoice_type')
                    ->label('Τύπος παραστατικού (προαιρετικό)')
                    ->options(Codes::INVOICE_TYPES)
                    ->searchable()
                    ->placeholder('Όλοι οι τύποι')
                    ->helperText('Άφησέ το κενό για όλους τους τύπους του προμηθευτή· όρισέ το για πιο ειδικό κανόνα.'),

                Select::make('classification_type')
                    ->label('Τύπος χαρακτηρισμού (E3)')
                    ->options(Codes::expenseClassTypeOptions())
                    ->searchable()
                    ->required(),

                Select::make('classification_category')
                    ->label('Κατηγορία χαρακτηρισμού')
                    ->options(Codes::expenseClassCategoryOptions())
                    ->searchable()
                    ->required(),

                TextInput::make('label')
                    ->label('Σημείωση')
                    ->maxLength(120),

                TextInput::make('priority')
                    ->label('Προτεραιότητα')
                    ->numeric()
                    ->default(0)
                    ->required()   // integer NOT NULL — an empty submit must not reach the DB
                    ->helperText('Μεγαλύτερη = υπερισχύει όταν ταιριάζουν πολλοί κανόνες.'),

                Toggle::make('is_active')
                    ->label('Ενεργός')
                    ->default(true),
            ]);
    }
}
