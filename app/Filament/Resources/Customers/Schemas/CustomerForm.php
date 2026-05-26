<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Services\AadeRegistryLookup;
use Filament\Actions\Action as FormAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

class CustomerForm
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
                                    ->maxLength(191)
                                    ->columnSpan(2),

                                TextInput::make('type')
                                    ->label('Type')
                                    ->helperText('Free text — e.g. "Company" / "Individual" / etc.')
                                    ->maxLength(60),

                                TextInput::make('occupation')
                                    ->maxLength(120),

                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true)
                                    ->helperText('Inactive customers stay in the books for invoice history but are hidden from the default list.'),

                                Toggle::make('needs_immediate_invoice')
                                    ->label('Immediate invoicing (γκρινιάρης)')
                                    ->helperText('When on, the scheduled invoicing command issues + sends to myDATA on the same tick as payment lands, instead of rolling into the weekly batch.'),
                            ])
                            ->columns(2),

                        Tab::make('Tax & legal')
                            ->schema([
                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20)
                                    ->suffixAction(
                                        FormAction::make('fetch_customer_from_aade')
                                            ->label('Fetch from AADE')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            // Only meaningful for Greek tenants — RgWsPublic2
                                            // looks up Greek AFMs only.
                                            ->visible(fn () => Filament::getTenant()?->country_code === 'GR')
                                            ->action(function (callable $get, callable $set) {
                                                $tenant = Filament::getTenant();
                                                if (! $tenant) {
                                                    Notification::make()->title('No tenant context.')->warning()->send();
                                                    return;
                                                }
                                                $afm = trim((string) $get('afm'));
                                                if ($afm === '') {
                                                    Notification::make()->title('Enter an AFM first.')->warning()->send();
                                                    return;
                                                }
                                                try {
                                                    $result = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm);
                                                } catch (AadeCredentialsInvalid) {
                                                    Notification::make()
                                                        ->title('GSIS credentials missing or invalid')
                                                        ->body('Configure them on the Company → AADE registry (GSIS) tab.')
                                                        ->danger()->send();
                                                    return;
                                                } catch (AadeAfmNotFound) {
                                                    Notification::make()
                                                        ->title('AFM not found or inactive in AADE registry')
                                                        ->body('Double-check the digits, or fill the customer manually if this is a special case.')
                                                        ->warning()->send();
                                                    return;
                                                } catch (AadeUnreachable) {
                                                    Notification::make()
                                                        ->title('AADE registry unreachable')
                                                        ->body('Try again in a moment, or fill the customer manually.')
                                                        ->warning()->send();
                                                    return;
                                                }
                                                // Fill what AADE returned. Operator can edit any
                                                // value before saving — the fetch never persists
                                                // by itself.
                                                $set('name', $result->name);
                                                $set('tax_office', $result->doy);
                                                $set('address1', $result->address);
                                                $set('city', $result->city);
                                                $set('postcode', $result->postcode);
                                                $set('country', 'GR');
                                                $primary = $result->primaryActivity();
                                                if ($primary) {
                                                    $set('kad_primary', $primary['code']);
                                                    // occupation is the human-readable activity
                                                    // text that legacy prints on invoices as
                                                    // "Δραστηριότητα: ...". Only overwrite if
                                                    // currently empty (don't clobber operator-
                                                    // typed occupations).
                                                    if (empty($get('occupation'))) {
                                                        $set('occupation', $primary['description']);
                                                    }
                                                }
                                                Notification::make()
                                                    ->title('Loaded from AADE: '.$result->name)
                                                    ->body($result->doy.($primary ? ' · '.$primary['description'] : ''))
                                                    ->success()->send();
                                            }),
                                    ),

                                TextInput::make('vat_vies')
                                    ->label('VIES VAT (EU intra-community)')
                                    ->maxLength(30),

                                TextInput::make('tax_office')
                                    ->label('Tax office (ΔΟΥ)')
                                    ->maxLength(60),

                                TextInput::make('kad_primary')
                                    ->label('Primary KAD (Δραστηριότητα)')
                                    ->maxLength(20)
                                    ->helperText('Auto-fills from AADE Fetch. Numeric activity code; the human-readable text lives in Occupation on the Identity tab.'),

                                TextInput::make('withhold_tax')
                                    ->label('Withholding tax category')
                                    ->numeric()
                                    ->helperText('Numeric category code (legacy column).'),
                            ])
                            ->columns(2),

                        Tab::make('Address & contact')
                            ->schema([
                                TextInput::make('address1')
                                    ->label('Address line 1')
                                    ->maxLength(60)
                                    ->columnSpan(2),

                                TextInput::make('address2')
                                    ->label('Address line 2')
                                    ->maxLength(60)
                                    ->columnSpan(2),

                                TextInput::make('city')->maxLength(60),
                                TextInput::make('postcode')->maxLength(10),
                                TextInput::make('country')->maxLength(60),

                                TextInput::make('phone1')
                                    ->label('Phone')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('phone2')
                                    ->label('Phone (alt)')
                                    ->tel()
                                    ->maxLength(30),
                                TextInput::make('fax')
                                    ->tel()
                                    ->maxLength(30),

                                TextInput::make('email')
                                    ->email()
                                    ->maxLength(120),
                                TextInput::make('secondary_email')
                                    ->label('Email (alt)')
                                    ->email()
                                    ->maxLength(120),
                            ])
                            ->columns(2),

                        Tab::make('Commercial')
                            ->schema([
                                TextInput::make('discount')
                                    ->label('Default discount %')
                                    ->numeric()
                                    ->step('0.01')
                                    ->minValue(0)
                                    ->maxValue(100)
                                    ->default(0)
                                    ->suffix('%'),

                                Select::make('payment_method_id')
                                    ->label('Default payment method')
                                    ->options(fn () => PaymentMethod::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->orderBy('description')
                                        ->pluck('description', 'id'))
                                    ->searchable()
                                    ->preload(),

                                Select::make('referred_by_customer_id')
                                    ->label('Referred by')
                                    ->searchable()
                                    // Server-side search — no row-count ceiling.
                                    ->getSearchResultsUsing(function (string $search, ?Customer $record) {
                                        return Customer::query()
                                            ->where('company_id', Filament::getTenant()?->getKey())
                                            ->when($record?->id, fn ($q, $id) => $q->whereKeyNot($id))
                                            ->where('name', 'like', "%{$search}%")
                                            ->orderBy('name')
                                            ->limit(50)
                                            ->pluck('name', 'id')
                                            ->toArray();
                                    })
                                    // Resolve the currently-saved id back to a label on edit.
                                    ->getOptionLabelUsing(fn ($value) => Customer::query()
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->whereKey($value)
                                        ->value('name'))
                                    ->helperText('Optional. Pick another customer if this one was referred. Non-customer sources (Google, trade show…) — tag support comes later.'),

                                TextInput::make('whmcs_client_id')
                                    ->label('WHMCS client ID')
                                    ->numeric()
                                    ->helperText('Set by the WHMCS bridge when it lands. Editable manually for now.'),
                            ])
                            ->columns(2),

                        // Only meaningful when the tenant submits via PEPPOL
                        // (Estonian companies right now; future EU expansion).
                        Tab::make('PEPPOL')
                            ->visible(fn () => Filament::getTenant()?->einvoice_provider === 'ee-peppol')
                            ->schema([
                                TextInput::make('peppol_endpoint')
                                    ->label('PEPPOL endpoint identifier')
                                    ->maxLength(255)
                                    ->helperText('Estonian companies: their Äriregistri kood formatted as the PEPPOL identifier (e.g. 0007:12345678). Used by the future PEPPOL submitter.'),
                            ]),

                        Tab::make('Notes')
                            ->schema([
                                Textarea::make('details')
                                    ->rows(8)
                                    ->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }
}
