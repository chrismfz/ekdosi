<?php

namespace App\Http\Controllers\Install;

use App\Services\Install\MariaDbConnectionTester;
use App\Support\Install\EnvWriter;
use App\Support\Install\InstallState;
use App\Support\Install\InstallTokenManager;
use App\Support\Install\RequirementsChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The web first-run installer (see {@see InstallState} for the fail-closed
 * gate). Deliberately a plain controller + self-contained Blade — it runs
 * BEFORE `.env`/APP_KEY/DB exist, so it leans on NO session, NO CSRF token and
 * NO Filament assets. Security is the filesystem token (re-checked on every
 * POST) plus the {@see InstallState} gate; wizard state is carried in the form
 * itself (one page, one submit), so nothing is persisted until the final,
 * atomic `.env` write.
 */
class InstallController
{
    public function __construct(
        private readonly InstallState $state,
        private readonly InstallTokenManager $tokens,
        private readonly MariaDbConnectionTester $dbTester,
        private readonly EnvWriter $env,
        private readonly RequirementsChecker $requirements,
    ) {}

    /** GET /install — render the wizard (issuing the verification token on first hit). */
    public function show(): Response
    {
        $this->guardPristine();

        $requirements = $this->requirements->check();

        return response()->view('install.wizard', [
            'tokenIssued' => $this->tokens->issue() !== null,
            'tokenPath' => $this->relativeTokenPath(),
            'errors' => [],
            'old' => [],
            'defaults' => $this->defaults(),
            'requirements' => $requirements,
            'hasBlockers' => $this->requirements->hasBlockers($requirements),
        ]);
    }

    /** POST /install/test-db — AJAX connectivity probe. Token-gated, returns JSON. */
    public function testDb(Request $request): JsonResponse
    {
        $this->guardPristine();

        if (! $this->tokens->verify($request->input('verify_token'))) {
            return response()->json([
                'ok' => false,
                'reason' => 'token',
                'message' => 'Λάθος κωδικός επιβεβαίωσης — δες το αρχείο στον διακομιστή.',
            ], 403);
        }

        $result = $this->dbTester->test(
            host: (string) $request->input('db_host', '127.0.0.1'),
            port: (int) $request->input('db_port', 3306),
            database: (string) $request->input('db_database', ''),
            user: (string) $request->input('db_username', ''),
            password: (string) $request->input('db_password', ''),
        );

        return response()->json([
            'ok' => $result->ok,
            'reason' => $result->reason,
            'message' => $result->message,
            'needsOverride' => $result->needsOverride,
        ]);
    }

