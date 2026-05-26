<?php

namespace App\Filament\Resources\DistributionAims\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class DistributionAimForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('description')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull()
                    ->helperText('Σκοπός διακίνησης — e.g. "Πώληση", "Δείγμα", "Ενδοδιακίνηση".'),
            ]);
    }
}
