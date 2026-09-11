<?php

namespace App\Filament\Pages;

use App\Casts\MaybeEncrypted;
use App\Filament\Clusters\SettingsCluster;
use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use App\Services\Updates\UpdateChecker;
use App\Support\Settings\SystemSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Crypt;

/**
 * «Ρυθμίσεις συστήματος» (the «Σύστημα» area) — the deploy-wide global knobs that
 * aren't the scheduler (those live on ScheduleSettings). Same pattern as
 * ScheduleSettings: the `system_settings` store overrides, env/config is the
 * DEFAULT, only deviations are persisted (toggling back to default drops the row),
 * every change is audited.
 *
 * SUPER_ADMIN-ONLY: these drive platform-wide behaviour (auth policy, ops alerts),
 * not a single tenant — so they belong with the other deploy-wide «Σύστημα» pages.
 *
 * Editable knobs are wired LIVE: the code that reads them consults
 * SystemSettings with the config value as the default (AdminPanelProvider for 2FA,
 * RunScheduledCompanyBackups for the backup alert). `encrypt_secrets_at_rest` is
 * shown READ-ONLY on purpose — flipping the write-mode without re-encrypting
 * existing rows leaves silent mixed state, so the safe path is the
 * `secrets:reencrypt` command (surfaced as guidance, not a one-click footgun).
 */
