<?php

namespace App\Filament\Resources\Suppliers\Schemas;

use App\Enums\SupplierSource;
use App\Filament\Support\AadeFormFill;
use App\Filament\Support\Tags\TagControls;
use App\Filament\Support\ViesFormFill;
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
                            // Reuse the GSIS lookup we already have for customers.
                            // For GR suppliers this is the ONLY way to get the
                            // name (myDATA never sends it). Two modes: "Άντληση"
                            // fills empty fields, "Διόρθωση" overwrites from AADE
                            // (source of truth).
                            ->suffixActions([
                                FormAction::make('fetch_supplier_from_aade')
                                    ->label('Άντληση από ΑΑΔΕ')
                                    ->icon('heroicon-o-arrow-down-tray')
                                    ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                    ->action(fn (callable $get, callable $set) => self::applyAadeToSupplier($get, $set, overwrite: false)),
                                FormAction::make('correct_supplier_from_aade')
                                    ->label('Διόρθωση από ΑΑΔΕ')
                                    ->icon('heroicon-o-arrow-path')
                                    ->color('warning')
                                    ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                    ->requiresConfirmation()
                                    ->modalHeading('Διόρθωση στοιχείων από ΑΑΔΕ')
                                    ->modalDescription('Αντικαθιστά επωνυμία/ΔΟΥ/διεύθυνση/δραστηριότητα με τα επίσημα στοιχεία του μητρώου ΑΑΔΕ (πηγή αλήθειας).')
                                    ->action(fn (callable $get, callable $set) => self::applyAadeToSupplier($get, $set, overwrite: true)),
                                // EU supplier (non-GR): validate the VAT via VIES and
                                // fill name/address where the member state publishes them.
                                FormAction::make('verify_supplier_vies')
                                    ->label('Επαλήθευση VIES')
                                    ->icon('heroicon-o-shield-check')
                                    ->visible(fn (callable $get) => ($get('country') ?: 'GR') !== 'GR')
                                    ->action(fn (callable $get, callable $set) => self::applyViesToSupplier($get, $set)),
                            ]),

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

                        TagControls::field()
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * GSIS lookup → supplier fields. overwrite=false fills empty fields
     * (import); overwrite=true replaces them (AADE source of truth). Shares
     * the empty/overwrite rule with the customer form via AadeFormFill::assign.
     */
    private static function applyAadeToSupplier(callable $get, callable $set, bool $overwrite): void
    {
        $result = AadeFormFill::lookup($get('afm'));
        if (! $result) {
            return;
        }

        AadeFormFill::assign($get, $set, 'name', $result->name, $overwrite);
        AadeFormFill::assign($get, $set, 'tax_office', $result->doy, $overwrite);
        AadeFormFill::assign($get, $set, 'address1', $result->address, $overwrite);
        AadeFormFill::assign($get, $set, 'city', $result->city, $overwrite);
        AadeFormFill::assign($get, $set, 'postcode', $result->postcode, $overwrite);
        AadeFormFill::assign($get, $set, 'country', 'GR', $overwrite);
        $primary = $result->primaryActivity();
        if ($primary) {
            AadeFormFill::assign($get, $set, 'occupation', $primary['description'] ?? null, $overwrite);
        }

        Notification::make()
            ->title($overwrite ? 'Διορθώθηκε από την ΑΑΔΕ' : 'Στοιχεία αντλήθηκαν από την ΑΑΔΕ')
            ->success()->send();
    }

    /**
     * VIES validation → supplier fields (non-GR EU). Validity is surfaced by
     * ViesFormFill::check; identity fields fill only-when-empty where the
     * member state publishes them.
     */
    private static function applyViesToSupplier(callable $get, callable $set): void
    {
        $result = ViesFormFill::check($get('afm'), $get('country'));
        if (! $result || ! $result->valid) {
            return;
        }

        ViesFormFill::assign($get, $set, 'country', $result->countryCode === 'EL' ? 'GR' : $result->countryCode, overwrite: false);
        if ($result->hasIdentity()) {
            ViesFormFill::assign($get, $set, 'name', $result->name, overwrite: false);
            ViesFormFill::assign($get, $set, 'address1', str_replace("\n", ', ', $result->address), overwrite: false);
        }
    }
}
