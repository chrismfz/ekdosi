<?php

namespace App\Filament\Pages;

use App\Services\TenantRoleProvisioner;
use App\Support\Settings\SystemSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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
        'whmcs_auto_issue_enabled' => ['WHMCS — αυτόματη έκδοση', 'Δηλώνει ΑΥΤΟΜΑΤΑ στην ΑΑΔΕ για γκρινιάρηδες πελάτες σε οπλισμένους tenants. Διπλό κλειδί με την per-tenant ρύθμιση.', true],
        // myDATA
        'mydata_reconcile_enabled' => ['myDATA — αντιπαραβολή πωλήσεων', 'Καθημερινός read-only έλεγχος local↔ΑΑΔΕ.', false],
        'mydata_vat_picture_enabled' => ['myDATA — εικόνα ΦΠΑ', 'Ανανεώνει το cache του widget «Εικόνα από myDATA» (βαρύ AADE pull).', false],
        // Αντίγραφα ασφαλείας
        'backup_run_enabled' => ['Backup — λήψη', 'Τρέχει το spatie backup:run (όλη η ΒΔ). Άφησέ το κλειστό αν τα backups τα τρέχει το systemd/cron.', false],
        'backup_cleanup_enabled' => ['Backup — καθαρισμός', 'spatie backup:clean — εφαρμόζει την πολιτική διατήρησης.', false],
        'backup_monitor_enabled' => ['Backup — παρακολούθηση', 'spatie backup:monitor — ελέγχει φρεσκάδα/μέγεθος των αντιγράφων.', false],
        'company_backups_enabled' => ['Backup ανά εταιρία', 'Per-tenant pipeline (Phase 4) — τρέχει ωριαία, κάθε εταιρία στη δική της συχνότητα.', false],
        // Υπηρεσίες & ειδοποιήσεις
        'overdue_notifications_enabled' => ['Ειδοποιήσεις ληξιπρόθεσμων', 'Καθημερινό «καμπανάκι» για ληξιπρόθεσμα τιμολόγια (χωρίς email).', false],
        'service_renewals_enabled' => ['Ανανεώσεις υπηρεσιών (πρόχειρα)', 'Δημιουργεί ΠΡΟΧΕΙΡΑ τιμολόγια ανανέωσης για συμβόλαια που λήγουν. ΔΕΝ δηλώνει αυτόματα.', true],
        'service_dunning_enabled' => ['Dunning υπηρεσιών', 'Auto suspend/terminate ληξιπρόθεσμων συμβολαίων. Πραγματικός διακόπτης = το per-product dunning_enabled.', false],
    ];

    /**
     * Section grouping (heading → list of task keys).
     *
     * @var array<string, list<string>>
     */
    private const SECTIONS = [
        'Email & ουρά εργασιών' => ['mail_sweep_enabled', 'queue_heartbeat_enabled', 'resend_failed_emails_enabled'],
        'WHMCS' => ['whmcs_fetch_enabled', 'whmcs_auto_issue_enabled'],
        'myDATA' => ['mydata_reconcile_enabled', 'mydata_vat_picture_enabled'],
        'Αντίγραφα ασφαλείας' => ['backup_run_enabled', 'backup_cleanup_enabled', 'backup_monitor_enabled', 'company_backups_enabled'],
        'Υπηρεσίες & ειδοποιήσεις' => ['overdue_notifications_enabled', 'service_renewals_enabled', 'service_dunning_enabled'],
    ];

    public function mount(): void
    {
        $settings = app(SystemSettings::class);
        $state = [];
        foreach (array_keys(self::TASKS) as $key) {
            $state[$key] = $settings->bool("schedule.{$key}", (bool) config("ekdosi.schedule.{$key}"));
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

    public static function getNavigationGroup(): ?string
    {
        return 'Σύστημα';
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
