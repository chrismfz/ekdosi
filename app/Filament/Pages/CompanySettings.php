<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\SettingsCluster;
use App\Filament\Support\MailTemplateFields;
use App\Models\Company;
use App\Models\CompanyBackupSetting;
use App\Models\InvoiceReminder;
use App\Services\Reminders\ReminderMessage;
use App\Services\Reminders\ReminderSettings;
use App\Support\MyData\ClassificationGuidance;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * «Ρυθμίσεις εταιρείας» — the SELF-SERVICE subset of a tenant's own settings, so a
 * company_admin can manage their PDF branding, invoice-mail templates/from, the
 * auto-email switches, and their backup cadence WITHOUT needing the panel-global
 * CompanyResource (which is super_admin-only — it also exposes the cross-tenant
 * roster, tenant identity/ΑΦΜ, and create/delete-company).
 *
 * Deliberately SAFE-subset only. The credential/infra knobs stay super_admin
 * (CompanyResource → company tab): myDATA / GSIS / WHMCS secrets, the tenant's
 * SMTP server, e-invoice provider, and the sensitive backup policy (encryption
 * passphrase, remote destinations, retention). Here a company_admin only flips
 * enable + cadence — local-only, encrypted-by-default backups; remote targets
 * and the passphrase remain the administrator's call.
 *
 * Gating: `View:CompanySettings` (Shield page permission). company_admin gets it
 * automatically (it's not in ADMIN_FORBIDDEN_RESOURCES — that list is
 * User/Company/Role, and «CompanySettings» is a distinct page resource), operator
 * does not, super_admin via the Gate::before bypass. After deploy:
 * `php artisan shield:generate` then re-provision roles (shield:sync-super-admin
 * or the role-picker) so the permission row exists + is granted.
 *
 * SECURITY: save() writes ONLY the explicit whitelists below — it never
 * mass-assigns raw form state, so a crafted Livewire payload can't reach a
 * non-whitelisted column (e.g. mydata_subscription_key or whmcs_api_secret).
 */
class CompanySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Εταιρεία';

    protected static ?int $navigationSort = 96;

    protected string $view = 'filament.pages.company-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * The safe Company columns this page may write. Anything not here is
     * super_admin territory (CompanyResource).
     *
     * @var list<string>
     */
    private const COMPANY_FIELDS = [
        // MYD-006: the income-classification policy (safe to self-serve — it is
        // business identity, not a credential; the go-live gate requires it).
        'business_activity_type',
        'logo_path',
        'pdf_footer_text',
        'show_customer_balance_on_pdf',
        'mail_subject_template',
        'mail_body_template',
        'auto_email_on_mydata_accept',
        'auto_email_on_issue',
        'mail_from_address',
        'mail_from_name',
        // Προσωπικό / ΕΡΓΑΝΗ — the safe, non-credential knobs (the e-ΕΦΚΑ creds,
        // environment and auto-submission switches stay super_admin, Company form).
        'leave_notify_email',
        'ergani_card_requires_kiosk',
        // i18n Slice 0: the tenant's fallback communication language (drives emails
        // when a customer has no explicit language/country). Business identity, not
        // a credential → safe to self-serve.
        'default_language',
        'mydata_auto_fetch_expenses',
        // Payment reminders (dunning).
        'reminders_enabled',
        'reminders_mode',
        'reminders_since',
        'reminder_pre_due_days',
        'reminder_first_days',
        'reminder_second_days',
        'reminder_final_days',
        'reminder_min_balance',
        'reminder_attach_pdf',
        'reminder_templates',
    ];

    /**
     * The safe CompanyBackupSetting columns — enable + cadence only. Encryption
     * mode, passphrase, destinations and retention stay with super_admin.
     *
     * @var list<string>
     */
    private const BACKUP_FIELDS = ['enabled', 'frequency', 'run_at_time'];

    public function mount(): void
    {
        $company = $this->tenant();
        $backup = $company->backupSetting;

        $state = [];
        foreach (self::COMPANY_FIELDS as $field) {
            $state[$field] = $company->{$field};
        }
        $state['reminders_mode'] = $company->reminders_mode ?: 'review';   // required radio; the column's own default
        $state['backup_enabled'] = (bool) ($backup->enabled ?? false);
        $state['backup_frequency'] = $backup->frequency ?? 'off';
        $state['backup_run_at_time'] = $backup->run_at_time ?? '02:00';

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Είδος δραστηριότητας (κατηγοριοποίηση εσόδων)')
                    ->description(ClassificationGuidance::INTRO)
                    ->visible(fn (): bool => in_array($this->tenant()->einvoice_provider, ['gr-mydata', 'gr-provider'], true))
                    ->schema([
                        // Not a form-level `required()` on purpose: it would block
                        // saving UNRELATED settings until chosen. The go-live gate is
                        // the authoritative blocker (MYD-006 acceptance) — here we
                        // surface + guide, so an operator saving mail templates isn't
                        // forced to also classify in the same submit.
                        Select::make('business_activity_type')
                            ->label('Είδος δραστηριότητας')
                            ->options(ClassificationGuidance::options())
                            ->placeholder('— δεν έχει επιλεγεί (απαιτείται πριν το go-live) —')
                            ->native(false)
                            ->helperText(fn (?string $state): HtmlString => MyDataCodeGuide::helperText(
                                ClassificationGuidance::hintFor($state)
                                    ?? 'Επίλεξε το είδος για να ταξινομούνται σωστά τα αγαθά στην ΑΑΔΕ (§8.6). Απαιτείται πριν το go-live.'
                            ))
                            ->live()
                            ->columnSpanFull(),
                    ]),

                Section::make('Εμφάνιση PDF')
                    ->description('Logo + κείμενο υποσέλιδου + προεπιλογή «υπολοίπου πελάτη» σε κάθε PDF αυτής της εταιρείας.')
                    ->schema([
                        FileUpload::make('logo_path')
                            ->label('Logo')
                            ->image()
                            ->disk('public')
                            ->directory('logos')
                            ->maxSize(2048)
                            ->acceptedFileTypes(['image/png', 'image/jpeg'])
                            ->helperText('PNG ή JPEG, έως 2MB. (Όχι SVG — ο DomPDF είναι raster.)')
                            ->imagePreviewHeight('80'),
                        Textarea::make('pdf_footer_text')
                            ->label('Κείμενο υποσέλιδου PDF')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Εμφανίζεται στο υποσέλιδο κάθε PDF. Απλό κείμενο.'),
                        Toggle::make('show_customer_balance_on_pdf')
                            ->label('Υπόλοιπο πελάτη στο PDF')
                            ->helperText('Προεπιλογή: τυπώνει block «Νέο υπόλοιπο» στα τιμολόγια επί πιστώσει. Ανά πελάτη υπερισχύει η δική του ρύθμιση.'),
                    ])
                    ->columns(2),

                Section::make('Πρότυπα email τιμολογίου')
                    ->description('Θέμα + σώμα του email προς τον πελάτη. Placeholders: {tenant_name}, {invoice_code}, {invoice_type}, {issued_at}, {customer_name}, {total}, {mark}, {verify_url}, {mark_section}.')
                    ->schema([
                        MailTemplateFields::subject(
                            'Πρότυπο θέματος',
                            'Προσυμπληρωμένο με το προεπιλεγμένο πρότυπο — άλλαξέ το ελεύθερα. Με «Επαναφορά προεπιλογής» (ή αδειάζοντάς το) επανέρχεται η προεπιλογή. Μία γραμμή.',
                        ),
                        MailTemplateFields::body(
                            'Πρότυπο σώματος',
                            'Προσυμπληρωμένο με το προεπιλεγμένο πρότυπο — άλλαξέ το ελεύθερα. Με «Επαναφορά προεπιλογής» επανέρχεται η προεπιλογή. Απλό κείμενο με placeholders· το HTML μετατρέπεται σε plain text κατά την αποστολή (ασφάλεια).',
                        ),
                    ]),

                Section::make('Αποστολή email')
                    ->description('Πότε φεύγει αυτόματα email και με ποιον αποστολέα. (Ο SMTP server της εταιρείας ρυθμίζεται από τον διαχειριστή συστήματος.)')
                    ->schema([
                        Toggle::make('auto_email_on_mydata_accept')
                            ->label('Auto-email στον πελάτη όταν η ΑΑΔΕ αποδεχτεί')
                            ->helperText('Όταν μια υποβολή (απευθείας myDATA Ή μέσω παρόχου, π.χ. InvoSign) γίνει αποδεκτή/VALID, μπαίνει στην ουρά email με το PDF προς τον πελάτη.')
                            ->columnSpanFull(),
                        Toggle::make('auto_email_on_issue')
                            ->label('Auto-email στον πελάτη κατά την έκδοση (εκτός ηλεκτρονικής υποβολής)')
                            ->helperText('Όταν οριστικοποιείται πρόχειρο σε εταιρεία που ΔΕΝ φιλάρει ηλεκτρονικά (ούτε myDATA ούτε πάροχος) — αλλιώς το email φεύγει στην αποδοχή. Ανά πελάτη υπάρχει opt-out.')
                            ->columnSpanFull(),
                        TextInput::make('mail_from_address')
                            ->label('Διεύθυνση αποστολέα (From)')
                            ->email()
                            ->maxLength(191)
                            ->placeholder(config('mail.from.address'))
                            ->helperText('Ο αποστολέας που βλέπει ο παραλήπτης. Πρέπει να ταιριάζει με το SPF/DKIM του SMTP. Κενό = προεπιλογή εφαρμογής.'),
                        TextInput::make('mail_from_name')
                            ->label('Όνομα αποστολέα (From)')
                            ->maxLength(191)
                            ->placeholder(fn (): string => $this->tenant()->name),
                        // i18n Slice 0 self-service: the tenant fallback language for
                        // customer emails when the customer has no explicit language and
                        // no usable country (the customer/its country still win). The PDF
                        // stays frozen at issue; this affects emails only.
                        Select::make('default_language')
                            ->label('Προεπιλεγμένη γλώσσα επικοινωνίας')
                            ->options([
                                'el' => 'Ελληνικά',
                                'en' => 'Αγγλικά',
                                'both' => 'Δίγλωσσο (GR/EN)',
                            ])
                            // Constrain server-side too (not just the dropdown): save()
                            // writes this column verbatim, so a crafted payload must not
                            // land a garbage locale that CustomerLanguage then reads.
                            ->rules(['nullable', 'in:el,en,both'])
                            ->placeholder('Αυτόματο (Ελληνικά)')
                            ->helperText('Fallback γλώσσα όταν ο πελάτης δεν έχει ρητή γλώσσα ούτε χώρα (ο πελάτης/η χώρα του υπερισχύουν). Ισχύει στα email· το PDF μένει «παγωμένο» στο έγγραφο.'),
                    ])
                    ->columns(2),

                Section::make('Προσωπικό — ΕΡΓΑΝΗ')
                    ->description('Άδειες και ψηφιακή κάρτα. Οι κωδικοί ΕΡΓΑΝΗ και η αυτόματη υποβολή ρυθμίζονται από τον διαχειριστή συστήματος.')
                    ->visible(fn (): bool => $this->tenant()->hasErgani())
                    ->schema([
                        TextInput::make('leave_notify_email')
                            ->label('Email λογιστή για τις άδειες')
                            ->email()
                            ->maxLength(191)
                            ->helperText('Κάθε άδεια που εγκρίνεται ή ανακαλείται στέλνεται εδώ. Κενό = δεν στέλνεται τίποτα.'),
                        Toggle::make('ergani_card_requires_kiosk')
                            ->label('Κάρτα μόνο στο γραφείο (tablet / QR)')
                            ->helperText('Αν είναι ενεργό, η κάρτα χτυπιέται μόνο από το tablet του γραφείου ή σκανάροντας το QR του — όχι με απλό κουμπί από το κινητό.'),
                    ])
                    ->columns(2),

                $this->remindersSection(),

                Section::make('Αυτόματη άντληση εξόδων (myDATA)')
                    ->description('Read-only ανανέωση της λίστας «αδέσποτων εξόδων» για ΑΥΤΗ την εταιρεία, ανά λίγες ώρες. Δεν δημιουργεί εγγραφές — η καταχώριση παραμένει χειροκίνητη.')
                    ->visible(fn (): bool => $this->tenant()->canReadMyData())
                    ->schema([
                        Toggle::make('mydata_auto_fetch_expenses')
                            ->label('Αυτόματη άντληση εξόδων για αυτή την εταιρεία')
                            ->helperText('Όταν είναι ενεργό, ο προγραμματιστής ανανεώνει αυτόματα τα «αδέσποτα έξοδα» αυτής της εταιρείας (μόνο ανανέωση λίστας, όχι καταχώριση). Ισχύει εφόσον ο διαχειριστής συστήματος έχει ενεργοποιήσει την αντίστοιχη προγραμματισμένη εργασία.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Αυτόματα αντίγραφα ασφαλείας')
                    ->description('Ενεργοποίηση + συχνότητα. Κρυπτογράφηση, συνθηματικό, απομακρυσμένοι προορισμοί και διατήρηση ρυθμίζονται από τον διαχειριστή συστήματος (καρτέλα εταιρείας).')
                    ->schema([
                        Toggle::make('backup_enabled')
                            ->label('Ενεργό')
                            ->helperText('Όταν είναι ενεργό, λαμβάνεται τοπικό αντίγραφο με την παρακάτω συχνότητα. Κρυπτογράφηση (συνθηματικό) και απομακρυσμένοι προορισμοί ρυθμίζονται από τον διαχειριστή συστήματος.'),
                        Select::make('backup_frequency')
                            ->label('Συχνότητα')
                            ->options(['off' => 'Ανενεργό', 'daily' => 'Καθημερινά', 'weekly' => 'Εβδομαδιαία', 'monthly' => 'Μηνιαία'])
                            ->required(),
                        TextInput::make('backup_run_at_time')
                            ->label('Ώρα (HH:MM)')
                            ->rule('date_format:H:i')
                            ->required(),
                    ])
                    ->columns(3),
            ])
            ->statePath('data');
    }

    public static function getNavigationLabel(): string
    {
        return 'Ρυθμίσεις εταιρείας';
    }

    public function getTitle(): string
    {
        return 'Ρυθμίσεις εταιρείας';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:CompanySettings');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Αποθήκευση')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),
        ];
    }

    /**
     * «Υπενθυμίσεις πληρωμής» — WHMCS-style: an optional reminder before the due
     * date, then 1st / 2nd / final after it, each N days from the due date (blank =
     * that stage is off), each with its own subject/body (blank = the default text,
     * in the customer's language).
     */
    private function remindersSection(): Section
    {
        // The after-due stages escalate: each set one must come later than the set
        // ones before it (equal/inverted days would send the «2η» before the «1η»).
        $afterDue = ['reminder_first_days', 'reminder_second_days', 'reminder_final_days'];
        $stageDays = fn (string $name, string $label, string $help): TextInput => TextInput::make($name)
            ->label($label)
            ->numeric()->integer()->minValue(0)->maxValue(365)
            ->suffix('ημέρες')
            ->placeholder('ανενεργή')
            ->helperText($help)
            ->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($name, $afterDue, $get): void {
                $at = array_search($name, $afterDue, true);
                if ($at === false || blank($value)) {
                    return;
                }
                foreach (array_slice($afterDue, 0, $at) as $earlier) {
                    if (filled($get($earlier)) && (int) $value <= (int) $get($earlier)) {
                        $fail('Πρέπει να είναι περισσότερες ημέρες από την προηγούμενη υπενθύμιση ('.(int) $get($earlier).').');

                        return;
                    }
                }
            });
        $templateLocale = ReminderSettings::templateLocaleOf($this->tenant());

        $templates = [];
        foreach (InvoiceReminder::STAGE_LABELS as $stage => $label) {
            if ($stage === InvoiceReminder::STAGE_MANUAL) {
                continue;
            }
            $templates[] = TextInput::make("reminder_templates.{$stage}.subject")
                ->label("{$label} — θέμα")
                ->maxLength(191)
                ->placeholder(fn (): string => trans("mail.reminder.{$stage}.subject", [], $templateLocale))
                ->columnSpanFull();
            $templates[] = Textarea::make("reminder_templates.{$stage}.body")
                ->label("{$label} — κείμενο")
                ->rows(5)
                ->placeholder(fn (): string => trans("mail.reminder.{$stage}.body", [], $templateLocale))
                ->columnSpanFull();
        }

        return Section::make('Υπενθυμίσεις πληρωμής')
            ->description('Email υπενθύμισης στον πελάτη για ανεξόφλητα τιμολόγια επί πιστώσει και για προτιμολόγια που του έχουν προσφερθεί (όχι για όσα προέρχονται από WHMCS). Όπως στο WHMCS: προαιρετικά μία πριν τη λήξη και μετά 1η / 2η / τελευταία. Τι στάλθηκε και τι περιμένει: «Υπενθυμίσεις».')
            ->collapsible()
            ->schema([
                Toggle::make('reminders_enabled')
                    ->label('Ενεργές υπενθυμίσεις')
                    ->dehydrateStateUsing(fn ($state): bool => (bool) $state)
                    ->live()
                    ->afterStateUpdated(function (bool $state, $set, $get): void {
                        if ($state && blank($get('reminders_since'))) {
                            $set('reminders_since', now()->toDateString());
                        }
                    })
                    ->columnSpanFull(),
                Radio::make('reminders_mode')
                    ->label('Αποστολή')
                    ->options([
                        'review' => 'Προς έγκριση — μπαίνουν στη σελίδα «Υπενθυμίσεις» και τις στέλνεις εσύ',
                        'auto' => 'Αυτόματα — φεύγουν μόνες τους',
                    ])
                    ->default('review')
                    ->required()
                    ->columnSpanFull(),
                DatePicker::make('reminders_since')
                    ->label('Για παραστατικά που λήγουν από')
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->requiredIf('reminders_enabled', true)
                    ->helperText('Ό,τι έληγε νωρίτερα δεν παίρνει υπενθύμιση — ώστε η ενεργοποίηση να μη στείλει «τελευταία υπενθύμιση» για παλιές οφειλές.'),
                TextInput::make('reminder_min_balance')
                    ->label('Ελάχιστο υπόλοιπο')
                    ->numeric()->minValue(0)
                    ->suffix('€')
                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : 0)   // blank = no minimum (NOT NULL column)
                    ->helperText('Κάτω από αυτό δεν στέλνεται υπενθύμιση.'),
                $stageDays('reminder_pre_due_days', 'Πριν τη λήξη', 'Ημέρες ΠΡΙΝ τη λήξη (φιλική υπενθύμιση).'),
                $stageDays('reminder_first_days', '1η υπενθύμιση', 'Ημέρες μετά τη λήξη.'),
                $stageDays('reminder_second_days', '2η υπενθύμιση', 'Ημέρες μετά τη λήξη.'),
                $stageDays('reminder_final_days', '3η (τελευταία)', 'Ημέρες μετά τη λήξη.'),
                Toggle::make('reminder_attach_pdf')
                    ->label('Επισύναψη του PDF του παραστατικού')
                    ->dehydrateStateUsing(fn ($state): bool => (bool) $state)
                    ->columnSpanFull(),
                Section::make('Κείμενα email')
                    ->description('Κενό = το προεπιλεγμένο κείμενο (φαίνεται αχνά). Τα δικά σου κείμενα πάνε σε πελάτες στη γλώσσα της εταιρείας· οι υπόλοιποι παίρνουν το προεπιλεγμένο στη γλώσσα τους. Placeholders: '.ReminderMessage::PLACEHOLDERS.'. Το {pay_section} γίνεται «πληρώστε online: …» μόνο αν η εταιρεία δέχεται online πληρωμές.')
                    ->collapsed()
                    ->schema($templates)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public function save(): void
    {
        $company = $this->tenant();
        $state = $this->form->getState();

        // Build the per-field diff as the {attributes,old} shape the activity feed
        // renders (Activity::changeLines reads the `attribute_changes` column —
        // hence withChanges(), NOT withProperties() which lands in `properties`
        // and shows blank).
        $new = [];
        $old = [];

        // ── Company safe subset (explicit whitelist, never raw mass-assign) ──
        $companyUpdate = [];
        foreach (self::COMPANY_FIELDS as $field) {
            // A field whose section is hidden (e.g. the myDATA controls for a
            // non-myDATA tenant) is absent from the submitted state — leave the
            // column untouched rather than nulling a NOT NULL boolean.
            if (! array_key_exists($field, $state)) {
                continue;
            }
            $value = $state[$field];
            $companyUpdate[$field] = $value;
            if ($this->normalize($company->{$field}) !== $this->normalize($value)) {
                $new[$field] = $this->normalize($value);
                $old[$field] = $this->normalize($company->{$field});
            }
        }
        $company->update($companyUpdate);

        // ── Backup enable + cadence (preserves the super_admin-owned columns:
        //    updateOrCreate touches ONLY these keys; passphrase/destinations/
        //    retention keep their existing values or table defaults) ──
        $backup = $company->backupSetting;
        $backupDefaults = ['enabled' => false, 'frequency' => 'off', 'run_at_time' => '02:00'];
        $backupUpdate = [
            'enabled' => (bool) ($state['backup_enabled'] ?? false),
            'frequency' => (string) ($state['backup_frequency'] ?? 'off'),
            'run_at_time' => (string) ($state['backup_run_at_time'] ?? '02:00'),
        ];
        // A NEW row created here gets secrets_mode='raw': a company_admin can't set
        // a passphrase (super_admin-only), so the table's 'passphrase' default +
        // null passphrase would make CompanyBackupRunner throw on EVERY run. raw =
        // unencrypted, local-only (no remote target without super_admin
        // destinations) — the safe self-service default. Existing rows are left as
        // the administrator configured them.
        if ($backup === null) {
            $backupUpdate['secrets_mode'] = 'raw';
        }
        foreach (self::BACKUP_FIELDS as $field) {
            $before = $backup->{$field} ?? $backupDefaults[$field];
            if ($this->normalize($before) !== $this->normalize($backupUpdate[$field])) {
                $new["backup_{$field}"] = $this->normalize($backupUpdate[$field]);
                $old["backup_{$field}"] = $this->normalize($before);
            }
        }
        CompanyBackupSetting::updateOrCreate(['company_id' => $company->getKey()], $backupUpdate);

        $changed = $new !== [];
        if ($changed) {
            activity('company_settings')
                ->performedOn($company)
                ->causedBy(auth()->user())
                ->withChanges(['attributes' => $new, 'old' => $old])
                ->event('updated')
                ->log('Ενημέρωση ρυθμίσεων εταιρείας');
        }

        Notification::make()
            ->title($changed ? 'Οι ρυθμίσεις αποθηκεύτηκαν' : 'Καμία αλλαγή')
            ->success()
            ->send();
    }

    /** The current tenant (canAccess guarantees it's a Company before we get here). */
    private function tenant(): Company
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            abort(403);
        }

        return $tenant;
    }

    /** Treat null and '' as equal so a blank field isn't logged as a spurious change. */
    private function normalize(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value;
        }

        return $value === null ? '' : $value;
    }
}
