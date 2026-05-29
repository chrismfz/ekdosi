<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\SupplierSource;
use App\Filament\Support\AadeFormFill;
use Filament\Actions\Action as FormAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Στοιχεία προμηθευτή')
                    ->columns(2)
                    ->schema([
                        TextInput::make('afm')
                            ->label('ΑΦΜ')
                            ->maxLength(20)
                            // One supplier per AFM per tenant — friendly
                            // message instead of a raw DB unique violation.
                            ->unique(
                                table: 'suppliers',
                                column: 'afm',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule->where('company_id', Filament::getTenant()?->getKey()),
                            )
                            ->suffixAction(
                                // Reuse the GSIS lookup we already have for
                                // customers. For GR suppliers this is the ONLY
                                // way to get the name (myDATA never sends it).
                                FormAction::make('fetch_supplier_from_aade')
                                    ->label('Άντληση από ΑΑΔΕ')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                    ->action(function (callable $get, callable $set): void {
                                        $result = AadeFormFill::lookup($get('afm'));
                                        if (! $result) {
                                            return;
                                        }

                                        // Only fill fields the operator left empty (don't
                                        // clobber a typed trade name / friendly address).
                                        $fillIfEmpty = function (string $field, ?string $value) use ($get, $set): void {
                                            if (empty($get($field)) && filled($value)) {
                                                $set($field, $value);
                                            }
                                        };
                                        $fillIfEmpty('name', $result->name);
                                        $fillIfEmpty('tax_office', $result->doy);
                                        $fillIfEmpty('address1', $result->address);
                                        $fillIfEmpty('city', $result->city);
                                        $fillIfEmpty('postcode', $result->postcode);
                                        $fillIfEmpty('country', 'GR');
                                        $primary = $result->primaryActivity();
                                        if ($primary) {
                                            $fillIfEmpty('occupation', $primary['description'] ?? null);
                                        }

                                        Notification::make()->title('Στοιχεία αντλήθηκαν από την ΑΑΔΕ')->success()->send();
                                    }),
                            ),

                        TextInput::make('name')
                            ->label('Επωνυμία')
                            ->maxLength(191)
                            ->columnSpan(1),

                        TextInput::make('tax_office')
                            ->label('ΔΟΥ')
                            ->maxLength(120),

                        TextInput::make('occupation')
                            ->label('Δραστηριότητα')
                            ->maxLength(191),

                        TextInput::make('country')
                            ->label('Χώρα (ISO-2)')
                            ->default('GR')
                            ->maxLength(2),
                    ]),

                Section::make('Επικοινωνία')
                    ->columns(2)
                    ->schema([
                        TextInput::make('address1')->label('Διεύθυνση')->maxLength(191),
                        TextInput::make('city')->label('Πόλη')->maxLength(120),
                        TextInput::make('postcode')->label('Τ.Κ.')->maxLength(20),
                        TextInput::make('phone1')->label('Τηλέφωνο')->maxLength(60),
                        TextInput::make('email')->label('Email')->email()->maxLength(191),
                    ]),

                Section::make('Λοιπά')
                    ->columns(2)
                    ->schema([
                        Select::make('source')
                            ->label('Προέλευση')
                            ->options(SupplierSource::options())
                            ->default(SupplierSource::Manual->value)
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Ενεργός')
                            ->default(true),

                        Textarea::make('notes')
                            ->label('Σημειώσεις')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
