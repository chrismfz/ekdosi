<?php

namespace App\Filament\Resources\Companies\Schemas;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\AadeRegistryLookup;
use App\Services\MailTemplateRenderer;
use App\Services\TenantMailerFactory;
use App\Services\Whmcs\WhmcsClientFactory;
use App\Services\Whmcs\WhmcsCustomerMatcher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Filament\Actions\Action as FormAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
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
                                                        ['html' => new \Illuminate\Support\HtmlString($html)],
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
                                                        'id'          => $whmcsClientId,
                                                        'userid'      => $whmcsClientId,
                                                        'email'       => $inv['email'] ?? null,
                                                        'firstname'   => $inv['firstname'] ?? null,
                                                        'lastname'    => $inv['lastname'] ?? null,
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
                                                try {
                                                    $client = app(WhmcsClientFactory::class)->for($record);
                                                    $ingestor = app(WhmcsInvoiceIngestor::class);
                                                    $minDate = $record->whmcs_invoice_min_date?->format('Y-m-d');
                                                    $list = $client->getPendingInvoices(limit: 100, minDate: $minDate);
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

                                                if (empty($list)) {
                                                    Notification::make()
                                                        ->title('Nothing to stage')
                                                        ->body('WHMCS returned no paid+unfiled invoices.')
                                                        ->success()->send();
                                                    return;
                                                }

                                                $created = 0;
                                                $updated = 0;
                                                $frozen = 0;
                                                $failed = 0;
                                                $abortReason = null;

                                                foreach ($list as $listRow) {
                                                    $invoiceId = (int) ($listRow['id'] ?? 0);
                                                    if ($invoiceId <= 0) {
                                                        $failed++;
                                                        continue;
                                                    }
                                                    try {
                                                        $payload = $client->getInvoiceWithClient($invoiceId);
                                                        if ($payload === null) {
                                                            $failed++;
                                                            continue;
                                                        }
                                                        $result = $ingestor->ingest($record, $payload);
                                                        if ($result->created) {
                                                            $created++;
                                                        } elseif ($result->auditPreserved) {
                                                            $frozen++;
                                                        } else {
                                                            $updated++;
                                                        }
                                                    } catch (WhmcsAuthenticationFailed $e) {
                                                        // Tenant-fatal: same logic as the artisan
                                                        // command. Abort the loop and surface the
                                                        // partial result.
                                                        $abortReason = 'WHMCS authentication failed: '.$e->getMessage();
                                                        break;
                                                    } catch (WhmcsUnreachable $e) {
                                                        $abortReason = 'WHMCS unreachable: '.$e->getMessage();
                                                        break;
                                                    } catch (WhmcsApiException $e) {
                                                        $failed++;
                                                    } catch (\Throwable $e) {
                                                        $failed++;
                                                    }
                                                }

                                                $summary = sprintf(
                                                    '%d new · %d refreshed · %d audit-frozen · %d failed',
                                                    $created, $updated, $frozen, $failed,
                                                );

                                                if ($abortReason !== null) {
                                                    Notification::make()
                                                        ->title('Aborted: '.$abortReason)
                                                        ->body('Partial result: '.$summary)
                                                        ->danger()->persistent()->send();
                                                } elseif ($failed > 0) {
                                                    Notification::make()
                                                        ->title('Fetched with partial failures')
                                                        ->body($summary)
                                                        ->warning()->persistent()->send();
                                                } else {
                                                    Notification::make()
                                                        ->title('Fetched into inbox')
                                                        ->body($summary.'. Open the WHMCS Inbox (coming in PR #32) to review and file.')
                                                        ->success()->send();
                                                }
                                            }),
                                    ]),

                                Section::make('Custom field mapping')
                                    ->description('Each WHMCS install assigns its own integer IDs to custom fields. Tell us which IDs carry which roles so we can read the right data when matching invoices and (in Stage B) building the myDATA payload.')
                                    ->schema([
                                        \Filament\Forms\Components\KeyValue::make('whmcs_custom_field_map')
                                            ->label(false)
                                            ->keyLabel('Role')
                                            ->valueLabel('WHMCS field id')
                                            ->addable(true)
                                            ->editableKeys(true)
                                            ->reorderable(false)
                                            ->helperText('Canonical roles: vatno (AFM), taxoffice (ΔΟΥ), occupation (Δραστηριότητα), griniaris (immediate-invoice flag), toinvoice (alternative billing-name). Leave empty if your WHMCS doesn\'t track a role.'),
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
}
