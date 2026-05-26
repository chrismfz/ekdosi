<?php

namespace App\Filament\Resources\Companies\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        Tab::make('Identity')
                            ->schema([
                                TextInput::make('name')
                                    ->required()
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function (string $state, callable $set, ?string $old, string $context) {
                                        // Auto-suggest a slug on create, leave existing slugs alone on edit.
                                        if ($context === 'create') {
                                            $set('slug', Str::slug($state));
                                        }
                                    }),

                                TextInput::make('slug')
                                    ->required()
                                    ->maxLength(255)
                                    ->alphaDash()
                                    ->unique(ignoreRecord: true)
                                    ->helperText('Used in the URL: /admin/{slug}/...  Lowercase, dashes only.'),

                                Select::make('country_code')
                                    ->required()
                                    ->options([
                                        'GR' => 'Greece (myDATA)',
                                        'EE' => 'Estonia (PEPPOL)',
                                    ])
                                    ->default('GR')
                                    ->live()
                                    ->afterStateUpdated(function (string $state, callable $set) {
                                        // Sync the e-invoice provider with the country choice
                                        $set('einvoice_provider', match ($state) {
                                            'GR' => 'gr-mydata',
                                            'EE' => 'ee-peppol',
                                            default => 'none',
                                        });
                                    }),

                                Select::make('einvoice_provider')
                                    ->required()
                                    ->options([
                                        'gr-mydata' => 'Greek myDATA (AADE)',
                                        'ee-peppol' => 'Estonian PEPPOL',
                                        'none' => 'None (PDF only)',
                                    ])
                                    ->default('gr-mydata')
                                    ->helperText('Which submitter the IssueInvoice action routes through.'),

                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20),

                                TextInput::make('tax_office')
                                    ->label('Tax office (ΔΟΥ)')
                                    ->maxLength(60),
                            ])
                            ->columns(2),

                        Tab::make('Address & contact')
                            ->schema([
                                TextInput::make('address')
                                    ->columnSpanFull()
                                    ->maxLength(255),
                                TextInput::make('city')
                                    ->maxLength(60),
                                TextInput::make('postcode')
                                    ->maxLength(10),
                                TextInput::make('phone')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('email')
                                    ->label('Email address')
                                    ->email()
                                    ->maxLength(255),
                            ])
                            ->columns(2),

                        Tab::make('myDATA credentials')
                            // Only relevant for the Greek myDATA submitter.
                            ->visible(fn (callable $get) => $get('einvoice_provider') === 'gr-mydata')
                            ->schema([
                                Section::make()
                                    ->description('Stored encrypted at rest. Leave the key blank on edit to keep the existing value.')
                                    ->schema([
                                        TextInput::make('mydata_aade_id')
                                            ->label('AADE user ID (aade-user-id header)')
                                            ->maxLength(255),

                                        TextInput::make('mydata_subscription_key')
                                            ->label('Subscription key (Ocp-Apim-Subscription-Key)')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->maxLength(255),

                                        Toggle::make('mydata_production')
                                            ->label('Production endpoint')
                                            ->helperText('Off → AADE sandbox. On → real submissions.'),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