class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $cluster = SettingsCluster::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Λειτουργία';

    protected static ?int $navigationSort = 97;

    protected string $view = 'filament.pages.general-settings';

    /** @var array<string, mixed> */
    public ?array $data = [];

    /** Read-only context surfaced in the blade (mailer + secrets posture). */
    public ?array $info = null;

    /**
     * Editable global knobs: setting key (under `system.`) → [config path, label,
     * help, type ('bool'|'string')]. Env/config is the default; the store overrides.
     *
     * @var array<string, array{0:string,1:string,2:string,3:string}>
     */
    private const KNOBS = [
        'require_2fa' => [
            'ekdosi.require_2fa',
            'Υποχρεωτικό 2FA (TOTP)',
            'Αναγκάζει κάθε χρήστη να ρυθμίσει two-factor στην επόμενη σύνδεση. Άναψέ το ΑΦΟΥ εγγραφούν όλοι, αλλιώς κλειδώνεις την ομάδα έξω.',
            'bool',
        ],
        'backup_alert_on_failure' => [
            'ekdosi.backup.alert_on_failure',
            'Ειδοποίηση email σε αποτυχία backup',
            'Στέλνει email όταν ένα προγραμματισμένο per-company backup αποτύχει/μένει μισό. (Πάντα γράφεται και Log::error.)',
            'bool',
        ],
        'backup_alert_email' => [
            'ekdosi.backup.alert_email',
            'Email(s) ειδοποίησης backup',
            'Παραλήπτες (χωρισμένοι με κόμμα). Κενό = όλοι οι super_admin με email.',
            'string',
        ],
        'error_alerts_enabled' => [
            'ekdosi.error_alerts.enabled',
            'Ειδοποιήσεις σφαλμάτων (email)',
            'Στέλνει email στους υπεύθυνους ops όταν συμβεί ανεπίληπτη εξαίρεση (deduped/throttled). Γράφεται πάντα και στο log.',
            'bool',
        ],
        'error_alert_email' => [
            'ekdosi.error_alerts.email',
            'Email(s) ειδοποίησης σφαλμάτων',
            'Παραλήπτες (κόμμα). Κενό = πέφτει στην αλυσίδα των backup alerts → super_admins.',
            'string',
        ],
        'error_alert_throttle_minutes' => [
            'ekdosi.error_alerts.throttle_minutes',
            'Throttle ειδοποιήσεων σφαλμάτων (λεπτά)',
            'Ίδιο σφάλμα → ένα email ανά τόσα λεπτά (αποτρέπει flood από βρόχο σφαλμάτων).',
            'string',
        ],
        'ai_enabled' => [
            'ekdosi.ai.enabled',
            'AI «Βοηθός» (καθολικός διακόπτης)',
            'Ο master διακόπτης — ακόμη κι αν μια εταιρία τον έχει ανοιχτό, μένει κλειστός αν εδώ είναι OFF. Χρειάζεται και ANTHROPIC_API_KEY στο .env.',
            'bool',
        ],
        'update_check_enabled' => [
            'ekdosi.updates.enabled',
            'Έλεγχος ενημερώσεων',
            'Read-only σύγκριση του build με το τελευταίο GitHub release (φαίνεται στην Υγεία συστήματος). Ποτέ δεν εφαρμόζει ενημέρωση.',
            'bool',
        ],
        'update_repo' => [
            'ekdosi.updates.repo',
            'Αποθετήριο ενημερώσεων (owner/repo)',
            'GitHub αποθετήριο του ελέγχου εκδόσεων, π.χ. «chrismfz/ekdosi». Κενό = χρησιμοποιείται το EKDOSI_UPDATE_REPO του .env.',
            'string',
        ],
        // NOTE: the update TOKEN is a secret — handled OUTSIDE this KNOBS map (it must
        // never be rendered into the form or compared as plaintext). See mount()/save().
    ];

    /**
     * String knobs where a BLANK field means «no override → use the default», not a
     * stored empty string. Only for knobs whose empty value is never meaningful
     * (update_repo — a cleared field must fall back to the .env repo, not persist ''
     * which would shadow the non-empty default). The email knobs are deliberately
     * NOT here: an explicit empty override IS meaningful there.
     *
     * @var list<string>
     */
    private const BLANK_REVERTS_TO_DEFAULT = ['update_repo'];

    public function mount(): void
    {
        $settings = app(SystemSettings::class);
        $state = [];
        foreach (self::KNOBS as $key => [$configPath, , , $type]) {
            $state[$key] = $type === 'bool'
                ? $settings->bool("system.{$key}", (bool) config($configPath))
                : (string) $settings->string("system.{$key}", config($configPath) ?? '');
        }
        // The token is a secret — NEVER pre-fill it. Empty means «keep as-is» on save.
        $state['update_token'] = '';
        $this->form->fill($state);
        $this->loadInfo();
    }

    /** Read-only posture: secrets at-rest mode + global/per-tenant mailer picture. */
    private function loadInfo(): void
    {
        $settings = app(SystemSettings::class);
        $globalMailer = (string) config('mail.default');
        $this->info = [
            'encrypt_at_rest' => (bool) config('ekdosi.secrets.encrypt_at_rest', false),
            'global_mailer' => $globalMailer,
            'global_mailer_host' => (string) config("mail.mailers.{$globalMailer}.host", ''),
            'global_mailer_sends' => ! in_array($globalMailer, ['log', 'array', 'null'], true),
            'tenants_with_smtp' => Company::query()->whereNotNull('mail_smtp_host')->where('mail_smtp_host', '!=', '')->count(),
            // Is an update token available (UI override OR .env)? Drives the token
            // field's helper + the «clear» action — WITHOUT ever exposing the value.
            'update_token_set' => filled($settings->string('system.update_token', config('ekdosi.updates.token'))),
            'update_token_overridden' => $settings->has('system.update_token'),
        ];
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ασφάλεια')
                    ->schema([
                        Toggle::make('require_2fa')
                            ->label(self::KNOBS['require_2fa'][1])
                            ->helperText(self::KNOBS['require_2fa'][2])
                            ->onColor('warning')
                            ->inline(false),
                    ]),
                Section::make('Αντίγραφα ασφαλείας')
                    ->schema([
                        // Read-only status of the GLOBAL spatie backup encryption
                        // (env-driven — changing it is a .env edit, not a live
                        // toggle). Makes the «χωρίς κωδικό» case visible + warned,
                        // next to the per-company «Μυστικά» knob on CompanyResource.
                        Placeholder::make('global_backup_encryption')
                            ->label('Κρυπτογράφηση καθολικών αντιγράφων (spatie backup:run)')
                            ->content(fn (): string => filled(config('backup.backup.password'))
                                ? '🔒 Κρυπτογραφημένα με κωδικό (env BACKUP_ARCHIVE_PASSWORD).'
                                : '⚠ ΧΩΡΙΣ κωδικό — τα καθολικά αντίγραφα γράφονται χωρίς κρυπτογράφηση. Όρισε BACKUP_ARCHIVE_PASSWORD στο .env για κρυπτογράφηση.')
                            ->helperText('Read-only (env). Αφορά ΜΟΝΟ το καθολικό spatie backup· τα per-company αντίγραφα έχουν δικό τους «Μυστικά» (κρυπτογραφημένα/raw) στην εταιρεία.'),
                        Toggle::make('backup_alert_on_failure')
                            ->label(self::KNOBS['backup_alert_on_failure'][1])
                            ->helperText(self::KNOBS['backup_alert_on_failure'][2])
                            ->inline(false),
                        TextInput::make('backup_alert_email')
                            ->label(self::KNOBS['backup_alert_email'][1])
                            ->helperText(self::KNOBS['backup_alert_email'][2])
                            ->placeholder('ops@example.gr, alerts@example.gr')
                            ->maxLength(500),
                    ])->columns(1),
                Section::make('Ειδοποιήσεις σφαλμάτων (Ops)')
                    ->schema([
                        Toggle::make('error_alerts_enabled')
                            ->label(self::KNOBS['error_alerts_enabled'][1])
                            ->helperText(self::KNOBS['error_alerts_enabled'][2])
                            ->inline(false),
                        TextInput::make('error_alert_email')
                            ->label(self::KNOBS['error_alert_email'][1])
                            ->helperText(self::KNOBS['error_alert_email'][2])
                            ->placeholder('ops@example.gr')
                            ->maxLength(500),
                        TextInput::make('error_alert_throttle_minutes')
                            ->label(self::KNOBS['error_alert_throttle_minutes'][1])
                            ->helperText(self::KNOBS['error_alert_throttle_minutes'][2])
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440),
                    ])->columns(1),
                Section::make('AI Βοηθός')
                    ->schema([
                        Toggle::make('ai_enabled')
                            ->label(self::KNOBS['ai_enabled'][1])
                            ->helperText(self::KNOBS['ai_enabled'][2])
                            ->onColor('warning')
                            ->inline(false),
                    ])->columns(1),
                Section::make('Ενημερώσεις')
                    ->description('Read-only έλεγχος έκδοσης έναντι GitHub — φαίνεται στην «Υγεία συστήματος». ΔΕΝ εφαρμόζει ποτέ ενημέρωση (αυτό μένει στο deploy/update.sh).')
                    ->schema([
                        Toggle::make('update_check_enabled')
                            ->label(self::KNOBS['update_check_enabled'][1])
                            ->helperText(self::KNOBS['update_check_enabled'][2])
                            ->inline(false),
                        TextInput::make('update_repo')
                            ->label(self::KNOBS['update_repo'][1])
                            ->helperText(self::KNOBS['update_repo'][2])
                            ->placeholder('chrismfz/ekdosi')
                            ->maxLength(200)
                            // «owner/repo» only — reject a pasted full URL / extra path
                            // segment (blank is allowed = «use the .env default»).
                            ->rule(static fn (): Closure => static function (string $attr, mixed $value, Closure $fail): void {
                                $v = (string) $value;
                                // «owner/repo» only — reject blankless malformed input, a pasted
                                // full URL / extra path segment, AND any «..» dot-segment (real
                                // GitHub names can't contain it; keeps it out of the API path).
                                if ($v !== '' && (! preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $v) || str_contains($v, '..'))) {
                                    $fail('Μορφή «owner/repo» (π.χ. chrismfz/ekdosi) — όχι πλήρες URL.');
                                }
                            }),
                        TextInput::make('update_token')
                            ->label('Token ενημερώσεων (GitHub PAT, read-only)')
                            ->helperText(fn (): string => ($this->info['update_token_set'] ?? false)
                                ? 'Υπάρχει αποθηκευμένο token — άφησέ το κενό για να μείνει ως έχει, ή γράψε νέο για αντικατάσταση. Χρειάζεται ΜΟΝΟ για ΙΔΙΩΤΙΚΟ repo (read-only PAT, contents:read).'
                                : 'Κανένα token — χρειάζεται ΜΟΝΟ για ΙΔΙΩΤΙΚΟ repo (read-only PAT, contents:read). Δημόσιο repo δουλεύει χωρίς.')
                            ->password()
                            ->revealable(false)
                            ->autocomplete(false)
                            ->maxLength(255),
                    ])->columns(1),
            ])
            ->statePath('data');
    }

    public static function getNavigationLabel(): string
    {
        return 'Ρυθμίσεις συστήματος';
    }

    public function getTitle(): string
    {
        return 'Ρυθμίσεις συστήματος';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Deploy-wide platform knobs → super_admin only, like the rest of «Σύστημα».
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

            // Drop the stored update-token override → the check falls back to
            // EKDOSI_UPDATE_TOKEN (.env) or anonymous. Only shown when an override
            // actually exists (the .env value can't be cleared from the UI).
            Action::make('clearUpdateToken')
                ->label('Καθαρισμός token ενημερώσεων')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->visible(fn (): bool => (bool) ($this->info['update_token_overridden'] ?? false))
                ->requiresConfirmation()
                ->modalDescription('Θα αφαιρεθεί το αποθηκευμένο token ενημερώσεων. Ο έλεγχος θα πέσει πίσω στο EKDOSI_UPDATE_TOKEN (.env) ή σε ανώνυμο (δημόσιο repo).')
                ->action(function (): void {
                    app(SystemSettings::class)->forget('system.update_token');
                    app(UpdateChecker::class)->forgetCache();
                    activity('system_settings')
                        ->causedBy(auth()->user())
                        ->withProperties(['changes' => ['update_token' => ['from' => '••••', 'to' => null]]])
                        ->event('updated')
                        ->log('Ενημέρωση ρυθμίσεων συστήματος');
                    $this->loadInfo();
                    Notification::make()->title('Το token αφαιρέθηκε')->success()->send();
                }),
        ];
    }

    public function save(): void
    {
        $settings = app(SystemSettings::class);
        $userId = auth()->id();
        $state = $this->form->getState();

        $changes = [];
        foreach (self::KNOBS as $key => [$configPath, , , $type]) {
            $default = $type === 'bool' ? (bool) config($configPath) : (string) (config($configPath) ?? '');
            $chosen = $type === 'bool' ? (bool) ($state[$key] ?? false) : (string) ($state[$key] ?? '');
            $before = $type === 'bool'
                ? $settings->bool("system.{$key}", $default)
                : (string) $settings->string("system.{$key}", $default);

            // Drop the override (track the env/config default) when the chosen value
            // EQUALS the default, OR is blank for a knob where blank is NOT a
            // meaningful value (BLANK_REVERTS_TO_DEFAULT — e.g. update_repo: you
            // always need a repo, so a cleared field means «use the default», never a
            // stored '' that would shadow the non-empty default and break the check).
            // For the email knobs blank IS meaningful (override to «no recipients»),
            // so their empty override is kept.
            $blankReverts = $chosen === '' && in_array($key, self::BLANK_REVERTS_TO_DEFAULT, true);
            if ($chosen === $default || $blankReverts) {
                $settings->forget("system.{$key}");
                $effective = $default;
            } else {
                $settings->set("system.{$key}", $chosen, $type, $userId);
                $effective = $chosen;
            }

            if ($effective !== $before) {
                $changes[$key] = ['from' => $before, 'to' => $effective];
            }
        }

        // Secret update token — outside the KNOBS loop so it is NEVER rendered back
        // and an EMPTY field means «keep the existing value», not «clear it». Stored
        // encrypted when ekdosi.secrets.encrypt_at_rest is on (same posture as every
        // other secret); the value is NEVER put in the audit log.
        $token = trim((string) ($state['update_token'] ?? ''));
        if ($token !== '') {
            // Plaintext by default (DR: keyless mysqldump restore), APP_KEY-encrypted
            // when ekdosi.secrets.encrypt_at_rest is on — same decision as the
            // MaybeEncrypted cast. CAVEAT: this KV secret is NOT swept by
            // `secrets:reencrypt` (that command is model/cast-driven), so after an
            // encrypt→plain migration on a NEW APP_KEY it must be re-entered. Low
            // impact (read-only PAT). Tracked in docs/BACKLOG.md.
            $settings->set(
                'system.update_token',
                MaybeEncrypted::shouldEncrypt() ? Crypt::encryptString($token) : $token,
                'string',
                $userId,
            );
            $changes['update_token'] = ['from' => '••••', 'to' => '•••• (ενημερώθηκε)'];
        }

        if ($changes !== []) {
            activity('system_settings')
                ->causedBy(auth()->user())
                ->withProperties(['changes' => $changes])
                ->event('updated')
                ->log('Ενημέρωση ρυθμίσεων συστήματος');
        }

        // A repo/token/enable change must invalidate the cached check result, so a
        // non-fresh read (scheduler, SystemHealth mount) stops serving the OLD repo's
        // comparison for up to cache_hours.
        if (isset($changes['update_repo']) || isset($changes['update_token']) || isset($changes['update_check_enabled'])) {
            app(UpdateChecker::class)->forgetCache();
        }

        // Never leave the typed secret sitting in the form state after a save.
        $this->data['update_token'] = '';
        $this->loadInfo();

        Notification::make()
            ->title($changes === [] ? 'Καμία αλλαγή' : 'Οι ρυθμίσεις αποθηκεύτηκαν')
            ->success()
            ->send();
    }
}
