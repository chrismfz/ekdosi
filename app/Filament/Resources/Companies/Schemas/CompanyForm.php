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
use App\Models\Company;
use App\Models\InvoiceType;
use App\Services\AadeRegistryLookup;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Services\MailTemplateRenderer;
use App\Services\MyDataSubmitter;
use App\Services\TenantMailerFactory;
use App\Services\Whmcs\WhmcsBridgeClientFactory;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\SendChannel;
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
                                    ->options(SendChannel::options(config('ekdosi.einvoice.provider_labels', [])))
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
                                        .'<p>Το <strong>περιβάλλον ανάγνωσης ακολουθεί τον «Τρόπο αποστολής»</strong>: '
                                        .'<em>Δοκιμαστικό</em> → διαβάζει από Sandbox · <em>Παραγωγή</em> → από Production. '
                                        .'Γι\' αυτό σε δοκιμαστικό βλέπεις μόνο τα λίγα test παραστατικά του sandbox.</p>'
                                        .'</div>'
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
                                    ])
                                    ->columns(2),

                                Section::make('Mail templates')
                                    ->description('Subject and body for the customer-facing invoice mail. Use placeholders: {tenant_name}, {invoice_code}, {invoice_type}, {issued_at}, {customer_name}, {total}, {mark}, {verify_url}, {mark_section}.')
                                    ->schema([
                                        TextInput::make('mail_subject_template')
                                            ->label('Subject template')
                                            ->maxLength(191)
                                            ->placeholder(MailTemplateRenderer::DEFAULT_SUBJECT_TEMPLATE)
                                            ->helperText('Leave blank to use the default. Single line.'),
                                        Textarea::make('mail_body_template')
                                            ->label('Body template')
                                            ->rows(10)
                                            ->placeholder(MailTemplateRenderer::DEFAULT_BODY_TEMPLATE)
                                            ->helperText('Plain text with placeholders. HTML is escaped to plain text at send time (security).'),
                                    ]),

                                Section::make('Outbound mail routing')
                                    ->description('Where mails come from + who else gets BCC\'d for the audit trail.')
                                    ->schema([
                                        Toggle::make('auto_email_on_mydata_accept')
                                            ->label('Auto-email customer when AADE accepts')
                                            ->helperText('When a myDATA submission returns VALID, queue an email with the PDF to the customer. Off for sandbox/training tenants.')
                                            ->columnSpanFull(),
                                        // G6: the non-myDATA counterpart — fires when a draft
                                        // is finalized on a tenant that doesn't file via
                                        // myDATA (provider 'none' / Estonian / mode off).
                                        Toggle::make('auto_email_on_issue')
                                            ->label('Auto-email customer on issue (non-myDATA)')
                                            ->helperText('When a draft is finalized on a tenant that does NOT file via myDATA, queue an email with the PDF. Per-customer opt-out lives on each customer. Off = global kill-switch (e.g. for testing).')
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
                                            ->label('Skip WHMCS invoices dated before')
                                            ->native(false)
                                            ->displayFormat('Y-m-d')
                                            ->helperText('IMPORTANT for long-running tenants with historical test data. Set to your ekdosi-cutover date (e.g. when you started filing via this app). Invoices dated before this are silently skipped by Fetch + Preview. Leave blank only if your WHMCS is fresh / has no historical noise.'),
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

                                // G8 phase 2: γκρινιάρης auto-issue. DANGER zone —
                                // files legal documents unattended. Two-key armed:
                                // this toggle + the scheduler flag
                                // (EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE).
                                Section::make('Αυτόματη έκδοση (γκρινιάρης)')
                                    ->description('Όταν ενεργοποιηθεί, οι πληρωμένες εγγραφές του Inbox για πελάτες με σήμανση «άμεσης έκδοσης» (γκρινιάρης) εκδίδονται + υποβάλλονται ΑΥΤΟΜΑΤΑ στην ΑΑΔΕ από το προγραμματισμένο whmcs:auto-issue — μόνο οι σαφείς μονομερείς εγγραφές· οτιδήποτε αμφίβολο μένει στο Inbox για τον χειριστή. ΠΡΟΣΟΧΗ: εκδίδει νομικά παραστατικά χωρίς έγκριση.')
                                    ->schema([
                                        Toggle::make('whmcs_auto_issue_immediate')
                                            ->label('Αυτόματη έκδοση για γκρινιάρηδες')
                                            ->default(false)
                                            ->helperText('Απαιτεί ΚΑΙ τον γενικό διακόπτη του scheduler (EKDOSI_SCHEDULE_WHMCS_AUTO_ISSUE) ΚΑΙ ορισμένο προεπιλεγμένο τύπο παραστατικού παρακάτω. Με OFF (προεπιλογή) δεν εκδίδεται τίποτα αυτόματα — η εγγραφή απλώς επισημαίνεται «Άμεσο» στο Inbox.'),
                                        Select::make('whmcs_default_invoice_type_id')
                                            ->label('Προεπιλεγμένος τύπος παραστατικού (αυτόματη έκδοση)')
                                            ->options(fn (?Company $record) => $record
                                                ? InvoiceType::query()
                                                    ->where('company_id', $record->id)
                                                    ->orderBy('code')
                                                    ->get()
                                                    ->mapWithKeys(fn (InvoiceType $t) => [$t->id => $t->code.' — '.$t->name])
                                                    ->toArray()
                                                : [])
                                            ->searchable()
                                            ->helperText('Ο τύπος που χρησιμοποιεί η αυτόματη έκδοση. Χωρίς αυτόν, η αυτόματη έκδοση παραλείπει τον tenant (δεν μαντεύει ποτέ τον τύπο). Η χειροκίνητη επιλογή στο Inbox δεν επηρεάζεται.'),
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
                                                    'griniaris' => 'Γκρινιάρης / άμεση έκδοση (griniaris)',
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
                    ]),
            ]);
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

                if ($meta['secret'] ?? false) {
                    $input->password()
                        ->revealable()
                        ->dehydrated(fn (?string $state): bool => filled($state));
                }

                $fields[] = $input;
            }
        }

        return $fields;
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
                        ->body('Η διεύθυνση απαντά. Τα διαπιστευτήρια επαληθεύονται οριστικά στην πρώτη πραγματική αποστολή.')
                        ->success()->send()
                    : Notification::make()->title('Ο πάροχος απέρριψε τα στοιχεία')->body('Έλεγξε τα διαπιστευτήρια του παρόχου.')->danger()->send();
            });
    }

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
