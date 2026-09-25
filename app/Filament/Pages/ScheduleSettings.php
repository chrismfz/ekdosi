<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\SettingsCluster;
use App\Services\TenantRoleProvisioner;
use App\Support\Settings\SystemSettings;
use BackedEnum;
use Closure;
use Cron\CronExpression;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * «Χρονοπρογραμματιστής» settings (the «Σύστημα» area, slice 3) — flip a
 * scheduled task on/off from the UI instead of editing `EKDOSI_SCHEDULE_*` + a
 * redeploy. Writes the `system_settings` store; `routes/console.php` reads it at
 * run-time (`->when()`), env staying the default.
 *
 * SUPER_ADMIN-ONLY: these flags drive the cron, which serves EVERY tenant — a
 * deploy-wide system control, not company-scoped. (company_admin = company-scoped;
 * super_admin = everything.) Each save is audited (activity log + updated_by).
 *
 * Persistence rule: a toggle equal to its env/config default REMOVES any override
 * row (reverts to env-tracking); only a deviation is stored — so the table stays
 * minimal and env keeps driving untouched flags.
 */
class ScheduleSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Λειτουργία';

    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.pages.schedule-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /**
     * Toggle definitions: schedule key (also the config/DB suffix) → [label, help,
     * danger]. `danger` marks tasks that act on AADE/customers unattended.
     *
     * @var array<string, array{0: string, 1: string, 2: bool}>
     */
    private const TASKS = [
        // Email & ουρά
        'mail_sweep_enabled' => ['Καθαρισμός κολλημένων email', 'Επαναφέρει σε «failed» όσα email κόλλησαν από crash του worker.', false],
        'queue_heartbeat_enabled' => ['Παλμός ουράς (heartbeat)', 'Στέλνει μικρό job κάθε 5′ — δείχνει αν ζει ο queue worker.', false],
        'resend_failed_emails_enabled' => ['Επαναποστολή αποτυχημένων email', 'Ξαναβάζει στην ουρά τιμολόγια που απέτυχαν. Άναψέ το αφού σταθεροποιηθεί το SMTP.', false],
        // WHMCS
        'whmcs_fetch_enabled' => ['WHMCS — άντληση εκκρεμών', 'Φέρνει πληρωμένα/αδήλωτα WHMCS τιμολόγια στο inbox (μόνο staging, ΔΕΝ δηλώνει στην ΑΑΔΕ).', false],
        'whmcs_fetch_unpaid_enabled' => ['WHMCS — άντληση ΑΠΛΗΡΩΤΩΝ', 'Φέρνει ΑΠΛΗΡΩΤΑ WHMCS τιμολόγια πελατών «invoice-before-pay» (επί πιστώσει) στο inbox — μόνο staging.', false],
        'whmcs_auto_issue_enabled' => ['WHMCS — αυτόματη έκδοση', 'Δηλώνει ΑΥΤΟΜΑΤΑ στην ΑΑΔΕ για πελάτες άμεσης τιμολόγησης σε οπλισμένους tenants. Διπλό κλειδί: ισχύει ΚΑΙ ανά εταιρεία → «Εταιρείες» → πεδίο «άμεση τιμολόγηση».', true],
        'whmcs_payment_sync_enabled' => ['WHMCS — συγχρονισμός πληρωμών', 'Όταν ένα επί-πιστώσει WHMCS τιμολόγιο πληρωθεί στο WHMCS, καταγράφει την πληρωμή στο ekdosi και κλείνει την οφειλή. Γράφει ΜΟΝΟ στο ekdosi (ποτέ στο WHMCS)· καταγράφει μόνο ανοιχτά υπόλοιπα.', false],
        'whmcs_payment_reconcile_enabled' => ['WHMCS — εντοπισμός πληρωμών (read-only)', 'Εντοπίζει ποια ανοιχτά επί-πιστώσει τιμολόγια πληρώθηκαν στο WHMCS και τα δείχνει στο dashboard + στη σελίδα «Συγχρονισμός πληρωμών» με ειδοποίηση. ΔΕΝ γράφει χρήμα — ο χειριστής τα κλείνει με ένα κλικ.', false],
        // myDATA
        'mydata_reconcile_enabled' => ['myDATA — αντιπαραβολή πωλήσεων', 'Καθημερινός read-only έλεγχος local↔ΑΑΔΕ.', false],
        'mydata_vat_picture_enabled' => ['myDATA — εικόνα ΦΠΑ', 'Ανανεώνει το cache του widget «Εικόνα από myDATA» (βαρύ AADE pull).', false],
        'mydata_fetch_expenses_enabled' => ['myDATA — άντληση εξόδων (read-only)', 'Ανανεώνει την αντιπαραβολή εξόδων κάθε λίγες ώρες ώστε το badge «αδέσποτα έξοδα» να είναι φρέσκο. ΔΕΝ δημιουργεί εγγραφές. Διπλό κλειδί: ισχύει ΚΑΙ ανά εταιρεία → «Ρυθμίσεις εταιρείας» → «Αυτόματη άντληση εξόδων».', false],
        'mydata_console_refresh_enabled' => ['myDATA — ανανέωση κονσόλας (όλα)', 'Ζεσταίνει ΟΛΑ τα δεδομένα της Κονσόλας myDATA (Πωλήσεις/Έξοδα/Ε3/Εικόνα ΦΠΑ) ώστε να ανοίγει φρέσκια. Το βαρύτερο AADE pull — καλύπτει και τα «εικόνα ΦΠΑ»/«άντληση εξόδων», οπότε άφησέ τα κλειστά αν ανάψεις αυτό. ΔΕΝ δημιουργεί εγγραφές.', false],
        // Ψηφιακή Διακίνηση (ΔΑ)
        'delivery_fetch_inbound_enabled' => ['Ψηφιακό ΔΑ — άντληση εισερχόμενων', 'Φέρνει στο staging «Εισερχόμενα Διακίνησης» τα Δελτία Αποστολής που έκοψαν ΑΛΛΟΙ σε βάρος μας (παραλαβές), ανά myDATA-readable εταιρεία. Read-only — ΔΕΝ αποδέχεται/απορρίπτει/εκδίδει τίποτα (μένουν operator-gated).', false],
        'delivery_refresh_status_enabled' => ['Ψηφιακό ΔΑ — έλεγχος κατάστασης', 'Ξαναδιαβάζει από την ΑΑΔΕ την κατάσταση των ανοιχτών δελτίων/ΤΔΑ μας (σε διακίνηση, αναμένεται ο παραλήπτης κ.λπ.) ώστε να φαίνεται όταν ο πελάτης σκανάρει το QR ή ο μεταφορέας παραδώσει. Δεν δηλώνει τίποτα στην ΑΑΔΕ — ενημερώνει μόνο την τοπική κατάσταση· αν ένα ΤΔΑ βρεθεί ακυρωμένο στην ΑΑΔΕ, το ακυρώνει και τοπικά (όπως το χειροκίνητο «Έλεγχος κατάστασης»).', false],
        // myDATA — μητρώα (προμηθευτές/πελάτες)
        'suppliers_sync_enabled' => ['myDATA — συγχρονισμός προμηθευτών', 'Χτίζει το μητρώο Προμηθευτών από τα ΑΦΜ εκδοτών των RequestDocs, ανά myDATA εταιρεία. Read-from-AADE, γράφει ΜΟΝΟ νέους προμηθευτές (idempotent· δεν αγγίζει τιμολόγια/έξοδα/χρήμα). Παράθυρο: τελευταίος μήνας.', false],
        'customers_sync_enabled' => ['myDATA — συγχρονισμός πελατών', 'Χτίζει το μητρώο Πελατών από τους counterpart ΑΦΜ των πωλήσεων (RequestTransmittedDocs), ανά myDATA εταιρεία. Read-from-AADE, γράφει ΜΟΝΟ νέους πελάτες. Παράθυρο: τελευταίοι 12 μήνες (βαρύτερο pull).', false],
        'aade_status_refresh_enabled' => ['ΑΑΔΕ — έλεγχος κατάστασης ΑΦΜ πελατών', 'Χαλαρός εβδομαδιαίος έλεγχος (GSIS) αν το ΑΦΜ κάθε πελάτη είναι ενεργό/ανενεργό — λίγα ΑΦΜ/φορά, μόνο όσα δεν ελέγχθηκαν πρόσφατα (σεβασμός ορίων GSIS). Διπλό κλειδί: ισχύει ΚΑΙ ανά εταιρεία → «Εταιρείες» → «AADE registry (GSIS)» → «Περιοδικός έλεγχος». Read-only — δεν αλλάζει το δικό σου «ενεργός πελάτης».', false],
        // Αντίγραφα ασφαλείας
        'backup_run_enabled' => ['Backup — λήψη', 'Τρέχει το spatie backup:run (όλη η ΒΔ). Άφησέ το κλειστό αν τα backups τα τρέχει το systemd/cron.', false],
        'backup_cleanup_enabled' => ['Backup — καθαρισμός', 'spatie backup:clean — εφαρμόζει την πολιτική διατήρησης.', false],
        'backup_monitor_enabled' => ['Backup — παρακολούθηση', 'spatie backup:monitor — ελέγχει φρεσκάδα/μέγεθος των αντιγράφων.', false],
        'company_backups_enabled' => ['Backup ανά εταιρία', 'Per-tenant pipeline (Phase 4) — τρέχει ωριαία, κάθε εταιρία στη δική της συχνότητα.', false],
        // Υπηρεσίες & ειδοποιήσεις
        'overdue_notifications_enabled' => ['Ειδοποιήσεις ληξιπρόθεσμων', 'Καθημερινό «καμπανάκι» για ληξιπρόθεσμα τιμολόγια (χωρίς email).', false],
        'invoice_reminders_enabled' => ['Υπενθυμίσεις πληρωμής (email)', 'Καθημερινές υπενθυμίσεις προς πελάτες, μόνο για εταιρείες που τις έχουν ενεργοποιήσει στις «Ρυθμίσεις εταιρείας».', false],
        'leads_notify_due_enabled' => ['Leads — υπενθύμιση επόμενου βήματος', 'Καθημερινό «καμπανάκι» στον χειριστή για leads με επόμενο βήμα σήμερα ή ληξιπρόθεσμο (χωρίς email).', false],
        'service_renewals_enabled' => ['Ανανεώσεις υπηρεσιών (πρόχειρα)', 'Δημιουργεί ΠΡΟΧΕΙΡΑ τιμολόγια ανανέωσης για συμβόλαια που λήγουν. ΔΕΝ δηλώνει αυτόματα.', true],
        'service_dunning_enabled' => ['Dunning υπηρεσιών', 'Auto suspend/terminate ληξιπρόθεσμων συμβολαίων. Πραγματικός διακόπτης = το per-product dunning_enabled.', false],
        'intent_expiry_enabled' => ['Πληρωμές — λήξη εκκρεμών intents', 'Σημειώνει «Έληξε» τα εγκαταλελειμμένα online payment intents της Πύλης πέρα από το όριο, ώστε οι «Εκκρεμείς Πληρωμές Πύλης» να μένουν πραγματικές. Μόνο αλλαγή κατάστασης — μια καθυστερημένη επιβεβαίωση εξοφλεί κανονικά.', false],
        'ai_reminders_enabled' => ['AI Βοηθός — υπενθυμίσεις', 'Παραδίδει τις ώριμες (operator-confirmed) υπενθυμίσεις του AI «Βοηθού» ως ειδοποιήσεις-καμπανάκι. Τρέχει κάθε λεπτό.', false],
        // Υποστήριξη & domains
        'tickets_poll_imap_enabled' => ['Υποστήριξη — polling email (IMAP)', 'Διαβάζει τα mailboxes των τμημάτων υποστήριξης (IMAP) και δρομολογεί εισερχόμενα email σε tickets.', false],
        'domain_sync_enabled' => ['Συγχρονισμός domains', 'Συγχρονίζει καταστάσεις/λήξεις domains (νυχτερινό).', false],
        // Αναφορές
        'dashboard_metrics_enabled' => ['Αναφορές — προθέρμανση cache', 'Προϋπολογίζει τα δεδομένα των γραφημάτων «Αναφορές» ανά εταιρεία (τρέχον + προηγούμενο έτος) ώστε η σελίδα να ανοίγει από warm cache αντί να ξανα-υπολογίζει (~25s). Read-only — δεν δημιουργεί εγγραφές.', false],
    ];

    /**
     * Section grouping (heading → list of task keys).
     *
     * @var array<string, list<string>>
     */
    private const SECTIONS = [
        'Email & ουρά εργασιών' => ['mail_sweep_enabled', 'queue_heartbeat_enabled', 'resend_failed_emails_enabled'],
        'WHMCS' => ['whmcs_fetch_enabled', 'whmcs_fetch_unpaid_enabled', 'whmcs_auto_issue_enabled', 'whmcs_payment_sync_enabled', 'whmcs_payment_reconcile_enabled'],
        'myDATA' => ['mydata_reconcile_enabled', 'mydata_vat_picture_enabled', 'mydata_fetch_expenses_enabled', 'mydata_console_refresh_enabled'],
        'Ψηφιακή Διακίνηση (ΔΑ)' => ['delivery_fetch_inbound_enabled', 'delivery_refresh_status_enabled'],
        'myDATA — μητρώα (προμηθευτές/πελάτες)' => ['suppliers_sync_enabled', 'customers_sync_enabled', 'aade_status_refresh_enabled'],
        'Αντίγραφα ασφαλείας' => ['backup_run_enabled', 'backup_cleanup_enabled', 'backup_monitor_enabled', 'company_backups_enabled'],
        'Υπηρεσίες & ειδοποιήσεις' => ['overdue_notifications_enabled', 'invoice_reminders_enabled', 'leads_notify_due_enabled', 'service_renewals_enabled', 'service_dunning_enabled', 'intent_expiry_enabled', 'ai_reminders_enabled'],
        'Υποστήριξη & domains' => ['tickets_poll_imap_enabled', 'domain_sync_enabled'],
        'Αναφορές' => ['dashboard_metrics_enabled'],
    ];

    /**
     * Timing knobs: schedule key (config/DB suffix) → [label, kind ('cron'|'time')].
     * The config value is the DEFAULT; a valid deviation is stored, an invalid one is
     * REJECTED on save (and, belt-and-braces, ignored by routes/console.php's
     * $scheduleCron/$scheduleTime so a stray row can never break schedule:run).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const TIMINGS = [
        'resend_failed_emails_cron' => ['Επαναποστολή αποτυχημένων email', 'cron'],
        'whmcs_fetch_cron' => ['WHMCS — άντληση εκκρεμών', 'cron'],
        'whmcs_fetch_unpaid_cron' => ['WHMCS — άντληση ΑΠΛΗΡΩΤΩΝ', 'cron'],
        'whmcs_auto_issue_cron' => ['WHMCS — αυτόματη έκδοση', 'cron'],
        'whmcs_payment_sync_cron' => ['WHMCS — συγχρονισμός πληρωμών', 'cron'],
        'whmcs_payment_reconcile_cron' => ['WHMCS — εντοπισμός πληρωμών', 'cron'],
        'mydata_reconcile_time' => ['myDATA — αντιπαραβολή πωλήσεων', 'time'],
        'mydata_vat_picture_cron' => ['myDATA — εικόνα ΦΠΑ', 'cron'],
        'mydata_fetch_expenses_cron' => ['myDATA — άντληση εξόδων', 'cron'],
        'mydata_console_refresh_cron' => ['myDATA — ανανέωση κονσόλας', 'cron'],
        'delivery_fetch_inbound_cron' => ['Ψηφιακό ΔΑ — άντληση εισερχόμενων', 'cron'],
        'delivery_refresh_status_cron' => ['Ψηφιακό ΔΑ — έλεγχος κατάστασης', 'cron'],
        'suppliers_sync_cron' => ['myDATA — συγχρονισμός προμηθευτών', 'cron'],
        'customers_sync_cron' => ['myDATA — συγχρονισμός πελατών', 'cron'],
        'aade_status_refresh_cron' => ['ΑΑΔΕ — έλεγχος κατάστασης ΑΦΜ πελατών', 'cron'],
        'intent_expiry_cron' => ['Πληρωμές — λήξη εκκρεμών intents', 'cron'],
        'overdue_notifications_time' => ['Ειδοποιήσεις ληξιπρόθεσμων', 'time'],
        'invoice_reminders_time' => ['Υπενθυμίσεις πληρωμής', 'time'],
        'leads_notify_due_time' => ['Leads — υπενθύμιση επόμενου βήματος', 'time'],
        'service_renewals_time' => ['Ανανεώσεις υπηρεσιών', 'time'],
        'service_dunning_time' => ['Dunning υπηρεσιών', 'time'],
        'backup_run_cron' => ['Backup — λήψη', 'cron'],
        'backup_cleanup_cron' => ['Backup — καθαρισμός', 'cron'],
        'backup_monitor_cron' => ['Backup — παρακολούθηση', 'cron'],
        'company_backups_cron' => ['Backup ανά εταιρία', 'cron'],
        'tickets_poll_imap_cron' => ['Υποστήριξη — polling email', 'cron'],
        'domain_sync_cron' => ['Συγχρονισμός domains', 'cron'],
        'dashboard_metrics_cron' => ['Αναφορές — προθέρμανση cache', 'cron'],
    ];

    public function mount(): void
    {
        $settings = app(SystemSettings::class);
        $state = [];
        foreach (array_keys(self::TASKS) as $key) {
            $state[$key] = $settings->bool("schedule.{$key}", (bool) config("ekdosi.schedule.{$key}"));
        }
        foreach (array_keys(self::TIMINGS) as $key) {
            $state[$key] = (string) $settings->string("schedule.{$key}", (string) config("ekdosi.schedule.{$key}"));
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (self::SECTIONS as $heading => $keys) {
            $toggles = [];
            foreach ($keys as $key) {
                [$label, $help, $danger] = self::TASKS[$key];
                $toggle = Toggle::make($key)->label($label)->helperText($help)->inline(false);
                if ($danger) {
                    $toggle->onColor('danger');
                }
                $toggles[] = $toggle;
            }
            $sections[] = Section::make($heading)->schema($toggles)->columns(2);
        }

        $timingFields = [];
        foreach (self::TIMINGS as $key => [$label, $kind]) {
            $default = (string) config("ekdosi.schedule.{$key}");
            $field = TextInput::make($key)
                ->label($label)
                ->placeholder($default)
                ->helperText(($kind === 'cron' ? 'cron 5 πεδίων' : 'ώρα ΩΩ:ΛΛ').' · προεπιλογή: '.$default)
                ->required()
                ->maxLength(40);

            if ($kind === 'cron') {
                $field->rules([
                    fn (): Closure => function (string $attribute, $value, Closure $fail): void {
                        if (! CronExpression::isValidExpression((string) $value)) {
                            $fail('Μη έγκυρη έκφραση cron (5 πεδία, π.χ. «*/15 * * * *»).');
                        }
                    },
                ]);
            } else {
                $field->rules(['regex:/^([01]\d|2[0-3]):[0-5]\d$/'])
                    ->validationMessages(['regex' => 'Μη έγκυρη ώρα — χρησιμοποίησε ΩΩ:ΛΛ (π.χ. 06:00).']);
            }

            $timingFields[] = $field;
        }
        $sections[] = Section::make('Χρονισμός (πότε τρέχει)')
            ->description('Cron 5 πεδίων ή ώρα ΩΩ:ΛΛ. Άφησε την προεπιλογή αν δεν χρειάζεται αλλαγή· μη έγκυρη τιμή απορρίπτεται.')
            ->schema($timingFields)
            ->columns(2)
            ->collapsed();

        return $schema->components($sections)->statePath('data');
    }

    public static function getNavigationLabel(): string
    {
        return 'Χρονοπρογραμματιστής';
    }

    public function getTitle(): string
    {
        return 'Ρυθμίσεις χρονοπρογραμματιστή';
    }

    /**
     * Καθολικές (deploy-wide) ρυθμίσεις — ισχύουν για ΟΛΟΥΣ τους tenants, παρόλο
     * που η σελίδα κάθεται κάτω από tenant-scoped URL. Το badge + το subheading το
     * κάνουν ρητό ώστε να μη μπερδεύεται με τις per-company «Ρυθμίσεις εταιρείας».
     */
    public static function getNavigationBadge(): ?string
    {
        return 'Καθολικό';
    }

    public function getSubheading(): ?string
    {
        return '⚠ Καθολικές ρυθμίσεις — ισχύουν για ΟΛΟΥΣ τους tenants του deployment (όχι μόνο την τρέχουσα εταιρεία). Οι per-company διακόπτες είναι στις «Ρυθμίσεις εταιρείας».';
    }

    /**
     * The on/off task keys the page exposes — the single source SchedulerCoverageTest
     * checks against config('ekdosi.schedule') so a new scheduled flag can't be added
     * without either surfacing it here or documenting it as intentionally hidden.
     *
     * @return list<string>
     */
    public static function taskKeys(): array
    {
        return array_keys(self::TASKS);
    }

    /** @return list<string> */
    public static function timingKeys(): array
    {
        return array_keys(self::TIMINGS);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Deploy-wide (cron) controls → super_admin only, like SystemHealth.
        return Filament::getTenant() !== null
            && $user !== null
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Αποθήκευση')
                ->icon('heroicon-o-check')
                ->action(fn () => $this->save()),

            // Backups are toggled here but the ARTIFACTS (which files, where, how
            // big, when) live on the health screen — link straight to that section.
            Action::make('viewBackups')
                ->label('Αρχεία αντιγράφων (Υγεία)')
                ->icon('heroicon-o-archive-box')
                ->color('gray')
                ->url(fn (): string => SystemHealth::getUrl().'#backups'),
        ];
    }

    public function save(): void
    {
        $settings = app(SystemSettings::class);
        $userId = auth()->id();
        $state = $this->form->getState();

        $changes = [];
        foreach (array_keys(self::TASKS) as $key) {
            $default = (bool) config("ekdosi.schedule.{$key}");
            $chosen = (bool) ($state[$key] ?? false);
            $before = $settings->bool("schedule.{$key}", $default);

            // Equal to the env default → drop the override (track env); else store it.
            if ($chosen === $default) {
                $settings->forget("schedule.{$key}");
            } else {
                $settings->setBool("schedule.{$key}", $chosen, $userId);
            }

            if ($chosen !== $before) {
                $changes[$key] = ['from' => $before, 'to' => $chosen];
            }
        }

        foreach (array_keys(self::TIMINGS) as $key) {
            $default = (string) config("ekdosi.schedule.{$key}");
            $chosen = trim((string) ($state[$key] ?? ''));
            $before = (string) $settings->string("schedule.{$key}", $default);

            // Equal to (or blank →) the config default → drop the override; else store.
            if ($chosen === '' || $chosen === $default) {
                $settings->forget("schedule.{$key}");
                $chosen = $default;
            } else {
                $settings->set("schedule.{$key}", $chosen, 'string', $userId);
            }

            if ($chosen !== $before) {
                $changes[$key] = ['from' => $before, 'to' => $chosen];
            }
        }

        if ($changes !== []) {
            activity('system_settings')
                ->causedBy(auth()->user())
                ->withProperties(['changes' => $changes])
                ->event('updated')
                ->log('Ενημέρωση ρυθμίσεων χρονοπρογραμματιστή');
        }

        Notification::make()
            ->title($changes === [] ? 'Καμία αλλαγή' : 'Οι ρυθμίσεις αποθηκεύτηκαν')
            ->success()
            ->send();
    }
}
