<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Services\AadeRegistryLookup;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
                                    // ->live() so the dependent myDATA-submission tab's
                                    // ->visible() check re-evaluates on direct edits, not
                                    // only when country_code's afterStateUpdated indirectly
                                    // sets the provider.
                                    ->live()
                                    ->helperText('Which submitter the IssueInvoice action routes through.'),

                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20)
                                    ->suffixAction(
                                        FormAction::make('fetch_from_aade')
                                            ->label('Fetch from AADE')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->visible(fn (callable $get) => $get('country_code') === 'GR')
                                            ->action(function (callable $get, callable $set, ?\App\Models\Company $record) {
                                                if (! $record) {
                                                    Notification::make()
                                                        ->title('Save the company first, then click Fetch.')
                                                        ->warning()
                                                        ->send();
                                                    return;
                                                }
                                                $afm = trim((string) $get('afm'));
                                                if ($afm === '') {
                                                    Notification::make()->title('Enter an AFM first.')->warning()->send();
                                                    return;
                                                }
                                                try {
                                                    $result = app(AadeRegistryLookup::class, ['tenant' => $record])->findByAfm($afm);
                                                } catch (AadeCredentialsInvalid) {
                                                    Notification::make()
                                                        ->title('GSIS credentials invalid')
                                                        ->body('Check the AADE registry credentials below.')
                                                        ->danger()->send();
                                                    return;
                                                } catch (AadeAfmNotFound) {
                                                    Notification::make()->title('AFM not found or inactive in AADE registry')->warning()->send();
                                                    return;
                                                } catch (AadeUnreachable) {
                                                    Notification::make()->title('AADE registry unreachable — try again later')->warning()->send();
                                                    return;
                                                }
                                                // Only overwrite fields the operator hasn't
                                                // typed into. Without these guards, a typed
                                                // trade name "MyIP" gets clobbered by the
                                                // AADE legal name "MYIP NET WORKS Ο.Ε.";
                                                // similarly for friendly delivery addresses.
                                                // Operators can clear a field to force AADE
                                                // to populate it.
                                                $fillIfEmpty = function (string $field, string $value) use ($get, $set): void {
                                                    if (empty($get($field)) && $value !== '') {
                                                        $set($field, $value);
                                                    }
                                                };
                                                $fillIfEmpty('name', $result->name);
                                                $fillIfEmpty('tax_office', $result->doy);
                                                $fillIfEmpty('address', $result->address);
                                                $fillIfEmpty('city', $result->city);
                                                $fillIfEmpty('postcode', $result->postcode);
                                                $primary = $result->primaryActivity();
                                                if ($primary) {
                                                    $fillIfEmpty('kad_primary', $primary['code']);
                                                }
                                                // Surface the AADE-reported status — a
                                                // deactivated own-company AFM means everything
                                                // downstream (myDATA submission) will fail.
                                                $body = $result->doy.($primary ? ' · '.$primary['description'] : '');
                                                $notification = Notification::make()
                                                    ->title('Loaded from AADE: '.$result->name);
                                                if ($result->active) {
                                                    $notification->body($body)->success();
                                                } else {
                                                    $notification
                                                        ->body($body.' · ⚠ Status: '.($result->statusDescr ?: 'unknown — verify with AADE'))
                                                        ->warning();
                                                }
                                                $notification->send();
                                            }),
                                    ),

                                TextInput::make('tax_office')
                                    ->label('Tax office (ΔΟΥ)')
                                    ->maxLength(60),

                                TextInput::make('kad_primary')
                                    ->label('Primary KAD (Δραστηριότητα)')
                                    ->maxLength(20)
                                    ->helperText('Auto-fills from the AADE Fetch button. Used on invoice headers as the issuer\'s primary activity classification.'),
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

                        Tab::make('myDATA submission')
                            // Only relevant for the Greek myDATA submitter.
                            ->visible(fn (callable $get) => $get('einvoice_provider') === 'gr-mydata')
                            ->schema([
                                Section::make()
                                    ->description('REST credentials for posting invoices to AADE myDATA. Stored encrypted at rest. Leave the key blank on edit to keep the existing value.')
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

                                        Select::make('mydata_mode')
                                            ->label('Submission mode')
                                            ->options(\App\Enums\MyDataMode::options())
                                            ->default(\App\Enums\MyDataMode::Off->value)
                                            ->required()
                                            ->live()
                                            ->helperText(new \Illuminate\Support\HtmlString(
                                                '<strong>Off</strong> = no AADE call (PDFs only, safe for testing). '
                                                . '<strong>Sandbox</strong> = AADE test endpoint (synthetic MARKs). '
                                                . '<strong>Production</strong> = LIVE submissions affecting real tax records. '
                                                . '<br><strong>⚠ Switching to/from Production:</strong> the change takes effect '
                                                . 'on save. Verify credentials via "Test connection" before going Live; '
                                                . 'switching back to Off/Sandbox stops legally-required filings.'
                                            )),
                                            // Note: a Notification-on-afterStateUpdated approach
                                            // was tried and removed — it fired on every form
                                            // state change (including immediate undos), creating
                                            // toast spam that trained operators to ignore the
                                            // warnings. The safer pattern is to surface the
                                            // mode-change semantic in helperText + a real
                                            // confirm modal on the EditCompany page's save
                                            // action when mydata_mode transitions involve
                                            // Production. That belongs on the page class, not
                                            // the form schema — tracked in CLAUDE.md as a
                                            // deferred follow-up since it requires touching
                                            // EditCompany.php and a custom save action.
                                    ])
                                    ->footerActions([
                                        FormAction::make('test_mydata_connection')
                                            ->label('Test myDATA connection')
                                            ->icon('heroicon-o-bolt')
                                            // Only meaningful when mode != off. NullSubmitter's
                                            // testConnection trivially returns true so the
                                            // button would lie about the credentials being
                                            // valid (it never tries them).
                                            ->visible(fn (callable $get) => in_array(
                                                $get('mydata_mode'),
                                                ['sandbox', 'production'],
                                                true,
                                            ))
                                            ->action(function (?\App\Models\Company $record) {
                                                if (! $record) {
                                                    Notification::make()
                                                        ->title('Save the company first, then test.')
                                                        ->warning()->send();
                                                    return;
                                                }
                                                try {
                                                    $ok = (new \App\Services\MyDataSubmitter($record))->testConnection();
                                                } catch (\Throwable $e) {
                                                    Notification::make()
                                                        ->title('myDATA unreachable')
                                                        ->body($e->getMessage())
                                                        ->warning()->send();
                                                    return;
                                                }
                                                if ($ok) {
                                                    Notification::make()
                                                        ->title('Connected to myDATA')
                                                        ->body('Credentials accepted by AADE ('.($record->mydata_mode_enum->value ?? '?').' endpoint).')
                                                        ->success()->send();
                                                } else {
                                                    Notification::make()
                                                        ->title('myDATA rejected the credentials')
                                                        ->body('Check the aade-user-id and Ocp-Apim-Subscription-Key fields.')
                                                        ->danger()->send();
                                                }
                                            }),
                                    ]),
                            ]),

                        Tab::make('AADE registry (GSIS)')
                            // Greek-only: the RgWsPublic2 service is for AFM
                            // lookup against the Greek tax registry. Different
                            // credentials from myDATA (SOAP UsernameToken, not
                            // REST headers — see App\Services\AadeRegistryLookup).
                            ->visible(fn (callable $get) => $get('country_code') === 'GR')
                            ->schema([
                                Section::make()
                                    ->description('SOAP credentials for the AADE VAT lookup service (RgWsPublic2). Used by the "Fetch from AADE" button on customer + company AFM fields. Different from myDATA credentials.')
                                    ->schema([
                                        TextInput::make('gsis_username')
                                            ->label('GSIS username')
                                            ->maxLength(120),

                                        TextInput::make('gsis_password')
                                            ->label('GSIS password')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->maxLength(255),
                                    ])
                                    ->footerActions([
                                        FormAction::make('test_gsis')
                                            ->label('Test registry credentials')
                                            ->icon('heroicon-o-bolt')
                                            ->action(function (callable $get, ?\App\Models\Company $record) {
                                                if (! $record) {
                                                    Notification::make()
                                                        ->title('Save the company first, then test.')
                                                        ->warning()->send();
                                                    return;
                                                }
                                                // Use the form's current AFM, not the
                                                // persisted one — operator may have just
                                                // edited it. Cache::forget so the test
                                                // ALWAYS hits the wire (otherwise a stale
                                                // cache entry would falsely report
                                                // success after credentials changed).
                                                $afm = trim((string) $get('afm'));
                                                if ($afm === '') {
                                                    Notification::make()
                                                        ->title('Enter an AFM on the Identity tab first.')
                                                        ->warning()->send();
                                                    return;
                                                }
                                                \Illuminate\Support\Facades\Cache::forget(
                                                    "aade.registry.{$record->getKey()}.{$afm}"
                                                );
                                                try {
                                                    $result = app(AadeRegistryLookup::class, ['tenant' => $record])->findByAfm($afm);
                                                    Notification::make()
                                                        ->title('Connected to GSIS')
                                                        ->body('Looked up AFM '.$afm.' successfully: '.$result->name.' ('.$result->doy.')')
                                                        ->success()->send();
                                                } catch (AadeCredentialsInvalid $e) {
                                                    Notification::make()
                                                        ->title('Credentials rejected')
                                                        ->body($e->getMessage())
                                                        ->danger()->send();
                                                } catch (AadeAfmNotFound) {
                                                    Notification::make()
                                                        ->title('Credentials OK but AFM not found')
                                                        ->body('GSIS accepted the login but didn\'t recognise '.$afm.'. Check the AFM field on the Identity tab.')
                                                        ->warning()->send();
                                                } catch (AadeUnreachable) {
                                                    Notification::make()
                                                        ->title('AADE unreachable')
                                                        ->body('SOAP/network failure — try again later.')
                                                        ->warning()->send();
                                                }
                                            }),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
