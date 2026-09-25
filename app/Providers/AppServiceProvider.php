<?php

namespace App\Providers;

use App\Http\Controllers\Ergani\CardKioskController;
use App\Listeners\RecordAuthEvent;
use App\Models\User;
use App\Services\Leads\LeadMatcher;
use App\Services\Support\Inbound\ImapMailbox;
use App\Services\Support\Inbound\WebklexImapMailbox;
use App\Support\ErrorAlerts\ExceptionNotifier;
use App\Support\Settings\SystemSettings;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\PortalHost;
use Filament\Events\TenantSet;
use Filament\Forms\Components\DateTimePicker;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Ambient tenant for the CompanyScope global scope. Singleton so the
        // current company id lives for the whole request / command.
        $this->app->singleton(CompanyContext::class);

        // Tenant resolved from the customer-portal host (#1c). Singleton;
        // ResolvePortalHost overwrites it per portal request (no stale leak).
        $this->app->singleton(PortalHost::class);

        // Leads dedupe lookup — request-scoped so one form render shares a single
        // lookup across banner / DNC rule / create hook (memo inside the class).
        $this->app->scoped(LeadMatcher::class);

        // Deploy-wide settings store — singleton so the loaded map is shared
        // (one DB/cache read per process; the scheduler reads it on every tick).
        $this->app->singleton(SystemSettings::class);

        // Support/ticket IMAP transport (Πυλώνας E) — the real webklex mailbox behind
        // the ImapMailbox seam; tests bind a fake.
        $this->app->bind(
            ImapMailbox::class,
            WebklexImapMailbox::class,
        );
    }

    public function boot(): void
    {
        LeadMatcher::listenForWrites();

        // Security visibility (Πυλώνας ασφάλειας): record login/logout/failed
        // attempts on BOTH panels into `auth_events` so a super-admin can spot
        // recon / brute-force (incl. against non-existent usernames). The
        // subscriber is fully best-effort and never blocks authentication.
        Event::subscribe(RecordAuthEvent::class);

        // One password policy for the whole app: min 8 + not-found-in-a-known
        // breach (HaveIBeenPwned k-anonymity — only a SHA-1 prefix is sent, never
        // the password; fail-OPEN if the API is unreachable, so it can never lock
        // anyone out). Everything already validates with Password::defaults()
        // (operator Users, CustomerUsers, the portal reset), so defining it here
        // upgrades them all at once.
        //
        // The breach check is a BLOCKING outbound call, so it's skipped under the
        // test runner (offline suite) AND behind a config kill-switch — a deploy on
        // a locked-down network (where pwnedpasswords is unreachable) can turn it
        // off so every password op doesn't stall until the fail-open timeout.
        // min(8) is always enforced locally regardless.
        Password::defaults(function (): Password {
            $rule = Password::min(8);

            if ($this->app->runningUnitTests() || ! config('ekdosi.password_breach_check', true)) {
                return $rule;
            }

            return $rule->uncompromised();
        });

        /*
         * Hard block on destructive DB commands (db:wipe, migrate:fresh,
         * migrate:refresh) anywhere EXCEPT the automated test suite.
         *
         * Gated on the testing env — deliberately NOT app()->isProduction() —
         * because the prod box was mislabeled APP_ENV=local, which would have
         * left this guard (and Laravel's own prompts) OFF exactly when it was
         * needed: a stray `php artisan test` with cached prod config once ran
         * RefreshDatabase against the live MariaDB and wiped it. Tying the
         * guard to "not testing" makes it fire regardless of how APP_ENV is
         * set, while RefreshDatabase (APP_ENV=testing) still works in CI.
         * Plain `migrate` is unaffected — deploys keep working.
         */
        DB::prohibitDestructiveCommands(! $this->app->environment('testing'));

        /*
         * Since the v2.0.2 migration squash, `database/migrations/` is empty and
         * the schema comes from `database/schema/{connection}-schema.sql`.
         * Laravel resolves that file by CONNECTION NAME, not driver
         * (MigrateCommand::schemaPath()), and `loadSchemaState()` returns
         * SILENTLY when the file is missing. So on a connection we ship no
         * baseline for (config/database.php still defines `mysql`/`pgsql`, and
         * DbSnapshot/DbRestore/CustomerLedger do branch on the `mysql` driver),
         * `php artisan migrate` against a COMPLETELY EMPTY database now prints
         * «Nothing to migrate», exits 0, and leaves a broken install that looks
         * healthy. Pre-squash the same command built the whole schema.
         *
         * Refuse instead: NO BASELINE for the connection = the schema can never
         * be built, full stop. Deliberately NOT «and database/migrations/ is
         * also empty»: post-squash that directory only ever carries DELTAS on
         * top of the baseline, so the first new migration would silence the
         * guard forever and hand the operator the exact same empty-but-exit-0
         * database with one delta applied on top of nothing.
         *
         * Scope: CommandStarting is re-routed from the Symfony console
         * dispatcher, which Kernel::__construct wires up at boot EXCEPT while
         * `runningUnitTests()`. So outside the suite this covers every
         * entrypoint — the shell, `deploy/update.sh` and `SelfUpdate` (both
         * spawn `php artisan migrate`), and the in-process
         * `Artisan::call('migrate')` in InstallController alike. Inside the
         * suite it is inert unless a test opts in with `WithConsoleEvents`,
         * which SchemaBaselineTest does.
         */
        Event::listen(function (CommandStarting $event) {
            if ($event->command !== 'migrate') {
                return;
            }

            // NOTE: at CommandStarting the input is NOT yet bound to the
            // command definition, so getOption('database') sees an empty
            // definition and returns null. getParameterOption() reads the raw
            // tokens and works for both ArgvInput and ArrayInput.
            $connection = $event->input->getParameterOption('--database')
                ?: config('database.default');

            $hasBaseline = file_exists(database_path("schema/{$connection}-schema.sql"))
                || file_exists(database_path("schema/{$connection}-schema.dump"));

            if (! $hasBaseline) {
                throw new RuntimeException(
                    "Καμία πηγή schema για τη σύνδεση «{$connection}»: δεν υπάρχει "
                    ."database/schema/{$connection}-schema.sql. Από το squash v2.0.2 το baseline ΕΙΝΑΙ το "
                    .'schema — το database/migrations/ κρατά μόνο τα deltas από εκεί και πέρα, οπότε το '
                    .'migrate θα έχτιζε ΚΕΝΗ βάση (ή μόνο τα deltas) και θα έβγαινε με 0. Χρησιμοποίησε '
                    .'DB_CONNECTION=mariadb (ή sqlite), ή πρόσθεσε baseline για αυτή τη σύνδεση.'
                );
            }
        });

        /*
         * ekdosi MCP server OAuth (routes/ai.php): when Laravel Passport is
         * installed, render Laravel MCP's published consent view during the
         * OAuth authorization step (the screen where a logged-in operator
         * approves the claude.ai remote connector). Gated by class_exists so the
         * app boots fine before laravel/passport is required — the MCP endpoint
         * stays Sanctum-only until then. See MCP.md §6.
         */
        if (class_exists(Passport::class)
            && view()->exists('mcp.authorize')) {
            Passport::authorizationView(
                fn ($parameters) => view('mcp.authorize', $parameters)
            );
        }

        /*
         * Rate limit for the OAuth endpoints (applied group-wide via
         * config/passport.php `middleware`).
         *
         * `POST /oauth/register` is OAuth 2.1 Dynamic Client Registration — by
         * spec UNAUTHENTICATED, and deliberately left open here: that is how any
         * MCP client (the claude.ai connector, another LLM, a local agent)
         * self-registers. Open, however, also means it is the one OAuth route with
         * no credential to limit against, so an unthrottled endpoint lets anyone
         * create unlimited client rows. A genuine client registers ONCE, so a tight
         * per-IP cap costs nothing real and removes the flooding surface.
         *
         * Registration does NOT by itself grant access — a client still needs a
         * logged-in operator to approve it on the consent screen (which names the
         * client). Keeping the gate at «approve», not at «register», is what keeps
         * third-party and local clients workable.
         */
        // Office-tablet «ρολόι» (ψηφιακή κάρτα): SEPARATE buckets — the tablet's own
        // 15'' page reloads must never spend the punch budget (a 429 on a real punch
        // risks ΕΡΓΑΝΗ's 15' deadline). Punches are keyed per activated device.
        RateLimiter::for('card-kiosk-view', fn (Request $request): Limit => Limit::perMinute(30)->by('ckv|'.$request->ip()));
        // Above the per-tablet wrong-PIN pause (20/15'), so a burst meets THAT (clear
        // message + admin bell), not a generic 429.
        RateLimiter::for('card-kiosk-punch', fn (Request $request): Limit => Limit::perMinute(40)
            ->by('ckp|'.sha1((string) $request->cookie(CardKioskController::COOKIE)).'|'.$request->ip()));

        RateLimiter::for('oauth', function (Request $request): Limit {
            // 'oauth' is HARDCODED to match laravel/mcp, which registers the DCR
            // route at a hardcoded `oauth/register` (Server\Registrar::oauthRoutes()
            // default arg, called with no argument in routes/ai.php). Deriving it
            // from config('passport.path') instead would silently degrade the cap to
            // the loose branch the moment that config differed — reopening exactly
            // the flooding surface this limiter closes.
            // DISTINCT bucket keys per branch. ThrottleRequests keys a named limiter
            // as md5($name . $limit->key), so two branches sharing `by($ip)` would
            // share ONE counter: discovery/authorize hits would spend the
            // registration budget (false 429s), and — because the window TTL is set
            // only on a bucket's first hit — a cheap 60s-decay request could reopen
            // the bucket every minute and let ~9 registrations through each time,
            // dissolving the hourly cap.
            return $request->is('oauth/register')
                ? Limit::perHour(10)->by('oauth-register:'.$request->ip())
                : Limit::perMinute(60)->by('oauth:'.$request->ip());
        });

        /*
         * No-build panel utility CSS. The admin panel ships only Filament's
         * component CSS and registers no custom Tailwind theme, so utility classes
         * in our custom blade pages went unstyled. This hand-written supplement
         * (resources/css/panel.css) is copied into public + injected into the
         * panel <head> by `filament:assets` (which runs on every composer install
         * via filament:upgrade) — no npm / Vite build. See the file header.
         */
        FilamentAsset::register([
            Css::make('ekdosi-panel', resource_path('css/panel.css')),
        ]);

        /*
         * Greek date/time pickers panel-wide. Filament's date pickers default to a
         * NATIVE browser control (<input type="date">/<input type="datetime-local">),
         * whose DISPLAYED format follows the VIEWER'S BROWSER locale — so an operator
         * on an en-US browser saw the invoice «Ημερομηνία έκδοσης» as M/D/Y even though
         * APP_LOCALE=el (the value stored/printed was always correct — only the input
         * display was reversed). Force Filament's own JS picker (native:false) with an
         * el-GR display format so every date reads d/m/Y regardless of browser, weeks
         * starting Monday.
         *
         * Registered ONCE on the base DateTimePicker: BOTH DatePicker and TimePicker
         * extend it, and ComponentManager::configure() runs every ancestor's
         * configureUsing callback (parent→child), so this one registration reaches all
         * three. We set the DEFAULT-format slots — NOT an explicit ->displayFormat() —
         * so getDisplayFormat() still picks the RIGHT shape per field: d/m/Y for a
         * DatePicker, d/m/Y H:i for a DateTimePicker, and the untouched H:i for a
         * time-only TimePicker (an explicit displayFormat would wrongly force date
         * tokens onto a time field). A per-field ->displayFormat() still overrides it
         * (e.g. CompanyForm's ISO Y-m-d min-date pickers stay as they are).
         */
        DateTimePicker::configureUsing(fn (DateTimePicker $picker) => $picker
            ->native(false)
            ->firstDayOfWeek(1)
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i')
            ->defaultDateTimeWithSecondsDisplayFormat('d/m/Y H:i:s'));

        /*
         * @gup('Κείμενο') — Greek ALL-CAPS without τόνος, for PDF/print labels.
         * CSS text-transform:uppercase keeps the accent (wrong in Greek + ugly in
         * DomPDF); this echoes App\Support\GreekText::upper() instead.
         */
        Blade::directive('gup', fn (string $expr) => "<?php echo e(\App\Support\GreekText::upper($expr)); ?>");

        /*
         * super_admin is GLOBAL (the operator), not a per-tenant role: a user who
         * holds super_admin in ANY tenant bypasses every policy in EVERY tenant.
         * So the owner sees everything in a freshly created/restored company the
         * moment they're attached — no per-company super_admin assignment needed
         * (which also kills the role-picker chicken-and-egg). Data isolation is
         * unaffected: CompanyScope still filters tenant-owned queries to the
         * current Filament tenant — only the PERMISSION bypass is global. Per-
         * tenant company_admin/operator roles are unchanged (they scope non-super
         * users). isSystemSuperAdmin() is a single memoised query, cheap on this
         * hot path.
         */
        Gate::before(function ($user) {
            return ($user instanceof User && $user->isSystemSuperAdmin()) ? true : null;
        });

        /*
         * Sync Filament's current tenant into Spatie's PermissionRegistrar
         * so `hasRole()` / `hasPermissionTo()` scope queries to the right
         * team. Without this, a user's role in tenant A would also satisfy
         * checks in tenant B, defeating the whole point of teams mode.
         *
         * IMPORTANT: hook on the TenantSet event, NOT Filament::serving().
         * serving() fires before the tenant middleware resolves the
         * current tenant, so Filament::getTenant() returns null there
         * and the team id never gets set. TenantSet fires AFTER the
         * tenant is identified, which is exactly when the gate bypass
         * for super_admin needs the right team scope.
         */
        /*
         * OPS-3/OPS-9: a queue job that exhausts its retries lands in
         * failed_jobs and previously notified no one (the worker swallows the
         * exception). Route JobFailed through the same deduped/throttled ops
         * alert as unhandled exceptions. Best-effort — the listener is guarded
         * against the test runner and never throws.
         */
        Queue::failing(function (JobFailed $event): void {
            // The suite fails jobs on purpose — don't enqueue alerts for those.
            if ($this->app->runningUnitTests()) {
                return;
            }
            // Fully best-effort: a hiccup resolving the job name/queue must never
            // throw inside the worker's own failure path.
            try {
                app(ExceptionNotifier::class)->reportFailedJob(
                    $event->job->resolveName(),
                    $event->exception,
                    $event->connectionName,
                    $event->job->getQueue() ?? 'default',
                );
            } catch (\Throwable) {
                // swallow — the job already failed; alerting is secondary.
            }
        });

        Event::listen(
            TenantSet::class,
            function (TenantSet $event) {
                app(PermissionRegistrar::class)
                    ->setPermissionsTeamId($event->getTenant()->getKey());

                // Pin the ambient company so the CompanyScope global scope
                // auto-filters raw tenant-owned model queries made anywhere
                // inside the panel request (actions, pages, widgets, jobs
                // dispatched synchronously) — not just Filament's resource
                // queries.
                app(CompanyContext::class)->set($event->getTenant());
            },
        );
    }
}