    /** POST /install — the whole install, in order, atomic `.env` write LAST. */
    public function run(Request $request): Response
    {
        $this->guardPristine();

        // (1) Token gate — proof of server access, re-checked here.
        if (! $this->tokens->verify($request->input('verify_token'))) {
            return $this->redisplay($request, ['Λάθος κωδικός επιβεβαίωσης. Άνοιξε το αρχείο '.$this->relativeTokenPath().' στον διακομιστή και επικόλλησε τον κωδικό.']);
        }

        // (1b) Requirements gate — re-checked server-side (defence-in-depth: a
        //      scripted POST can't skip the preflight the wizard shows). Hard
        //      failures stop here, before we touch the DB or write anything.
        if ($this->requirements->hasBlockers($this->requirements->check())) {
            return $this->redisplay($request, ['Το περιβάλλον δεν πληροί τις ελάχιστες απαιτήσεις. Διόρθωσε τα κρίσιμα (κόκκινα) σημεία στην ενότητα «Έλεγχος συστήματος» και ξαναπροσπάθησε.']);
        }

        // (2) Validate.
        $validator = Validator::make($request->all(), $this->rules(), $this->messages());

        if ($validator->fails()) {
            return $this->redisplay($request, array_values($validator->errors()->all()));
        }

        $data = $validator->validated();

        // (3) Probe the DB and REFUSE a database that already holds a finished
        //     install (the «αν υπάρχει βάση, κάντο άχρηστο» rule, with creds).
        $probe = $this->dbTester->test(
            host: $data['db_host'],
            port: (int) $data['db_port'],
            database: $data['db_database'],
            user: $data['db_username'],
            password: (string) ($data['db_password'] ?? ''),
        );

        // A real connection failure (auth / unreachable / unknown DB) always stops.
        if (! $probe->ok && ! $probe->needsOverride) {
            return $this->redisplay($request, ['Η σύνδεση στη βάση απέτυχε: '.$probe->message]);
        }

        // ANY non-empty target DB is REFUSED by default — a foreign DB (WHMCS,
        // another app) or a partial prior attempt. This guards against
        // fat-fingering a populated/wrong database (the money-nervous ask). The
        // operator confirms an intended non-empty DB with an explicit checkbox,
        // which then completes a half-built tenant idempotently (--force below).
        $allowExisting = $request->boolean('allow_existing_db');

        if ($probe->needsOverride && ! $allowExisting) {
            return $this->redisplay($request, [$probe->message]);
        }

        // (4) Point the framework at the target DB for the rest of this request.
        $appKey = $this->env->generateAppKey();
        $this->applyRuntimeConfig($data, $appKey);

        // (5) Build the schema, then the first admin + company. Both idempotent
        //     (migrate is additive, ekdosi:install is firstOrCreate + --force), so
        //     a retry after a mid-way failure is safe. `.env` is NOT written yet —
        //     a failure here leaves the wizard available for that retry.
        try {
            Artisan::call('migrate', ['--force' => true]);

            $exit = Artisan::call('ekdosi:install', [
                '--name' => $data['admin_name'],
                '--email' => $data['admin_email'],
                '--password' => $data['admin_password'],
                '--company' => $data['company_name'],
                '--slug' => $this->slug($data),
                '--country' => $data['company_country'],
                '--provider' => $data['company_country'] === 'EE' ? 'ee-peppol' : 'gr-mydata',
                '--afm' => $data['company_afm'] ?? '',
                // Complete a half-built tenant from a prior attempt without
                // tripping the «users already exist» safeguard (all steps idempotent).
                '--force' => true,
                '--no-interaction' => true,
            ]);

            if ($exit !== 0) {
                return $this->redisplay($request, [
                    'Η δημιουργία διαχειριστή/εταιρίας απέτυχε. Λεπτομέρειες: '.trim(Artisan::output()),
                ]);
            }
        } catch (\Throwable $e) {
            return $this->redisplay($request, ['Η εγκατάσταση απέτυχε: '.$e->getMessage()]);
        }

        // (6) Commit: write `.env` atomically, drop the marker, remove the
        //     token, clear any cached config so the next request boots fresh.
        try {
            $this->env->write($this->env->render($this->envValues($data, $appKey)));
        } catch (\Throwable $e) {
            return $this->redisplay($request, [
                'Τα δεδομένα εγκαταστάθηκαν, αλλά το .env ΔΕΝ γράφτηκε ('.$e->getMessage().'). '
                .'Πρόσθεσε τα δικαιώματα εγγραφής στον ριζικό φάκελο και ξαναπροσπάθησε.',
            ]);
        }

        $this->state->markInstalled([
            'installed_at' => now()->toIso8601String(),
            'version' => (string) config('app.version'),
            'admin_email' => $data['admin_email'],
            'company' => $data['company_name'],
        ]);
        $this->tokens->clear();
        $this->clearConfigCache();

        return response()->view('install.done', [
            'appUrl' => rtrim($data['app_url'], '/'),
            'adminEmail' => $data['admin_email'],
            'company' => $data['company_name'],
            'checklist' => $this->postInstallChecklist(),
        ]);
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function guardPristine(): void
    {
        abort_unless($this->state->canInstall(), 410, 'Η εφαρμογή είναι ήδη εγκατεστημένη.');
    }

    /** Re-render the wizard with errors + old input (no session → view data). */
    private function redisplay(Request $request, array $errors): Response
    {
        $requirements = $this->requirements->check();

        return response()->view('install.wizard', [
            'tokenIssued' => $this->tokens->token() !== null,
            'tokenPath' => $this->relativeTokenPath(),
            'errors' => $errors,
            'old' => $request->except(['admin_password', 'admin_password_confirmation', 'db_password', 'mail_password', 'verify_token']),
            'defaults' => $this->defaults(),
            'requirements' => $requirements,
            'hasBlockers' => $this->requirements->hasBlockers($requirements),
        ], 422);
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'app_name' => ['required', 'string', 'max:255'],
            // Restrict to http/https — the bare `url` rule accepts javascript:/data:
            // schemes, and this value becomes APP_URL (PDF/email/QR/webhook links)
            // and a clickable href on the done page.
            'app_url' => ['required', 'url:http,https'],
            'app_env' => ['required', 'in:production,local'],
            'app_locale' => ['required', 'in:el,en'],
            'app_timezone' => ['required', 'timezone'],

            'db_host' => ['required', 'string', 'max:255'],
            'db_port' => ['required', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:64'],
            'db_username' => ['required', 'string', 'max:255'],
            'db_password' => ['nullable', 'string'],

            'mail_mailer' => ['required', 'in:log,smtp,sendmail'],
            'mail_host' => ['nullable', 'required_if:mail_mailer,smtp', 'string', 'max:255'],
            'mail_port' => ['nullable', 'required_if:mail_mailer,smtp', 'integer', 'between:1,65535'],
            'mail_username' => ['nullable', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string'],
            'mail_encryption' => ['required', 'in:null,tls,ssl'],
            'mail_from_address' => ['required', 'email'],
            'mail_from_name' => ['required', 'string', 'max:255'],

            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'company_name' => ['required', 'string', 'max:255'],
            'company_slug' => ['nullable', 'string', 'max:255'],
            'company_country' => ['required', 'in:GR,EE'],
            'company_afm' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'required' => 'Το πεδίο «:attribute» είναι υποχρεωτικό.',
            'admin_password.confirmed' => 'Οι κωδικοί διαχειριστή δεν ταιριάζουν.',
            'admin_password.min' => 'Ο κωδικός διαχειριστή πρέπει να έχει τουλάχιστον 8 χαρακτήρες.',
            'app_url.url' => 'Το APP_URL πρέπει να είναι έγκυρο URL (π.χ. https://ekdosi.myip.gr).',
        ];
    }

    private function applyRuntimeConfig(array $data, string $appKey): void
    {
        config([
            'app.key' => $appKey,
            'app.name' => $data['app_name'],
            'app.url' => $data['app_url'],
            'app.locale' => $data['app_locale'],
            'app.timezone' => $data['app_timezone'],
            'database.default' => 'mariadb',
            'database.connections.mariadb.host' => $data['db_host'],
            'database.connections.mariadb.port' => (int) $data['db_port'],
            'database.connections.mariadb.database' => $data['db_database'],
            'database.connections.mariadb.username' => $data['db_username'],
            'database.connections.mariadb.password' => (string) ($data['db_password'] ?? ''),
        ]);

        DB::purge('mariadb');
        DB::reconnect('mariadb');
    }

    /** @return array<string, string|bool> */
    private function envValues(array $data, string $appKey): array
    {
        return [
            'app_name' => $data['app_name'],
            'app_env' => $data['app_env'],
            'app_key' => $appKey,
            'app_url' => $data['app_url'],
            'app_locale' => $data['app_locale'],
            'app_timezone' => $data['app_timezone'],
            'db_host' => $data['db_host'],
            'db_port' => (string) $data['db_port'],
            'db_database' => $data['db_database'],
            'db_username' => $data['db_username'],
            'db_password' => (string) ($data['db_password'] ?? ''),
            'mail_mailer' => $data['mail_mailer'],
            'mail_host' => (string) ($data['mail_host'] ?? '127.0.0.1'),
            'mail_port' => (string) ($data['mail_port'] ?? '2525'),
            'mail_username' => (string) ($data['mail_username'] ?? ''),
            'mail_password' => (string) ($data['mail_password'] ?? ''),
            'mail_encryption' => $data['mail_encryption'],
            'mail_from_address' => $data['mail_from_address'],
            'mail_from_name' => $data['mail_from_name'],
        ];
    }

    private function slug(array $data): string
    {
        return Str::slug(($data['company_slug'] ?? '') ?: $data['company_name']) ?: 'company';
    }

    private function clearConfigCache(): void
    {
        foreach (['config.php', 'routes-v7.php', 'events.php'] as $file) {
            $cached = base_path('bootstrap/cache/'.$file);
            if (is_file($cached)) {
                @unlink($cached);
            }
        }
    }

    /** Path shown to the operator — relative, so we don't leak the absolute server path. */
    private function relativeTokenPath(): string
    {
        return 'storage/app/install/verify-token.txt';
    }

    /** @return array<string, string> */
    private function defaults(): array
    {
        return [
            'app_name' => 'ekdosi',
            'app_env' => 'production',
            'app_url' => (string) request()->getSchemeAndHttpHost(),
            'app_locale' => 'el',
            'app_timezone' => 'Europe/Athens',
            'db_host' => '127.0.0.1',
            'db_port' => '3306',
            'db_database' => 'ekdosi',
            'db_username' => 'ekdosi',
            'mail_mailer' => 'log',
            'mail_encryption' => 'null',
            'mail_from_address' => 'no-reply@example.gr',
            'company_country' => 'GR',
        ];
    }

    /** @return array<int, array{title: string, body: string}> */
    private function postInstallChecklist(): array
    {
        return [
            [
                'title' => 'Cron (χρονοπρογραμματιστής)',
                'body' => 'Πρόσθεσε στο crontab: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            ],
            [
                'title' => 'Queue worker (εργάτης ουράς)',
                'body' => 'Κράτα ζωντανό έναν worker για email/imports: php artisan queue:work (μέσω systemd ή supervisord — δες INSTALL.md).',
            ],
            [
                'title' => 'Επεκτάσεις PHP',
                'body' => 'Για εισαγωγή από Firebird χρειάζεται pdo_firebird· για GSIS lookup το ext-soap (μόνο στον host που τρέχει το ETL/artisan).',
            ],
            [
                'title' => 'Δικαιώματα & HTTPS',
                'body' => 'Βεβαιώσου ότι το storage/ και bootstrap/cache/ είναι εγγράψιμα, και ότι το site σερβίρεται μέσω HTTPS σε production.',
            ],
            [
                'title' => 'Έλεγχος υγείας',
                'body' => 'Τρέξε php artisan ops:health για μια πλήρη εικόνα (queue/scheduler/backup/mail/myDATA/δίσκος).',
            ],
        ];
    }
}
