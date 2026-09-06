<?php

namespace App\Filament\Resources\CannedReplies\Schemas;

use App\Models\CannedReplyCategory;
use App\Support\CannedReplyExpander;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CannedReplyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('canned_reply_category_id')
                    ->label('Κατηγορία')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        TextInput::make('name')->label('Όνομα κατηγορίας')->required()->maxLength(120),
                    ])
                    ->createOptionUsing(fn (array $data): int => CannedReplyCategory::create([
                        'company_id' => Filament::getTenant()?->getKey(),
                        'name' => $data['name'],
                    ])->getKey()),
                TextInput::make('title')
                    ->label('Τίτλος')
                    ->required()
                    ->maxLength(160),
                Toggle::make('is_active')
                    ->label('Ενεργή')
                    ->default(true),
                Textarea::make('body')
                    ->label('Κείμενο')
                    ->required()
                    ->rows(8)
                    ->columnSpanFull()
                    ->helperText('Placeholders που αντικαθίστανται στην απάντηση: '.implode(' · ', CannedReplyExpander::tokens())),
                TextInput::make('sort')
                    ->label('Σειρά')
                    ->numeric()
                    ->default(0),
            ]);
    }
}
