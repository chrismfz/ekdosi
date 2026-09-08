<?php

namespace App\Filament\Resources\Tags\Schemas;

use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class TagForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Όνομα')
                    ->required()
                    ->maxLength(60)
                    // One vocabulary per tenant — no duplicate tag names.
                    ->unique(
                        table: 'tags',
                        column: 'name',
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Filament::getTenant()?->getKey()),
                    ),

                ColorPicker::make('color')
                    ->label('Χρώμα (προαιρετικό)'),

                Toggle::make('is_pinned')
                    ->label('Εμφάνιση ως καρτέλα (fast filter)')
                    ->helperText('Καρφιτσωμένη: εμφανίζεται ως γρήγορη καρτέλα πάνω από τις λίστες όπου χρησιμοποιείται.')
                    ->default(false),

                TextInput::make('sort_order')
                    ->label('Σειρά')
                    ->numeric()
                    ->default(0)
                    ->helperText('Μικρότερο = πρώτο.'),
            ]);
    }
}
