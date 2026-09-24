<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Enums\MyDataMode;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Filament\Pages\MyDataCodeGuide;
use App\Filament\Support\MailTemplateFields;
use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\AadeRegistryLookup;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Services\MyDataSubmitter;
use App\Services\TenantMailerFactory;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderEndpointGuard;
use App\Support\EInvoice\SendChannel;
use App\Support\MyData\ClassificationGuidance;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
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

                                // Multi-domain #1c: optional custom host for the customer
                                // portal (e.g. cs.nixpal.com). Κενό = μόνο το κοινό default
                                // host. Ορίζει τη γλώσσα/branding των guest σελίδων του tenant.
                                TextInput::make('portal_host')
                                    ->label('Custom portal host')
                                    ->maxLength(255)
                                    // Normalise on blur (bare lowercase host) BEFORE the unique
                                    // check, so a case/whitespace/scheme variant doesn't slip past
                                    // validation and hit the DB unique index as a 500.
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(fn (?string $state, callable $set) => $set('portal_host', Company::normalizePortalHost($state)))
                                    ->unique(ignoreRecord: true)
                                    ->placeholder('cs.nixpal.com')
                                    ->helperText('Προαιρετικό. Hostname (χωρίς https:// ή /path — καθαρίζεται αυτόματα) όπου απαντά και η πύλη /user. Απαιτεί DNS + TLS + vhost προς την app. Κενό = μόνο το default host.'),

                                Select::make('country_code')
                                    ->required()
                                    ->options([
                                        'GR' => 'Greece (myDATA)',
                                        'EE' => 'Estonia (PEPPOL)',
                                    ])
                                    ->default('GR')
                                    ->live()
                                    ->afterStateUpdated(function (string $state, callable $set) {
                                        // Reset the send-channel to a safe default for the country.
                                        $set('send_channel', match ($state) {
                                            'GR' => 'mydata-off',
                                            'EE' => 'peppol',
                                            default => 'off',
                                        });
                                    }),

                                // The flat operator control: ONE dropdown that drives the
                                // underlying columns (einvoice_provider / mydata_mode /
                                // einvoice_provider_key / einvoice_provider_mode) via the
                                // page hooks + SendChannelFormBridge. No raw columns/JSON.
                                Select::make('send_channel')
                                    ->label('Τρόπος αποστολής παραστατικών')
                                    ->options(fn (?Company $record): array => self::sendChannelOptions($record))
                                    ->default(SendChannel::FALLBACK)
                                    ->required()
                                    ->live()
                                    ->native(false)
                                    ->helperText(new HtmlString(
                                        'Πώς φεύγουν τα παραστατικά στην ΑΑΔΕ. '
                                        .'<strong>myDATA — Παραγωγή/Δοκιμαστικό</strong>: απευθείας (διαπιστευτήρια στην καρτέλα «myDATA»). '
                                        .'<strong>Καθόλου</strong>: μόνο PDF, καμία αποστολή. '
                                        .'<strong>Πάροχος</strong> (π.χ. InvoSign): μέσω παρόχου — στοιχεία στην καρτέλα «Πάροχος». '
                                        .'<br>Τα διαπιστευτήρια myDATA μένουν πάντα — χρησιμοποιούνται για ελέγχους/συμφωνία/έξοδα ακόμη κι όταν στέλνεις μέσω παρόχου.'
                                    )),

                                TextInput::make('afm')
                                    ->label('AFM / VAT number')
                                    ->maxLength(20)
                                    ->suffixAction(
                                        FormAction::make('fetch_from_aade')
                                            ->label('Fetch from AADE')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->visible(fn (callable $get) => $get('country_code') === 'GR')
                                            ->action(function (callable $get, callable $set, ?Company $record) {
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
                                    )
                                    // MYD-024: warn, don't block. In practice a new ΑΦΜ means a
                                    // new legal entity (and new myDATA/provider credentials), not
                                    // an edit — but a typo fixed before the first filing is
                                    // legitimate, so the warning only appears once something has
                                    // actually been filed under the current one.
                                    ->helperText(fn (?Company $record) => self::identityChangeWarning(
                                        $record,
                                        'Το ΑΦΜ είναι η νομική ταυτότητα του εκδότη: στέλνεται σε κάθε '
                                        .'παραστατικό και είναι το ίδιο ΑΦΜ με το οποίο συνδεόμαστε στο myDATA '
                                        .'και στον πάροχο.',
                                    )),

                                TextInput::make('tax_office')
                                    ->label('Tax office (ΔΟΥ)')
                                    ->maxLength(60),

                                TextInput::make('kad_primary')
                                    ->label('Primary KAD (Δραστηριότητα)')
                                    ->maxLength(20)
                                    ->helperText('Auto-fills from the AADE Fetch button. Printed on the invoice header as the issuer\'s primary activity classification.'),

                                // MYD-006: the income-classification policy (§8.6 goods
                                // bucket). Required before go-live; shown for AADE-filing
                                // tenants only (Estonian/none don't classify income to AADE).
                                Select::make('business_activity_type')
                                    ->label('Είδος δραστηριότητας (κατηγοριοποίηση εσόδων)')
                                    ->options(ClassificationGuidance::options())
                                    ->native(false)
                                    ->live()
                                    ->visible(fn (callable $get) => in_array(
                                        SendChannel::decompose((string) $get('send_channel'))['einvoice_provider'],
                                        ['gr-mydata', 'gr-provider'],
                                        true,
                                    ))
                                    ->helperText(fn (?string $state): HtmlString => MyDataCodeGuide::helperText(
                                        ClassificationGuidance::hintFor($state)
                                            ?? 'Ορίζει την §8.6 κατηγορία εσόδων των αγαθών (εμπορεύματα vs δικά μας προϊόντα). Απαιτείται πριν το go-live.'
                                    )),

                                TextInput::make('gemi')
                                    ->label('ΓΕΜΗ')
                                    ->maxLength(30)
                                    ->helperText(fn (?Company $record) => self::identityChangeWarning(
                                        $record,
                                        'Αριθμός ΓΕΜΗ — τυπώνεται στην κεφαλίδα του παραστατικού (υποχρεωτικό για '
                                        .'εγγεγραμμένες στο ΓΕΜΗ οντότητες, ν.4919/2022).',
                                    )),
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
                                Section::make('Αγγλικά στοιχεία (διεθνή έγγραφα — CMR)')
                                    ->description('Επίσημη λατινική επωνυμία/διεύθυνση για τη φορτωτική CMR (box 1 — Sender). Αν μείνουν κενά, γίνεται αυτόματη μεταγραφή των ελληνικών.')
                                    ->columns(2)
                                    ->collapsed()
                                    ->columnSpanFull()
                                    ->schema([
                                        TextInput::make('name_en')->label('Company name (EN)')->maxLength(255)->columnSpanFull(),
                                        TextInput::make('address_en')->label('Address (EN)')->maxLength(255)->columnSpanFull(),
                                        TextInput::make('city_en')->label('City (EN)')->maxLength(255),
                                    ]),
                            ])
                            ->columns(2),

                        Tab::make('myDATA')
                            // Shown for BOTH direct-myDATA and provider channels: a provider
                            // tenant still needs myDATA credentials for the READ path
                            // (reconciliation / έξοδα). Hidden only for PEPPOL / «none».
                            ->visible(fn (callable $get) => in_array(
                                SendChannel::decompose((string) $get('send_channel'))['einvoice_provider'],
                                ['gr-mydata', 'gr-provider'],
                                true,
                            ))
                            ->schema([
                                // Provider tenants: explain that the myDATA READ environment is
                                // NOT a separate switch — it follows the «Τρόπος αποστολής» mode
                                // (InvoSign Δοκιμαστικό → reads Sandbox, Παραγωγή → reads Production).
                                // This is the in-UI home for the coupling so it isn't forgotten.
                                Placeholder::make('mydata_read_env_notice')
                                    ->hiddenLabel()
                                    ->visible(fn (callable $get) => SendChannel::isProvider((string) $get('send_channel')))
                                    ->content(new HtmlString(
                                        '<div class="text-sm rounded-lg bg-warning-50 dark:bg-warning-400/10 p-3 space-y-1">'
                                        .'<p class="font-semibold">ℹ️ Ανάγνωση myDATA & πάροχος</p>'
                                        .'<p>Στέλνεις μέσω παρόχου, αλλά οι έλεγχοι/συμφωνία/έξοδα διαβάζουν '
                                        .'<strong>απευθείας από την ΑΑΔΕ</strong> με τα δικά σου διαπιστευτήρια myDATA — όχι από τον πάροχο.</p>'
                                        .'<p>Από προεπιλογή το <strong>περιβάλλον ανάγνωσης ακολουθεί τον «Τρόπο αποστολής»</strong>: '
                                        .'<em>Δοκιμαστικό</em> → διαβάζει από Sandbox · <em>Παραγωγή</em> → από Production '
                                        .'(γι\' αυτό σε δοκιμαστικό βλέπεις μόνο τα λίγα test παραστατικά του sandbox). '
                                        .'Μπορείς όμως να το <strong>ορίσεις ρητά</strong> παρακάτω — π.χ. να διαβάζεις '
                                        .'<strong>Παραγωγή</strong> ενώ δοκιμάζεις τον πάροχο στο Sandbox.</p>'
                                        .'</div>'
                                    )),

                                // The explicit READ-environment override (Company::mydataReadMode).
                                // Empty = «Αυτόματο» (follow the send mode) — the default for every
                                // tenant; a value reads that environment REGARDLESS of the submit
                                // channel, provided its credentials are set. Reads never write to AADE.
                                Select::make('mydata_read_env')
                                    ->label('Περιβάλλον ανάγνωσης myDATA')
                                    // Provider-only, matching the notice above: a DIRECT gr-mydata
                                    // tenant reads the same environment it submits to, so a
                                    // read≠submit split there would just manufacture false
                                    // reconciliation mismatches (all invoices «missingAtAade»)
                                    // with no upside. The model still honours a value set out-of-band.
                                    ->visible(fn (callable $get) => SendChannel::isProvider((string) $get('send_channel')))
                                    ->options([
                                        'sandbox' => 'Δοκιμαστικό (Sandbox)',
                                        'production' => 'Παραγωγή (Production)',
                                    ])
                                    ->placeholder('Αυτόματο — ακολουθεί τον «Τρόπο αποστολής»')
                                    ->native(false)
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state ?: null)
                                    ->helperText(new HtmlString(
                                        'Κανονικά το περιβάλλον ανάγνωσης ακολουθεί τον «Τρόπο αποστολής». '
                                        .'Όρισέ το ρητά για να <strong>διαβάζεις Παραγωγή ενώ στέλνεις μέσω δοκιμαστικού παρόχου</strong> '
                                        .'(χρειάζονται συμπληρωμένα τα αντίστοιχα διαπιστευτήρια πιο κάτω· αλλιώς επανέρχεται στο «Αυτόματο»). '
                                        .'Αφορά μόνο ΑΝΑΓΝΩΣΗ — δεν επηρεάζει την υποβολή.'
                                    )),

                                Section::make('Sandbox / Developer credentials')
                                    ->description('Το περιβάλλον (Παραγωγή/Δοκιμαστικό/Καθόλου) επιλέγεται από το «Τρόπος αποστολής» στην καρτέλα Στοιχεία — εδώ μπαίνουν μόνο τα διαπιστευτήρια. REST credentials για το test endpoint της ΑΑΔΕ (synthetic MARKs). Κρυπτογραφημένα. Άφησε το key κενό στην επεξεργασία για να κρατηθεί το υπάρχον. ⚠ Πριν πας σε Παραγωγή, δοκίμασε με «Test Sandbox connection».')
                                    ->schema([
                                        TextInput::make('mydata_aade_id_sandbox')
                                            ->label('SANDBOX — AADE user ID (aade-user-id header)')
                                            ->maxLength(255),

                                        TextInput::make('mydata_subscription_key_sandbox')
                                            ->label('SANDBOX — Subscription key (Ocp-Apim-Subscription-Key)')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->maxLength(255),
                                    ])
                                    ->footerActions([
                                        self::mydataTestAction(
                                            'test_mydata_sandbox',
                                            'Test Sandbox connection',
                                            MyDataMode::Sandbox,
                                        ),
                                    ]),

                                Section::make('Production / Live credentials')
                                    ->description('REST credentials for LIVE submissions affecting real tax records. Stored encrypted at rest. Leave the key blank on edit to keep the existing value.')
                                    ->schema([
                                        TextInput::make('mydata_aade_id_production')
                                            ->label('PRODUCTION — AADE user ID (aade-user-id header)')
                                            ->maxLength(255),

                                        TextInput::make('mydata_subscription_key_production')
                                            ->label('PRODUCTION — Subscription key (Ocp-Apim-Subscription-Key)')
                                            ->password()
                                            ->revealable()
                                            ->dehydrated(fn (?string $state): bool => filled($state))
                                            ->maxLength(255),
                                    ])
                                    ->footerActions([
                                        self::mydataTestAction(
                                            'test_mydata_production',
                                            'Test Production connection',
                                            MyDataMode::Production,
                                        ),
                                    ]),

                                Section::make('Επιλογές αποστολής')
                                    ->description('Προαιρετικές ρυθμίσεις για το ΤΙ στέλνουμε στην ΑΑΔΕ.')
                                    ->schema([
                                        Toggle::make('mydata_send_item_descr')
                                            ->label('Αποστολή περιγραφής γραμμής (<itemDescr>) στην ΑΑΔΕ')
                                            ->helperText('Η ΑΑΔΕ ΔΕΝ απαιτεί την περιγραφή ανά γραμμή (το myDATA κρατά μόνο αξίες/ΦΠΑ/κατηγοριοποίηση εσόδων — η παλιά εφαρμογή δεν την έστελνε). ⚠ Προσοχή: η ΑΑΔΕ δέχεται την περιγραφή ΜΟΝΟ σε δελτία διακίνησης/αποστολής (τύποι 9.x) — σε κανονικό ΤΠΥ/ΤΙΜ την ΑΠΟΡΡΙΠΤΕΙ. Γι\' αυτό στέλνεται μόνο στους επιτρεπόμενους τύπους· στα υπόλοιπα παραστατικά αγνοείται ακόμη κι αν ανοίξεις τον διακόπτη. Δοκίμασέ το πρώτα στο Sandbox.')
                                            ->default(false),
                                    ]),
                            ]),

                        Tab::make('Πάροχος (ΥΠΑΗΕΣ)')
                            // Shown only when «Τρόπος αποστολής» is a provider channel.
                            ->visible(fn (callable $get) => SendChannel::isProvider((string) $get('send_channel')))
                            ->schema([
                                Section::make('Στοιχεία παρόχου')
                                    ->description('Συμπληρώνονται όταν στέλνεις μέσω παρόχου. Αποθηκεύονται κρυπτογραφημένα. Άφησε τον μυστικό κωδικό κενό στην επεξεργασία για να κρατηθεί ο υπάρχων. Όταν ολοκληρωθεί η τεχνική σύνδεση με τον πάροχο, το «Έλεγχος σύνδεσης» θα επιβεβαιώνει τα στοιχεία.')
                                    ->schema(self::providerCredentialFields())
                                    ->footerActions([
                                        self::providerTestAction(),
                                    ]),

                                Section::make('Παράδοση στον πελάτη')
                                    ->description('Ο πάροχος μπορεί να στείλει το νόμιμο παραστατικό στον πελάτη μέσω email. Άναψέ το όταν είσαι σε παραγωγή με πάροχο — κράτα το σβηστό στο δοκιμαστικό ώστε να μη φεύγουν δοκιμαστικά τιμολόγια σε πραγματικούς πελάτες.')
                                    ->schema([
                                        Toggle::make('einvoice_include_customer_email')
                                            ->label('Αποστολή email πελάτη στον πάροχο')
                                            ->default(false)
                                            ->helperText('Όταν είναι ON, το email του πελάτη μπαίνει στο XML (<CounterpartEmail>) και ο πάροχος το χρησιμοποιεί για να του στείλει το παραστατικό. Όταν είναι OFF, το πεδίο φεύγει κενό (ό,τι και για πελάτη χωρίς email) και ο πάροχος δεν στέλνει email. Αφορά ΜΟΝΟ τον δίαυλο παρόχου — το ekdosi κρατά το δικό του email τιμολογίων ανεξάρτητα.'),
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

                                        // #8: opt this tenant into the gentle WEEKLY background
                                        // re-check of its customers' ΑΦΜ status (ενεργό/ανενεργό).
                                        // Off by default — GSIS has daily quotas, so the sweep is
                                        // bounded (few AFMs/run, only stale ones, throttled) AND
                                        // per-tenant opt-in. The on-demand «Διασταύρωση» button
                                        // works regardless of this toggle.
                                        Toggle::make('aade_status_auto_refresh')
                                            ->label('Περιοδικός έλεγχος κατάστασης ΑΦΜ πελατών (ΑΑΔΕ)')
                                            ->helperText('Χαλαρός εβδομαδιαίος έλεγχος λίγων ΑΦΜ κάθε φορά (μόνο όσα δεν ελέγχθηκαν πρόσφατα). Χρειάζεται και τον κεντρικό scheduler (EKDOSI_SCHEDULE_AADE_STATUS_REFRESH).')
                                            ->default(false),
                                    ])
                                    ->footerActions([
                                        FormAction::make('test_gsis')
                                            ->label('Test registry credentials')
                                            ->icon('heroicon-o-bolt')
                                            ->action(function (callable $get, ?Company $record) {
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
                                                Cache::forget(
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

                        // ====== PR #27: PDF & Email tab ======
                        Tab::make('PDF & Email')
                            ->schema([
                                Section::make('Branding')
                                    ->description('Logo + footer text used on every PDF this tenant emits.')
                                    ->schema([
                                        FileUpload::make('logo_path')
                                            ->label('Logo')
                                            ->image()
                                            ->disk('public')
                                            ->directory('logos')
                                            ->maxSize(2048)
                                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                                            ->helperText('PNG or JPEG, max 2MB. SVG not supported (DomPDF raster only).')
                                            ->imagePreviewHeight('80'),
                                        Textarea::make('pdf_footer_text')
                                            ->label('PDF footer text')
                                            ->rows(2)
                                            ->maxLength(500)
                                            ->helperText('Appears in the page footer of every PDF. Plain text only.'),
                                        Toggle::make('show_customer_balance_on_pdf')
                                            ->label('Υπόλοιπο πελάτη στο PDF')
                                            ->helperText('Προεπιλογή: τυπώνει block «Νέο υπόλοιπο» (Προηγούμενο + παραστατικό = Νέο) στα τιμολόγια επί πιστώσει. Ανά πελάτη υπερισχύει η δική του ρύθμιση.'),
                                        // i18n Slice 0: tenant fallback language for documents/mails
                                        // when a customer has NO explicit language and no usable
                                        // country to derive one from (App\Support\CustomerLanguage).
                                        Select::make('default_language')
                                            ->label('Προεπιλεγμένη γλώσσα επικοινωνίας')
                                            ->options([
                                                'el' => 'Ελληνικά',
                                                'en' => 'Αγγλικά',
                                                'both' => 'Δίγλωσσο (GR/EN)',
                                            ])
                                            ->placeholder('Αυτόματο (Ελληνικά)')
                                            ->helperText('Fallback γλώσσα του tenant όταν ο πελάτης δεν έχει ρητή γλώσσα ούτε χώρα (ο πελάτης/η χώρα του υπερισχύουν). Εφαρμόζεται στα email· το PDF μένει «παγωμένο» στο έγγραφο.'),
                                    ])
                                    ->columns(2),

                                Section::make('Mail templates')
                                    ->description('Subject and body for the customer-facing invoice mail. Use placeholders: {tenant_name}, {invoice_code}, {invoice_type}, {issued_at}, {customer_name}, {total}, {mark}, {verify_url}, {mark_section}.')
                                    ->schema([
                                        MailTemplateFields::subject(
                                            'Subject template',
                                            'Pre-filled with the default so you can tweak it. Use the reset link (or clear the field) to restore the app default. Single line.',
                                        ),
                                        MailTemplateFields::body(
                                            'Body template',
                                            'Pre-filled with the default — edit it inline. Use the reset link to restore the default. Plain text with placeholders; HTML is escaped to plain text at send time (security).',
                                        ),
                                    ]),

                                Section::make('Outbound mail routing')
                                    ->description('Where mails come from + who else gets BCC\'d for the audit trail.')
                                    ->schema([
                                        Toggle::make('auto_email_on_mydata_accept')
                                            ->label('Auto-email customer when AADE accepts')
                                            ->helperText('When a filing is accepted/VALID — DIRECT to myDATA or through a provider (InvoSign…) — queue an email with the PDF to the customer. Off for sandbox/training tenants.')
                                            ->columnSpanFull(),
                                        // G6: the non-electronic counterpart — fires when a draft
                                        // is finalized on a tenant that doesn't file electronically
                                        // (provider 'none' / Estonian / mode off); an electronic
                                        // tenant (myDATA OR provider) gets the mail on acceptance.
                                        Toggle::make('auto_email_on_issue')
                                            ->label('Auto-email customer on issue (non-electronic)')
                                            ->helperText('When a draft is finalized on a tenant that does NOT file electronically (neither myDATA nor a provider), queue an email with the PDF. Per-customer opt-out lives on each customer. Off = global kill-switch (e.g. for testing).')
                                            ->columnSpanFull(),
                                        TextInput::make('mail_from_address')
                                            ->label('From address')
                                            ->email()
                                            ->maxLength(191)
                                            ->placeholder(config('mail.from.address'))
                                            ->helperText('Sender shown to recipients. Match the SMTP server\'s SPF/DKIM. Blank = use app-wide default.'),
                                        TextInput::make('mail_from_name')
                                            ->label('From name')
                                            ->maxLength(191)
                                            ->placeholder(fn (?Company $record) => $record?->name ?: config('mail.from.name')),
                                        TextInput::make('invoice_audit_bcc')
                                            ->label('Audit BCC recipients')
                                            ->maxLength(500)
                                            ->placeholder('audit@example.com, ops@example.com')
                                            ->helperText('Comma- or semicolon-separated. Blank = no BCC. Replaces the legacy hardcoded "invoice@myip.gr".')
                                            ->columnSpanFull(),
                                    ])
                                    ->columns(2),

                                Section::make('SMTP server (optional)')
                                    ->description('Send through this tenant\'s own SMTP server. Blank fields = use the global MAIL_MAILER from .env.')
                                    ->schema([
                                        TextInput::make('mail_smtp_host')
                                            ->label('Host')
                                            ->maxLength(191)
                                            ->placeholder('smtp.example.com'),
                                        TextInput::make('mail_smtp_port')
                                            ->label('Port')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(65535)
                                            ->placeholder('587'),
                                        TextInput::make('mail_smtp_username')
                                            ->label('Username')
                                            ->maxLength(191),
                                        TextInput::make('mail_smtp_password')
                                            ->label('Password')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(191)
                                            ->helperText('Encrypted at rest.')
                                            // Don't replace existing encrypted value on form save
                                            // if operator leaves it blank — Filament otherwise
                                            // clears the field. dehydrateStateUsing: if blank,
                                            // skip update by returning the current value.
                                            ->dehydrated(fn (?string $state) => filled($state))
                                            ->dehydrateStateUsing(fn (string $state) => $state),
                                        Select::make('mail_smtp_encryption')
                                            ->label('Encryption')
                                            ->options([
                                                'tls' => 'TLS (port 587)',
                                                'ssl' => 'SSL (port 465)',
                                            ])
                                            ->placeholder('None'),
                                        FormAction::make('test_smtp')
                                            ->label('Send a test email')
                                            ->icon('heroicon-o-paper-airplane')
                                            ->color('gray')
                                            // Defense-in-depth: only operators who can update
                                            // the Company should be able to spray test emails
                                            // from its SMTP. Without this gate, any user with
                                            // view-only access to Company who reaches the form
                                            // could send to arbitrary addresses.
                                            ->authorize(fn (?Company $record) => $record === null
                                                ? false
                                                : (auth()->user()?->can('update', $record) ?? false))
                                            ->requiresConfirmation()
                                            ->modalHeading('Send test email')
                                            ->modalDescription(fn (?Company $record) => 'Sends a one-off plain test mail to verify SMTP config + audit BCC. Uses the CURRENT saved settings (not pending unsaved edits — save first).')
                                            ->schema([
                                                TextInput::make('to')
                                                    ->label('Send test to')
                                                    ->email()
                                                    ->required()
                                                    ->helperText('Where to deliver the probe. BCC list (if configured) will also receive it.'),
                                            ])
                                            ->action(function (array $data, ?Company $record) {
                                                if (! $record) {
                                                    Notification::make()->title('Save the company first, then test.')->warning()->send();

                                                    return;
                                                }
                                                try {
                                                    $mailer = app(TenantMailerFactory::class)->for($record);
                                                    $bcc = array_map(fn (string $a) => new Address($a), $record->auditBccList());
                                                    // Contract-compliant: Mailer::send(array $view, array $data, Closure $callback).
                                                    // The array form ['html' => '<inline html>']
                                                    // is supported on the Mailer contract (verified
                                                    // at vendor/laravel/.../Mailer.php), unlike
                                                    // ->html() which only exists on the concrete
                                                    // \Illuminate\Mail\Mailer class.
                                                    $html = '<p>This is a test email from ekdosi for tenant <strong>'.e($record->name).'</strong>.</p>'.
                                                        '<p>If you received this, the tenant\'s SMTP config (or the global fallback) is working.</p>';
                                                    $mailer->send(
                                                        ['html' => new HtmlString($html)],
                                                        [],
                                                        function ($message) use ($data, $record, $bcc) {
                                                            $message->to($data['to'])
                                                                ->subject('[ekdosi test] '.$record->name);
                                                            if ($record->mail_from_address) {
                                                                $message->from(
                                                                    $record->mail_from_address,
                                                                    $record->mail_from_name ?: $record->name
                                                                );
                                                            }
                                                            foreach ($bcc as $b) {
                                                                $message->bcc($b->address, $b->name);
                                                            }
                                                        }
                                                    );
                                                    Notification::make()
                                                        ->title('Test email dispatched')
                                                        ->body('Sent to '.$data['to'].(count($bcc) ? ' (BCC: '.count($bcc).')' : '').'. Check the inbox.')
                                                        ->success()->send();
                                                } catch (\Throwable $e) {
                                                    // Surface the FULL SMTP error: this action is
                                                    // gated by authorize('update', $record), so
                                                    // only operators who already typed the SMTP
                                                    // host see it — there's no info-disclosure to
                                                    // an external party. Symfony Mailer wraps the
                                                    // server's reason in lines 2-N of the
                                                    // exception ("Connection refused", "535
                                                    // Authentication failed", "Server said: ..."),
                                                    // and that's exactly what the operator needs
                                                    // to diagnose their config. First-line-only
                                                    // sanitisation was hiding signal.
                                                    Notification::make()
                                                        ->title('Test email failed')
                                                        ->body($e->getMessage())
                                                        ->danger()->persistent()->send();
                                                }
                                            }),
                                    ])
                                    ->columns(2),
                            ]),

                        // ====== PR #28: WHMCS bridge — Stage A tab ======
                        Tab::make('WHMCS bridge')
                            ->schema([
                                Section::make('API credentials')
                                    ->description('Connection to the tenant\'s WHMCS install. Stage A is read-only — we list paid+unfiled invoices and match them to ekdosi customers. Stage B (next PR) will issue them through myDATA.')
                                    ->schema([
                                        TextInput::make('whmcs_api_url')
                                            ->label('API URL')
                                            ->maxLength(500)
                                            ->placeholder('https://billing.example.gr/includes/api.php')
                                            ->helperText('Full URL ending in /includes/api.php.'),
                                        TextInput::make('whmcs_api_identifier')
                                            ->label('API Identifier')
                                            ->maxLength(191)
                                            ->helperText('From WHMCS: Setup → Staff Management → API Credentials.'),
                                        TextInput::make('whmcs_api_secret')
                                            ->label('API Secret')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(191)
                                            ->helperText('Encrypted at rest. Leave blank to keep existing.')
                                            ->dehydrated(fn (?string $state) => filled($state))
                                            ->dehydrateStateUsing(fn (string $state) => $state),

                                        // PR #34 followup: cutover date filter so long-running
                                        // WHMCS tenants don't pull 19 years of historical test /
                                        // staff / internal invoices into the inbox. Set this once
                                        // to the date you started filing via ekdosi (or the day
                                        // you stopped doing test invoices). Null = no cutoff =
                                        // pull all history (correct for fresh WHMCS installs).
                                        DatePicker::make('whmcs_invoice_min_date')
                                            ->label('Cut-over: skip invoices PAID before')
                                            ->native(false)
                                            ->displayFormat('Y-m-d')
                                            ->helperText('Your ekdosi cut-over date. With the ekdosi_bridge plugin ≥ 0.48.0 the inbox (Fetch) brings every WHMCS invoice PAID on/after this date — regardless of when it was issued — so a renewal issued earlier but paid now still shows the day it is paid; the historical backlog (paid before it) stays out. Without that plugin (native API path) it falls back to the invoice ISSUE date. IMPORTANT for long-running tenants with historical data; leave blank only if your WHMCS is fresh.'),
                                        Toggle::make('whmcs_amount_includes_tax')
                                            ->label('WHMCS line amounts include VAT')
                                            ->default(true)
                                            ->helperText('ON (default, Greek norm): WHMCS sends GROSS line amounts and ekdosi backs out the VAT. OFF: this WHMCS runs tax-exclusive (NET amounts) — flip it so VAT is added, not divided out, otherwise filed VAT is wrong (G3).'),
                                        FormAction::make('test_whmcs_connection')
                                            ->label('Test connection')
                                            ->icon('heroicon-o-signal')
                                            ->color('gray')
                                            ->authorize(fn (?Company $record) => $record === null
                                                ? false
                                                : (auth()->user()?->can('update', $record) ?? false))
                                            ->action(function (?Company $record) {
                                                if (! $record) {
                                                    Notification::make()->title('Save the company first, then test.')->warning()->send();

                                                    return;
                                                }
                                                try {
                                                    $version = app(WhmcsClientFactory::class)
                                                        ->for($record)
                                                        ->testConnection();
                                                    Notification::make()
                                                        ->title('Connected to WHMCS')
                                                        ->body("Reached WHMCS ({$version}). Credentials accepted.")
                                                        ->success()->send();
                                                } catch (WhmcsNotConfigured $e) {
                                                    Notification::make()
                                                        ->title('WHMCS not configured')
                                                        ->body($e->getMessage())
                                                        ->warning()->send();
                                                } catch (WhmcsAuthenticationFailed $e) {
                                                    Notification::make()
                                                        ->title('WHMCS credentials rejected')
                                                        ->body($e->getMessage().' Check Setup → Staff Management → API Credentials in WHMCS, plus the IP allowlist if any.')
                                                        ->danger()->persistent()->send();
                                                } catch (WhmcsUnreachable $e) {
                                                    Notification::make()
                                                        ->title('WHMCS unreachable')
                                                        ->body($e->getMessage())
                                                        ->danger()->persistent()->send();
                                                } catch (WhmcsApiException $e) {
                                                    Notification::make()
                                                        ->title('WHMCS error')
                                                        ->body($e->getMessage())
                                                        ->danger()->persistent()->send();
                                                }
                                            }),

                                        // PR #34 followup: Filament-side controls
                                        // so operators don't have to SSH to the deploy
                                        // box to run `php artisan whmcs:fetch-pending`.
                                        // Same code paths as the artisan command.
                                        FormAction::make('preview_pending_whmcs')
                                            ->label('Preview pending')
                                            ->icon('heroicon-o-eye')
                                            ->color('gray')
                                            ->authorize(fn (?Company $record) => $record === null
                                                ? false
                                                : (auth()->user()?->can('update', $record) ?? false))
                                            ->action(function (?Company $record) {
                                                if (! $record) {
                                                    Notification::make()->title('Save the company first, then preview.')->warning()->send();

                                                    return;
                                                }
                                                try {
                                                    $client = app(WhmcsClientFactory::class)->for($record);
                                                    $minDate = $record->whmcs_invoice_min_date?->format('Y-m-d');
                                                    $invoices = $client->getPendingInvoices(limit: 100, minDate: $minDate);
                                                } catch (WhmcsNotConfigured $e) {
                                                    Notification::make()->title('WHMCS not configured')->body($e->getMessage())->warning()->send();

                                                    return;
                                                } catch (WhmcsAuthenticationFailed $e) {
                                                    Notification::make()->title('WHMCS credentials rejected')->body($e->getMessage())->danger()->persistent()->send();

                                                    return;
                                                } catch (WhmcsUnreachable $e) {
                                                    Notification::make()->title('WHMCS unreachable')->body($e->getMessage())->danger()->persistent()->send();

                                                    return;
                                                } catch (WhmcsApiException $e) {
                                                    Notification::make()->title('WHMCS error')->body($e->getMessage())->danger()->persistent()->send();

                                                    return;
                                                }

                                                $count = count($invoices);
                                                $cutoffNote = $minDate
                                                    ? " (skipping invoices dated before {$minDate})"
                                                    : ' (no cutoff date set — pulling ALL history)';
                                                if ($count === 0) {
                                                    Notification::make()
                                                        ->title('No paid + unfiled invoices')
                                                        ->body('WHMCS returned no pending invoices for this tenant'.$cutoffNote.'.')
                                                        ->success()->send();

                                                    return;
                                                }

                                                // Match each row so operators see what would land
                                                // as linked / afm / email / name / unmatched.
                                                $matcher = app(WhmcsCustomerMatcher::class);
                                                $matched = 0;
                                                foreach ($invoices as $inv) {
                                                    $whmcsClientId = (int) ($inv['userid'] ?? 0);
                                                    $m = $matcher->match($record, [
                                                        'id' => $whmcsClientId,
                                                        'userid' => $whmcsClientId,
                                                        'email' => $inv['email'] ?? null,
                                                        'firstname' => $inv['firstname'] ?? null,
                                                        'lastname' => $inv['lastname'] ?? null,
                                                        'companyname' => $inv['companyname'] ?? null,
                                                    ]);
                                                    if ($m->isMatched()) {
                                                        $matched++;
                                                    }
                                                }
                                                $unmatched = $count - $matched;

                                                Notification::make()
                                                    ->title("Preview: {$count} pending invoice(s)")
                                                    ->body("{$matched} would match an ekdosi customer · {$unmatched} unmatched. "
                                                        .'Click "Fetch pending" to stage them in the inbox.')
                                                    ->info()->persistent()->send();
                                            }),

                                        FormAction::make('fetch_pending_whmcs')
                                            ->label('Fetch pending')
                                            ->icon('heroicon-o-arrow-down-tray')
                                            ->color('primary')
                                            ->requiresConfirmation()
                                            ->modalHeading('Fetch pending WHMCS invoices?')
                                            ->modalDescription('Pulls the latest paid+unfiled invoices from WHMCS and stages them in the inbox for operator review. Idempotent — re-running is safe. Does NOT file at AADE (that needs operator click in the inbox).')
                                            ->modalSubmitActionLabel('Fetch now')
                                            ->authorize(fn (?Company $record) => $record === null
                                                ? false
                                                : (auth()->user()?->can('update', $record) ?? false))
                                            ->action(function (?Company $record) {
                                                if (! $record) {
                                                    Notification::make()->title('Save the company first.')->warning()->send();

                                                    return;
                                                }

                                                // ONE source path: delegate to whmcs:fetch-pending, which itself
                                                // picks bridge (Plugin-API) vs native WHMCS API per the tenant's
                                                // whmcs_fetch_via_bridge flag AND runs the legacy-invoiced refresh.
                                                // The button no longer re-implements the native ingest loop, so
                                                // source-selection + legacy-refresh behaviour can't drift between
                                                // this button and the scheduler/CLI. (Runs synchronously; a very
                                                // large tenant could approach the request timeout — the scheduled
                                                // task is the bulk path.)
                                                try {
                                                    $exit = Artisan::call('whmcs:fetch-pending', ['--tenant' => $record->slug]);
                                                } catch (\Throwable $e) {
                                                    Notification::make()->title('Fetch failed')->body($e->getMessage())->danger()->persistent()->send();

                                                    return;
                                                }
                                                $summary = collect(preg_split('/\r?\n/', trim(Artisan::output())))
                                                    ->first(fn ($l) => str_contains((string) $l, 'Summary')) ?: 'Done.';
                                                Notification::make()
                                                    ->title($exit === 0 ? "Fetched into inbox: {$record->name}" : 'Fetched with issues (exit '.$exit.')')
                                                    ->body((string) $summary)
                                                    ->{$exit === 0 ? 'success' : 'warning'}()
                                                    ->persistent()->send();
                                            }),
                                    ]),

                                // G8 phase 2: άμεση-τιμολόγηση auto-issue. DANGER zone —
                                // files legal documents unattended. Two-key armed:
                                // this toggle + the scheduler flag
                                // (EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE).
                                Section::make('Αυτόματη έκδοση (άμεση τιμολόγηση)')
                                    ->description('Όταν ενεργοποιηθεί, οι πληρωμένες εγγραφές του Inbox για πελάτες με σήμανση «άμεσης τιμολόγησης» εκδίδονται + υποβάλλονται ΑΥΤΟΜΑΤΑ στην ΑΑΔΕ από το προγραμματισμένο whmcs:auto-issue — μόνο οι σαφείς εγγραφές· οτιδήποτε αμφίβολο μένει στο Inbox για τον χειριστή. ΠΡΟΣΟΧΗ: εκδίδει νομικά παραστατικά χωρίς έγκριση.')
                                    ->schema([
                                        Toggle::make('whmcs_auto_issue_immediate')
                                            ->label('Αυτόματη έκδοση (άμεση τιμολόγηση)')
                                            ->default(false)
                                            ->helperText('Απαιτεί ΚΑΙ τον γενικό διακόπτη του scheduler (EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE) ΚΑΙ ορισμένο προεπιλεγμένο τύπο παραστατικού παρακάτω. Με OFF (προεπιλογή) δεν εκδίδεται τίποτα αυτόματα — η εγγραφή απλώς επισημαίνεται «Άμεσο» στο Inbox.'),
                                        Select::make('whmcs_default_invoice_type_id')
                                            ->label('Προεπιλεγμένος τύπος ΤΙΜΟΛΟΓΙΟΥ (αυτόματη έκδοση)')
                                            ->options(fn (?Company $record) => static::whmcsDefaultTypeOptions($record, $record?->whmcs_default_invoice_type_id))
                                            ->searchable()
                                            ->live()
                                            ->helperText('Ο τύπος για πελάτες που ζήτησαν ΤΙΜΟΛΟΓΙΟ (ή τρίτους που δεν είναι απόδειξη). Χωρίς αυτόν, η αυτόματη έκδοση παραλείπει τον tenant (δεν μαντεύει ποτέ τον τύπο). Η χειροκίνητη επιλογή στο Inbox δεν επηρεάζεται. ΣΗΜΑΝΤΙΚΟ: ο τρόπος πληρωμής αυτού του τύπου να έχει 0 ημέρες πίστωσης (τα WHMCS τιμολόγια είναι ήδη πληρωμένα· με πίστωση >0 θα εμφανίζονται ως ανοιχτές οφειλές).'),
                                        Select::make('whmcs_default_receipt_type_id')
                                            ->label('Προεπιλεγμένος τύπος ΑΠΟΔΕΙΞΗΣ (προαιρετικό)')
                                            ->options(fn (?Company $record) => static::whmcsDefaultTypeOptions($record, $record?->whmcs_default_receipt_type_id))
                                            ->searchable()
                                            ->live()
                                            ->helperText('Ο τύπος «Απόδειξης λιανικής» για όταν ο πελάτης ΔΕΝ ζήτησε τιμολόγιο, ή ένας μονομερής τρίτος είναι σημασμένος ως απόδειξη. Χωρίς αυτόν, τέτοιες εγγραφές ΜΕΝΟΥΝ στο Inbox για τον χειριστή (δεν εκδίδονται ποτέ ως λάθος τύπος). Ίδιος κανόνας πληρωμής: 0 ημέρες πίστωσης (ή κανένας τρόπος πληρωμής — και τα δύο = εξοφλημένο στην έκδοση).'),
                                        Select::make('whmcs_default_unpaid_type_id')
                                            ->label('Προεπιλεγμένος τύπος για ΑΠΛΗΡΩΤΑ WHMCS (επί πιστώσει, προαιρετικό)')
                                            ->options(fn (?Company $record) => static::whmcsDefaultTypeOptions($record, $record?->whmcs_default_unpaid_type_id))
                                            ->searchable()
                                            ->live()
                                            ->helperText('Ο τύπος «επί πιστώσει» για όταν το WHMCS τιμολόγιο έρχεται ΑΠΛΗΡΩΤΟ (π.χ. Α.Ε./Δημόσιο που θέλει πρώτα τιμολόγιο, μετά πληρώνει). Το «Δημιουργία Παραστατικού» τον προ-επιλέγει όταν η εγγραφή είναι Unpaid, ώστε να μείνει σωστά ΑΝΟΙΧΤΗ ΟΦΕΙΛΗ. Εδώ ΘΕΛΕΙΣ τρόπο πληρωμής με ημέρες πίστωσης >0 (το ΑΝΤΙΘΕΤΟ από τους παραπάνω). Κενό → πέφτει πίσω στον τύπο τιμολογίου.'),
                                        // Live tripwire for the «paid WHMCS invoice shows as an open
                                        // receivable» trap: warn ONLY when a chosen type's payment
                                        // method has due_days > 0. Cash-term (due_days = 0 OR no method
                                        // at all) is settled-at-issue → fine, no warning. Surfaced here
                                        // so the requirement isn't buried in a runbook.
                                        Placeholder::make('whmcs_default_type_payment_term_check')
                                            ->label('Έλεγχος τρόπου πληρωμής')
                                            ->content(function (Get $get, ?Company $record): HtmlString {
                                                if (! $record) {
                                                    return new HtmlString('');
                                                }
                                                $slots = [
                                                    'whmcs_default_invoice_type_id' => 'τιμολογίου',
                                                    'whmcs_default_receipt_type_id' => 'απόδειξης',
                                                ];
                                                $warnings = [];
                                                foreach ($slots as $field => $slotLabel) {
                                                    $id = $get($field);
                                                    if (! $id) {
                                                        continue;
                                                    }
                                                    $type = InvoiceType::query()
                                                        ->where('company_id', $record->id)
                                                        ->with('paymentMethod')
                                                        ->find($id);
                                                    $dueDays = $type?->paymentMethod?->due_days;
                                                    if ($dueDays !== null && (int) $dueDays > 0) {
                                                        $warnings[] = e("Ο τύπος {$slotLabel} «{$type->code} — {$type->name}» έχει τρόπο πληρωμής «{$type->paymentMethod->description}» με {$dueDays} ημέρες πίστωσης (πληρωμένο WHMCS → θα φαίνεται ως ανοιχτή οφειλή· βάλε 0 ημέρες)");
                                                    }
                                                }

                                                // The UNPAID slot is the MIRROR: it SHOULD be credit-term
                                                // (due_days > 0) so an unpaid WHMCS invoice stays an open
                                                // receivable. Warn if it's cash-term (0 or no method) —
                                                // an unpaid invoice issued under it would read as settled.
                                                $unpaidId = $get('whmcs_default_unpaid_type_id');
                                                if ($unpaidId) {
                                                    $unpaidType = InvoiceType::query()
                                                        ->where('company_id', $record->id)
                                                        ->with('paymentMethod')
                                                        ->find($unpaidId);
                                                    if ($unpaidType !== null) {
                                                        $udd = $unpaidType->paymentMethod?->due_days;
                                                        if ($udd === null || (int) $udd === 0) {
                                                            $warnings[] = e("Ο τύπος ΑΠΛΗΡΩΤΩΝ «{$unpaidType->code} — {$unpaidType->name}» είναι εξοφλημένος στην έκδοση (0 ημέρες/χωρίς τρόπο) — τα απλήρωτα δεν θα φαίνονται ως οφειλή· βάλε τρόπο πληρωμής με ημέρες πίστωσης >0");
                                                        }
                                                    }
                                                }

                                                if ($warnings === []) {
                                                    return new HtmlString('<span style="color:#16a34a;">✓ Σωστοί όροι πληρωμής: πληρωμένα = εξοφλημένα στην έκδοση, απλήρωτα = επί πιστώσει.</span>');
                                                }

                                                return new HtmlString('<span style="color:#dc2626; font-weight:600;">⚠️ '.implode('· ', $warnings).'.</span>');
                                            }),
                                    ]),

                                // T-1 (timologia v2): third-party invoicing. When ON, the
                                // ingestor asks the WHMCS-side plugin's resolve.php which of a
                                // WHMCS invoice's lines route to an alternate beneficiary
                                // (reseller → end-customer), bills single-party invoices to
                                // that contact, and stages multi-party ones for a guided split.
                                // OFF (default) = today's behaviour: everything bills the
                                // matched WHMCS client.
                                Section::make('Παραστατικά σε τρίτους (timologia v2)')
                                    ->description('Δρομολόγηση γραμμών WHMCS σε εναλλακτικό δικαιούχο (π.χ. reseller που τιμολογεί τους δικούς του πελάτες). Με ON, το ekdosi ρωτά το plugin (resolve.php) ποιος χρεώνεται ανά γραμμή.')
                                    ->schema([
                                        Toggle::make('whmcs_third_party_enabled')
                                            ->label('Ανίχνευση δρομολόγησης τρίτων (admin)')
                                            ->default(false)
                                            ->helperText('ADMIN-ONLY — οι πελάτες ΔΕΝ βλέπουν τίποτα από αυτό. Όταν είναι ON, ο ingestor ρωτά τη γέφυρα (resolve.php) ανά τιμολόγιο και γεμίζει τη στήλη «Τρίτος» στο WHMCS Inbox (μονομερή → χρέωση στον δικαιούχο· πολλαπλά → «Διαχωρισμός»). Με OFF η «Τρίτος» μένει «—». Απαιτεί resolve.php εγκατεστημένο + API URL/secret. Η ορατότητα της σελίδας ΠΕΛΑΤΩΝ «Παραστατικά σε τρίτους (v2)» ελέγχεται ΞΕΧΩΡΙΣΤΑ στο WHMCS plugin (ρύθμιση «Show client v2»).'),
                                    ]),

                                // Slice 2: where the inbox feed comes from. With ON, the
                                // scheduled fetch pulls invoices from the bridge plugin
                                // (resolve.php op=invoices) instead of WHMCS's native API —
                                // one paginated HMAC call, routing folded in, no 1+2N
                                // round-trips. Flip per tenant once validated live.
                                Section::make('Πηγή λήψης τιμολογίων (inbox)')
                                    ->description('Από πού τραβά το ekdosi τα τιμολόγια του WHMCS Inbox.')
                                    ->schema([
                                        Toggle::make('whmcs_fetch_via_bridge')
                                            ->label('Λήψη μέσω του bridge plugin (αντί native WHMCS API)')
                                            ->default(false)
                                            ->helperText('Με ON, το προγραμματισμένο whmcs:fetch-pending τραβά τα τιμολόγια από το δικό μας plugin (resolve.php op=invoices) — μία σελιδοποιημένη HMAC κλήση, με τη δρομολόγηση τρίτων ήδη μέσα, χωρίς τα 1+2N round-trips του native API. Απαιτεί plugin v0.20.0+. Δοκίμασέ το πρώτα χειροκίνητα: php artisan whmcs:fetch-pending --tenant=SLUG --via-bridge.'),

                                        // OUTBOUND opt-in (Phase 2). Default OFF: no write ever
                                        // lands in the customer's WHMCS unless this is on.
                                        Toggle::make('whmcs_push_payments')
                                            ->label('Ενημέρωση πληρωμών ΠΡΟΣ το WHMCS (mark-paid)')
                                            ->default(false)
                                            ->helperText('⚠ Γράφει στο σύστημα του πελάτη. Με ON, όταν ένα επί-πιστώσει τιμολόγιο εξοφληθεί ΕΔΩ, το WHMCS σημαίνεται πληρωμένο (AddInvoicePayment) — αυτόματα (queued) + με κουμπί «Σήμανση Paid στο WHMCS» ανά τιμολόγιο και στη σελίδα «Συγχρονισμός πληρωμών». Idempotent + anti-echo (δεν γυρίζει πίσω ό,τι ήρθε από το WHMCS). Για bridge tenants απαιτεί plugin με op=add_payment.'),
                                    ]),

                                Section::make('Custom field mapping')
                                    ->description('Each WHMCS install assigns its own integer IDs to custom fields. Map role→field so we read the right data (AFM, invoice-vs-receipt intent, …) when matching + filing. Use «Άντληση & αντιστοίχιση» to PICK the fields from WHMCS instead of typing fragile ids.')
                                    ->schema([
                                        // Picker: pull the WHMCS client custom-field catalogue (op=custom_fields)
                                        // and let the operator map each role to a field by NAME — instead of
                                        // hand-typing integer ids that silently break / get forgotten (the empty
                                        // map is exactly what made AFM + intent vanish from the inbox). Fills the
                                        // KeyValue below via $set, so the normal form Save persists it (no
                                        // direct-DB clobber of unsaved form edits).
                                        FormAction::make('map_whmcs_custom_fields')
                                            ->label('Άντληση & αντιστοίχιση πεδίων WHMCS')
                                            ->icon('heroicon-o-link')
                                            ->color('primary')
                                            ->visible(fn (?Company $record) => $record !== null && $record->whmcsBridgeUrl() !== null)
                                            ->authorize(fn (?Company $record) => $record === null
                                                ? false
                                                : (auth()->user()?->can('update', $record) ?? false))
                                            ->modalHeading('Αντιστοίχιση WHMCS custom fields')
                                            ->modalSubmitActionLabel('Εφαρμογή')
                                            ->fillForm(fn (?Company $record) => $record?->whmcs_custom_field_map ?? [])
                                            ->form(function (?Company $record) {
                                                $options = [];
                                                $help = null;
                                                try {
                                                    foreach (app(WhmcsBridgeClientFactory::class)->for($record)->listCustomFields() as $f) {
                                                        $options[(int) $f['id']] = $f['fieldname'].' (#'.$f['id'].')'.($f['adminonly'] ? ' [admin]' : '');
                                                    }
                                                    if ($options === []) {
                                                        $help = 'Δεν βρέθηκαν client custom fields στο WHMCS.';
                                                    }
                                                } catch (\Throwable $e) {
                                                    $help = 'Αδυναμία άντλησης πεδίων από το plugin ('.$e->getMessage()
                                                        .'). Βάλε ids χειροκίνητα στον πίνακα.';
                                                }
                                                $roles = [
                                                    'vatno' => 'ΑΦΜ (vatno)',
                                                    'wantsinvoice' => 'Θέλει τιμολόγιο (wantsinvoice)',
                                                    'taxoffice' => 'ΔΟΥ (taxoffice)',
                                                    'occupation' => 'Δραστηριότητα (occupation)',
                                                    'griniaris' => 'Άμεση τιμολόγηση (WHMCS role: griniaris)',
                                                ];
                                                $schema = [];
                                                foreach ($roles as $role => $label) {
                                                    $schema[] = Select::make($role)
                                                        ->label($label)
                                                        ->options($options)
                                                        ->searchable()
                                                        ->nullable()
                                                        ->helperText($help);
                                                }

                                                return $schema;
                                            })
                                            ->action(function (array $data, ?Company $record, Set $set) {
                                                // MERGE into the existing map — only set/unset the picker's
                                                // OWN roles, so a role outside the picker (e.g. a hand-entered
                                                // toinvoice) is never silently wiped by applying the picker.
                                                $map = (array) ($record?->whmcs_custom_field_map ?? []);
                                                $applied = 0;
                                                foreach (['vatno', 'wantsinvoice', 'taxoffice', 'occupation', 'griniaris'] as $role) {
                                                    if (filled($data[$role] ?? null) && (int) $data[$role] > 0) {
                                                        $map[$role] = (int) $data[$role];
                                                        $applied++;
                                                    } else {
                                                        unset($map[$role]);   // cleared in the picker → drop it
                                                    }
                                                }
                                                $set('whmcs_custom_field_map', $map);
                                                Notification::make()
                                                    ->title('Αντιστοιχίστηκαν '.$applied.' πεδία')
                                                    ->body('Πάτησε «Save» για να αποθηκευτεί η αντιστοίχιση.')
                                                    ->success()->send();
                                            }),
                                        KeyValue::make('whmcs_custom_field_map')
                                            ->label(false)
                                            ->keyLabel('Role')
                                            ->valueLabel('WHMCS field id')
                                            ->addable(true)
                                            ->editableKeys(true)
                                            ->reorderable(false)
                                            ->helperText(fn (?Company $record) => (empty($record?->whmcs_custom_field_map)
                                                    ? '⚠ Δεν έχει οριστεί αντιστοίχιση — ΑΦΜ + πρόθεση (τιμολόγιο/απόδειξη) ΔΕΝ διαβάζονται κι ο πελάτης μένει «μη συνδεδεμένος». '
                                                    : '')
                                                .'Canonical roles: vatno (AFM), taxoffice (ΔΟΥ), occupation (Δραστηριότητα), griniaris (immediate-invoice flag), wantsinvoice ("θα ήθελα τιμολόγιο" → invoice vs receipt). Leave empty if your WHMCS doesn\'t track a role.'),
                                    ]),

                                // PR #31 (Stage B-1): per-tenant webhook secret. Used by the
                                // /webhooks/whmcs/{slug}/invoice-paid endpoint to verify HMAC
                                // signatures on inbound calls from the WHMCS-side plugin
                                // (Stage B-3). Kept separate from the API secret deliberately:
                                // outbound vs inbound auth, distinct blast radius if either leaks.
                                Section::make('Inbound webhook')
                                    ->description('Shared secret for the WHMCS-side plugin to sign push notifications to this tenant\'s ekdosi inbox. Endpoint: POST /webhooks/whmcs/{slug}/invoice-paid with header X-Webhook-Signature: sha256=<hex hmac of raw body>. Stage B-3 plugin ships in a later PR; configure now to test end-to-end against a real WHMCS install.')
                                    ->schema([
                                        TextInput::make('whmcs_webhook_secret')
                                            ->label('Webhook secret')
                                            ->password()
                                            ->revealable()
                                            ->maxLength(191)
                                            ->helperText('Encrypted at rest. Generate a fresh random string (32+ chars) per tenant. Leave blank to keep existing.')
                                            ->dehydrated(fn (?string $state) => filled($state))
                                            ->dehydrateStateUsing(fn (string $state) => $state),
                                    ]),
                            ]),
                        Tab::make('AI Βοηθός')
                            ->schema([
                                Toggle::make('ai_assistant_enabled')
                                    ->label('Ενεργός AI βοηθός')
                                    ->helperText('Ενεργοποιεί τον in-app βοηθό (read-only Q&A) για αυτή την εταιρεία. Χρειάζεται και το global EKDOSI_AI_ENABLED.'),
                                Select::make('ai_model')
                                    ->label('Μοντέλο')
                                    ->options([
                                        'claude-sonnet-4-6' => 'Sonnet 4.6 (προεπιλογή — ισορροπία)',
                                        'claude-haiku-4-5' => 'Haiku 4.5 (φθηνό/γρήγορο)',
                                        'claude-opus-4-8' => 'Opus 4.8 (βαριά ανάλυση)',
                                    ])
                                    ->placeholder('Προεπιλογή συστήματος (Sonnet)')
                                    ->helperText('Κενό = η προεπιλογή του συστήματος.'),
                                TextInput::make('ai_monthly_token_cap')
                                    ->label('Μηνιαίο όριο tokens')
                                    ->numeric()
                                    ->minValue(0)
                                    ->helperText('Πάνω από αυτό ο βοηθός σταματά για τον μήνα. Κενό = μόνο το global όριο.'),
                                TextInput::make('ai_api_key')
                                    ->label('Κλειδί API (προαιρετικό)')
                                    ->password()
                                    ->revealable()
                                    ->helperText('Μόνο αν η εταιρεία θέλει δικό της Anthropic account· κενό = το global κλειδί. Encrypted at rest, leave blank to keep existing.')
                                    ->dehydrated(fn (?string $state) => filled($state))
                                    ->dehydrateStateUsing(fn (string $state) => $state),
                            ]),
                        Tab::make('Υποστήριξη')
                            ->schema([
                                Toggle::make('support_enabled')
                                    ->label('Ενεργό σύστημα υποστήριξης (tickets)')
                                    ->helperText('Ενεργοποιεί τον πυλώνα Υποστήριξης (Πυλώνας E) για αυτή την εταιρεία: το μενού «Υποστήριξη», τις ρυθμίσεις τμημάτων/έτοιμων απαντήσεων και (αργότερα) την πύλη πελάτη. Ανενεργό = τελείως κρυμμένο.'),
                            ]),
                        Tab::make('Domains')
                            ->schema([
                                Toggle::make('enable_domain_management')
                                    ->label('Ενεργή διαχείριση domains')
                                    ->helperText('Ενεργοποιεί τον πυλώνα Domains (Πυλώνας A) για αυτή την εταιρεία: το μενού «Domains», τις συνδέσεις registrar και (σταδιακά) καταχωρήσεις/ανανεώσεις/μεταφορές. Ανενεργό = τελείως κρυμμένο.'),
                            ]),
                    ]),
            ]);
    }

    /**
     * MYD-024 — an advisory, deliberately NOT a block.
     *
     * The AADE issuer block on an invoice is only vatNumber + country + branch, so
     * a company's name, address or ΔΟΥ changing cannot rewrite a filed invoice's
     * payload — the original audit finding was stricter than the protocol. The ΑΦΜ
     * is different: it IS the issuer identity AND the myDATA/provider credential
     * identity, so a new one means a new legal entity, not an edit. Same for ΓΕΜΗ.
     *
     * Blocking would be wrong: fixing a typo before the first filing is normal, and
     * a company that genuinely re-registers still has to be corrected somewhere. So
     * the warning appears only once something has actually been filed under the
     * current value, and it says what the operator is about to disagree with.
     */
    private static function identityChangeWarning(?Company $record, string $base): HtmlString
    {
        // Memoised with once(): the form asks for ΑΦΜ and again for ΓΕΜΗ, and each
        // call is two COUNTs. Same render, same answer. once() keys on the callable
        // + its bound object and is reset per request, so unlike a function-static
        // cache it cannot serve a stale count from a previous request under Octane.
        $filed = $record?->exists
            ? once(fn (): int => $record->filedDocumentCount())
            : 0;

        if ($filed === 0) {
            return new HtmlString(e($base));
        }

        return new HtmlString(
            e($base)
            .'<br><strong>⚠ Έχουν ήδη γίνει '.number_format($filed, 0, ',', '.')
            .' υποβολές στην ΑΑΔΕ με τα τρέχοντα στοιχεία.</strong> Αλλαγή ΑΦΜ/ΓΕΜΗ σημαίνει '
            .'κανονικά <em>νέα εταιρεία</em> (νέα διαπιστευτήρια myDATA/παρόχου, νέα σειρά '
            .'παραστατικών) — τα ήδη υποβληθέντα MARK παραμένουν στην ΑΑΔΕ με τα παλιά '
            .'στοιχεία. Άλλαξέ το μόνο για διόρθωση λάθους καταχώρισης.',
        );
    }

    /**
     * Invoice-type options for the three WHMCS default-type selectors (paid
     * invoice / receipt / unpaid). Monetary types only — a WHMCS auto-issue
     * default is always a real invoice/receipt, never a movement-only 9.x Δελτίο
     * Αποστολής (MYD-003). One shared query so the three selectors cannot drift.
     *
     * $currentId re-injects the value a field ALREADY holds when it is no longer
     * selectable (e.g. a legacy 9.x mis-stored before MYD-003), flagged, so the
     * admin SEES it instead of a silent blank that a save could quietly null —
     * mirrors DeliveryNoteForm. The runtime still refuses it at allocate().
     *
     * @return array<int, string>
     */
    private static function whmcsDefaultTypeOptions(?Company $record, ?int $currentId = null): array
    {
        if (! $record) {
            return [];
        }

        $opts = InvoiceType::query()
            ->where('company_id', $record->id)
            ->monetary()
            // Real customers' WHMCS invoices never default into an informal series.
            ->where('is_informal', false)
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name])
            ->all();

        if ($currentId && ! isset($opts[$currentId])) {
            $t = InvoiceType::query()->where('company_id', $record->id)->find($currentId);
            if ($t) {
                $opts[$t->id] = $t->code.' — '.$t->name.($t->is_informal ? ' (μη έγκυρο — άτυπη σειρά)' : ' (μη έγκυρο — Δελτίο Αποστολής)');
            }
        }

        return $opts;
    }

    /**
     * Labeled credential inputs for every configured provider (P3). Each is visible
     * only when its provider is the selected channel; secret fields are masked and
     * follow "blank = keep stored". State paths are the synthetic cfg_<key>_<field>
     * the SendChannelFormBridge assembles into the encrypted config blob.
     *
     * @return array<int, TextInput>
     */
    private static function providerCredentialFields(): array
    {
        $fields = [];
        foreach (config('ekdosi.einvoice.provider_fields', []) as $key => $defs) {
            foreach ((array) $defs as $name => $meta) {
                $input = TextInput::make("cfg_{$key}_{$name}")
                    ->label($meta['label'] ?? $name)
                    ->maxLength(255)
                    ->visible(fn (callable $get) => SendChannel::providerKey((string) $get('send_channel')) === $key);

                // A `url` endpoint field must be a public HTTPS host (SSRF / token+
                // payload exfiltration, PROV-017) — surface the same guard the transport
                // enforces at issue time, so a bad URL is caught on save.
                if ($meta['url'] ?? false) {
                    // Filament evaluates the OUTER closure (utility injection) and expects
                    // it to RETURN the Laravel rule closure — passing the rule closure
                    // directly makes Filament try to resolve $attribute as a dependency.
                    $input->rule(fn (): \Closure => function (string $attribute, $value, \Closure $fail) {
                        if (blank($value)) {
                            return;
                        }
                        try {
                            ProviderEndpointGuard::assertSafeBaseUrl((string) $value);
                        } catch (\RuntimeException $e) {
                            $fail($e->getMessage());
                        }
                    });
                }

                $isSecret = (bool) ($meta['secret'] ?? false);

                if ($isSecret) {
                    $input->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state));
                }

                // Say out loud whether something IS stored. A masked input that is
                // pre-filled looks EXACTLY like an empty one, so «τα κουκκάκια» left
                // the operator guessing whether the token was saved, still there, or
                // about to be wiped by this save. For a secret the last 4 chars are
                // shown (Stripe/AWS convention) so two tokens can be told apart
                // without revealing either.
                $input->helperText(fn (?Company $record): HtmlString => self::credentialStatus($record, $key, $name, $isSecret));

                $fields[] = $input;
            }
        }

        return $fields;
    }

    /**
     * The channel dropdown, plus the record's CURRENT channel re-injected (flagged) when
     * it is no longer one of the offered options.
     *
     * Filament validates a Select against its own options, so a stored channel outside
     * them fails validation on EVERY save — bricking the whole Company form for edits
     * that have nothing to do with e-invoicing, with no way to repair it through the UI.
     * A `einvoice_provider_key` that no longer appears in `provider_labels` reaches that
     * state without any whitespace at all: an ETL/hand-edited row, or simply deciding not
     * to ship a provider that some tenant is already on.
     *
     * Same idiom as whmcsDefaultTypeOptions() below: show the operator what the record
     * actually holds instead of a silent blank, and let them change it. Nothing here
     * makes an unknown provider usable — go-live-check and the filing path still refuse
     * it; this only keeps the form editable.
     *
     * @return array<string, string>
     */
    private static function sendChannelOptions(?Company $record): array
    {
        $options = SendChannel::options(config('ekdosi.einvoice.provider_labels', []));

        if ($record === null) {
            return $options;
        }

        $current = SendChannel::fromCompany($record);
        if ($current !== '' && ! isset($options[$current])) {
            $options[$current] = $current.' — άγνωστος πάροχος (μη έγκυρος· διάλεξε άλλον)';
        }

        return $options;
    }

    /**
     * «Είναι όντως αποθηκευμένο;» for one provider-credential field, read from the
     * record's stored config (not from form state — the point is what is ON DISK).
     */
    private static function credentialStatus(?Company $record, string $providerKey, string $field, bool $secret): HtmlString
    {
        if ($record === null) {
            return new HtmlString('Νέα εταιρεία — τίποτα αποθηκευμένο ακόμη.');
        }

        // The config blob is FLAT and belongs to the record's CURRENT provider. When the
        // operator has switched the channel to a different provider, a shared field name
        // (base_url exists on more than one) would otherwise make us announce the OLD
        // provider's value as safely stored — while dehydrate() starts that provider's
        // blob empty on save. Say what will actually happen instead.
        $currentKey = trim((string) $record->einvoice_provider_key);
        $config = is_array($record->einvoice_provider_config) ? $record->einvoice_provider_config : [];
        $stored = $config[$field] ?? null;

        // Switching to a DIFFERENT provider: the stored blob is the old one's and is not
        // carried over — say so rather than announcing it as this provider's.
        if ($currentKey !== '' && $currentKey !== $providerKey) {
            return new HtmlString(
                '<strong>⚠ Δεν έχει αποθηκευτεί για αυτόν τον πάροχο.</strong> '
                .'Με την αποθήκευση τα στοιχεία του προηγούμενου παρόχου <strong>διαγράφονται '
                .'οριστικά</strong> (δεν κρατιέται αντίγραφο) — κράτα τα αλλού αν τα χρειάζεσαι.'
            );
        }

        $shown = is_string($stored) && $stored !== ''
            ? ($secret ? self::maskSecret($stored) : e(mb_strimwidth($stored, 0, 60, '…')))
            : null;

        // No provider currently selected on the record (π.χ. «Καθόλου»): the blob is kept
        // but nothing records WHOSE it is, so don't vouch for it either way.
        if ($currentKey === '' && $shown !== null) {
            return new HtmlString(
                '<strong>Υπάρχει αποθηκευμένη τιμή από προηγούμενη ρύθμιση</strong> ('
                .$shown.'). Έλεγξέ την πριν αποθηκεύσεις.'
            );
        }

        if ($shown === null) {
            return new HtmlString('<strong>⚠ Δεν έχει αποθηκευτεί.</strong> Συμπλήρωσέ το και πάτα «Αποθήκευση».');
        }

        // States what is ON DISK right now — deliberately no promise about what this
        // save will do, because the helper can't see a replacement the operator has
        // just typed into the input and would otherwise say «δεν το πειράζει» about a
        // value that is being replaced.
        return new HtmlString('<strong>✓ Αποθηκευμένο</strong> ('.$shown.').');
    }

    /**
     * A short fingerprint so two tokens can be told apart at a glance.
     *
     * NOT a confidentiality control, and it must not be described as one: this form
     * pre-fills the credential inputs with the real values (that is what makes
     * ->revealable() work), so the full secret is already in the page for anyone who
     * can open it. The fingerprint exists to answer «ποιο token είναι αυτό;» in the
     * helper line, not to hide anything. Confidentiality here rests entirely on the
     * screen being super_admin-only (ADMIN_FORBIDDEN_RESOURCES).
     *
     * Short secrets are masked outright anyway, so the line never reads as if it were
     * showing a meaningful part of a tiny value.
     */
    private static function maskSecret(string $secret): string
    {
        return mb_strlen($secret) < 8
            ? str_repeat('•', 4)
            : '••••'.e(mb_substr($secret, -4));
    }

    private static function providerTestAction(): FormAction
    {
        return FormAction::make('test_provider')
            ->label('Έλεγχος σύνδεσης')
            ->icon('heroicon-o-bolt')
            ->action(function (?Company $record) {
                if (! $record) {
                    Notification::make()->title('Αποθήκευσε πρώτα την εταιρεία, μετά δοκίμασε.')->warning()->send();

                    return;
                }

                $key = (string) $record->einvoice_provider_key;
                $transport = app(ProviderTransportRegistry::class)->for($key);

                if ($transport instanceof NullProviderTransport) {
                    Notification::make()
                        ->title('Ο πάροχος δεν έχει ενεργοποιηθεί ακόμη')
                        ->body('Η τεχνική σύνδεση με τον πάροχο «'.($key ?: '—').'» ενεργοποιείται σε επόμενη έκδοση. Τα στοιχεία αποθηκεύονται κανονικά στο μεταξύ.')
                        ->warning()->send();

                    return;
                }

                try {
                    $ok = $transport->ping(ProviderCredentials::fromCompany($record));
                } catch (\Throwable $e) {
                    Notification::make()->title('Αποτυχία σύνδεσης με τον πάροχο')->body($e->getMessage())->warning()->send();

                    return;
                }

                $ok
                    ? Notification::make()
                        ->title('Ο πάροχος είναι προσβάσιμος')
                        ->body('Η διεύθυνση απαντά — έλεγχος μόνο διαθεσιμότητας. ΔΕΝ επαληθεύει διαπιστευτήρια ή υπόλοιπο (quota)· αυτά ελέγχονται στην πρώτη πραγματική αποστολή.')
                        ->success()->send()
                    : Notification::make()->title('Ο πάροχος απέρριψε τα στοιχεία')->body('Έλεγξε τα διαπιστευτήρια του παρόχου.')->danger()->send();
            });
    }

    /**
     * A "Test … connection" footer button for one myDATA environment.
     *
     * It tests the SAVED credentials for the given environment (sandbox
     * or production) regardless of the tenant's selected mode — so an
     * operator can verify either set without flipping the dropdown. The
     * credentials must be saved first (the action reads $record, not the
     * live form state) — same caveat as every other "Test" button here.
     */
    private static function mydataTestAction(string $name, string $label, MyDataMode $environment): FormAction
    {
        return FormAction::make($name)
            ->label($label)
            ->icon('heroicon-o-bolt')
            ->action(function (?Company $record) use ($environment) {
                if (! $record) {
                    Notification::make()
                        ->title('Save the company first, then test.')
                        ->warning()->send();

                    return;
                }
                try {
                    $ok = (new MyDataSubmitter($record))->testConnection($environment);
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
                        ->body('Credentials accepted by AADE ('.$environment->value.' endpoint).')
                        ->success()->send();
                } else {
                    Notification::make()
                        ->title('myDATA rejected the credentials')
                        ->body('Check the '.$environment->value.' aade-user-id and Ocp-Apim-Subscription-Key fields.')
                        ->danger()->send();
                }
            });
    }
}
