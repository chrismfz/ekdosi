<?php

namespace App\Filament\Pages;

use App\Filament\Resources\UpdateRuns\UpdateRunResource;
use App\Models\UpdateRun;
use App\Services\TenantRoleProvisioner;
use App\Services\Updates\UpdateChecker;
use App\Support\OperatorHealth\OperatorHealthReport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * «Υγεία συστήματος» — the web face of `php artisan ops:health`, so an admin
 * without terminal access sees the same liveness picture: queue worker heartbeat,
 * scheduled-task last-runs, backups, mail, WHMCS + myDATA per tenant, disk. Pure
 * read-only — renders `OperatorHealthReport::build()` (one source for CLI + web).
 *
 * SUPER_ADMIN-ONLY: this is system/infra + CROSS-TENANT (every company's WHMCS +
 * myDATA status), so a company_admin must NOT see it — they'd read other tenants'
 * data. company_admin = company-scoped; super_admin = everything. Part of the
 * «Σύστημα» area.
 */
class SystemHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-heart';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.pages.system-health';

    /** @var array<string, mixed> */
    public array $report = [];

    /** @var array<string, mixed> READ-ONLY update-check status (UpdateChecker). */
    public array $update = [];

    /** Short TTL: cheap page-loads/re-mounts reuse the last walk; «Ανανέωση» busts it. */
    private const CACHE_KEY = 'system_health.report';

    private const CACHE_TTL = 30; // seconds

    public function mount(): void
    {
        // On first paint, reuse a recent report if one is cached — build()
        // walks the storage/backups trees + per-tenant queries, so we don't
        // want every mount/poll to re-run it. The refresh action forces fresh.
        $this->report = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => app(OperatorHealthReport::class)->build(),
        );

        // Read-only update status — NON-BLOCKING: read the last cached result only,
        // never a synchronous GitHub call inside mount (that could hang the page for
        // up to `timeout`). «Έλεγχος ενημερώσεων» forces a fresh fetch on demand.
        $this->update = app(UpdateChecker::class)->cached();
    }

    public static function getNavigationLabel(): string
    {
        return 'Υγεία συστήματος';
    }

    public function getTitle(): string
    {
        return 'Υγεία συστήματος';
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

        // System/cross-tenant page → super_admin only (not gated on a per-tenant
        // shield permission, which company_admin would also hold).
        return Filament::getTenant() !== null
            && $user !== null
            && app(TenantRoleProvisioner::class)->isSuperAdminAnywhere($user);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Ανανέωση')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(fn () => $this->refreshReport()),

            // Read-only: force a fresh GitHub check (busts the 6h cache). Never
            // applies an update — the upgrade stays with deploy/update.sh.
            Action::make('checkUpdates')
                ->label('Έλεγχος ενημερώσεων')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->checkUpdates()),

            // In-app apply (Phase 2). No arming flag: shows whenever a newer
            // release is actually available (for a private repo that needs a valid
            // token, so «URL/token → yes» is natural) and no run is in flight.
            // Creates a queued UpdateRun; the cron scheduler applies it out-of-band
            // (ekdosi:self-update). See docs/versioning-and-updates.md.
            Action::make('installUpdate')
                ->label('Εγκατάσταση ενημέρωσης')
                ->icon('heroicon-o-arrow-up-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->applyAvailable()
                    && ($this->update['update_available'] ?? false) === true
                    && ! UpdateRun::hasActive())
                ->requiresConfirmation()
                ->modalHeading('Εγκατάσταση ενημέρωσης')
                ->modalDescription(fn (): string => sprintf(
                    'Θα εγκατασταθεί η έκδοση %s (τρέχουσα: v%s). Πριν την εφαρμογή λαμβάνεται στιγμιότυπο ΒΔ και η εφαρμογή μπαίνει σε maintenance mode· η διαδικασία τρέχει από τον scheduler και μπορείς να την παρακολουθήσεις στις «Ενημερώσεις».',
                    (string) ($this->update['latest_version'] ?? '?'),
                    (string) ($this->update['current_version'] ?? '?'),
                ))
                ->modalSubmitActionLabel('Έναρξη ενημέρωσης')
                ->action(fn () => $this->installUpdate()),

            // Re-queue every failed job (the only write on this page). Hidden when
            // nothing failed so it doesn't tempt a no-op.
            Action::make('retryFailedJobs')
                ->label('Επανάληψη αποτυχημένων')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Θα ξαναμπούν στην ουρά όλες οι αποτυχημένες εργασίες (queue:retry all).')
                ->visible(fn (): bool => (int) ($this->report['queue']['failed_jobs'] ?? 0) > 0)
                ->action(fn () => $this->retryFailedJobs()),
        ];
    }

    public function refreshReport(): void
    {
        // Explicit operator action → always a fresh walk; reseed the cache so the
        // next mount within the TTL reuses it.
        $this->report = app(OperatorHealthReport::class)->build();
        Cache::put(self::CACHE_KEY, $this->report, self::CACHE_TTL);
    }

    public function checkUpdates(): void
    {
        $this->update = app(UpdateChecker::class)->check(fresh: true);

        $ok = ($this->update['ok'] ?? false) === true;
        $available = $this->update['update_available'] ?? false;

        Notification::make()
            ->title(match (true) {
                ! $ok => 'Ο έλεγχος ενημερώσεων απέτυχε',
                $available => 'Διαθέσιμη νέα έκδοση: '.$this->update['latest_version'],
                default => 'Είσαι στην πιο πρόσφατη έκδοση',
            })
            ->body(match (true) {
                ! $ok => $this->update['error'] ?? null,
                $available && ! $this->applyAvailable() => 'Η αναβάθμιση γίνεται από τον server: '
                    .($this->updateCommand() ?? 'deploy/update.sh <tag>'),
                default => null,
            })
            ->{$ok ? ($available ? 'warning' : 'success') : 'danger'}()
            ->send();
    }

    public function retryFailedJobs(): void
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->refreshReport();

        Notification::make()
            ->title('Οι αποτυχημένες εργασίες ξαναμπήκαν στην ουρά')
            ->success()
            ->send();
    }

    /**
     * Queue an in-app update: lock the release the operator just saw and create a
     * `queued` UpdateRun. The web request does NOT run the deploy — the cron
     * scheduler picks the row up via `ekdosi:self-update` (out-of-band, since the
     * update restarts the app). Guarded (super_admin via canAccess + the action's
     * available/update-visible/single-flight visibility); re-checked here.
     */
    /**
     * In-app apply is OFF by default (UPD-001…015, triage 2026-09-02) — the
     * supported upgrade is `deploy/update.sh <tag>` on the host. The check above
     * stays on; only the apply is disarmed. See UpdateRun::inAppApplyEnabled().
     */
    private function applyAvailable(): bool
    {
        return UpdateRun::inAppApplyEnabled()
            && (bool) config('ekdosi.updates.enabled', true)
            && filled(config('ekdosi.updates.repo'));
    }

    /**
     * The upgrade command an operator should actually run, with the release they
     * just saw filled in. Shown wherever we report that a new version exists — the
     * page must not just say «there is an update» and leave them looking for a
     * button that is deliberately not there.
     */
    /**
     * Public twin of applyAvailable() for the blade — the «upgrade from the server»
     * box and the install button are alternatives, never both. (applyAvailable() is
     * private and a blade cannot reach it.)
     */
    public function inAppApplyArmed(): bool
    {
        return $this->applyAvailable();
    }

    public function updateCommand(): ?string
    {
        $target = $this->update['latest_version'] ?? null;

        if (! is_string($target) || $target === '') {
            return null;
        }

        return 'deploy/update.sh v'.ltrim($target, 'vV');
    }

    public function installUpdate(): void
    {
        if (! $this->applyAvailable()) {
            return;
        }
        if (UpdateRun::hasActive()) {
            Notification::make()
                ->title('Υπάρχει ήδη ενημέρωση σε εξέλιξη')
                ->warning()
                ->send();

            return;
        }

        $target = $this->update['latest_version'] ?? null;
        if (! is_string($target) || $target === '') {
            Notification::make()
                ->title('Δεν υπάρχει διαθέσιμη έκδοση για εγκατάσταση')
                ->warning()
                ->send();

            return;
        }

        $run = UpdateRun::create([
            'status' => UpdateRun::STATUS_QUEUED,
            'strategy' => (string) config('ekdosi.updates.strategy', UpdateRun::STRATEGY_PHP),
            'from_version' => $this->update['current_version'] ?? null,
            'from_ref' => $this->update['current_sha'] ?? null,
            'to_version' => ltrim($target, 'vV'),
            'to_ref' => $target,
            'triggered_by_user_id' => auth()->id(),
        ]);

        Notification::make()
            ->title('Η ενημέρωση προγραμματίστηκε')
            ->body('Θα εφαρμοστεί από τον scheduler. Παρακολούθησε την πρόοδο εδώ.')
            ->success()
            ->send();

        $this->redirect(UpdateRunResource::getUrl('view', ['record' => $run]));
    }

    // ── view helpers (one place for status → colour/label + formatting) ──

    public function statusColor(?string $status): string
    {
        return match ($status) {
            'ok' => 'success',
            'stale', 'missing', 'warn' => 'warning',
            'fail', 'failed', 'error' => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(?string $status): string
    {
        return match ($status) {
            'ok' => 'ΟΚ',
            'stale' => 'Παλιό',
            'missing' => 'Άγνωστο',
            'fail', 'failed', 'error' => 'Σφάλμα',
            'warn' => 'Προσοχή',
            'running' => 'Εκτελείται',
            default => (string) ($status ?? '—'),
        };
    }

    public function ago(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        try {
            return Carbon::parse($iso)->diffForHumans();
        } catch (\Throwable) {
            return (string) $iso;
        }
    }

    public function bytes(?int $n): string
    {
        if ($n === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $v = (float) $n;
        while ($v >= 1024 && $i < count($units) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, 1).' '.$units[$i];
    }

    public function ms(?int $n): string
    {
        if ($n === null) {
            return '—';
        }
        if ($n < 1000) {
            return $n.' ms';
        }

        return round($n / 1000, 1).' s';
    }
}
