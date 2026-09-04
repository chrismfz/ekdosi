<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\MyData\MyDataLookupSeeder;
use App\Services\Portability\BundleArchive;
use App\Services\Portability\CompanyImporter;
use App\Services\Portability\SecretsCodec;
use App\Services\TenantRoleProvisioner;
use BezhanSalleh\FilamentShield\Support\Utils as ShieldUtils;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turnkey from-zero install: create the FIRST super_admin user + the first
 * company, wire Shield (permissions + the per-tenant super_admin/standard roles),
 * and seed the standard Greek AADE lookups (VAT categories + classified invoice
 * types + payment methods + units) so the brand-new tenant can issue a ΤΠΥ with
 * zero manual Setup.
 *
 * Safeguard: refuses to run if ANY user already exists (a populated install) —
 * pass --force to add another company/admin anyway. Idempotent on the company
 * slug + the admin email (re-running won't duplicate).
 *
 * With --bundle, the first company is RESTORED from a `company:export` .zip
 * (identity, settings, sealed secrets, setup tables, assigned operators) instead
 * of a blank one, then the install admin is created + made super_admin.
 *
 *   php artisan ekdosi:install
 *   php artisan ekdosi:install --email=me@co.gr --password=… --company="ACME ΑΕ" --afm=… --no-interaction
 *   php artisan ekdosi:install --bundle=myip.zip --bundle-passphrase=… --email=me@co.gr --password=… --no-interaction
 */
class Install extends Command
{
    protected $signature = 'ekdosi:install
        {--name= : Admin full name}
        {--email= : Admin email (login)}
        {--password= : Admin password (else prompted, hidden)}
        {--company= : Company name}
        {--slug= : Company slug (default: slug of the name)}
        {--country=GR : Country code (GR/EE/…)}
        {--afm= : Company VAT/ΑΦΜ}
        {--provider=gr-mydata : e-invoice provider (gr-mydata/ee-peppol/none)}
        {--no-lookups : Skip seeding the standard Greek AADE lookups}
        {--bundle= : Provision the first company from a company-export .zip (company:export) instead of a blank one}
        {--bundle-passphrase= : Passphrase to open the bundle secrets (else prompted; ignored for a raw bundle)}
        {--force : Proceed even if users already exist}';

    protected $description = 'First-run install: create the first super_admin + company, wire Shield, seed lookups.';

    public function handle(): int
    {
        // (0) Safeguard — a populated install needs an explicit --force.
        if (User::query()->exists() && ! $this->option('force')) {
            $this->error('Υπάρχει ήδη χρήστης — η εγκατάσταση φαίνεται ολοκληρωμένη.');
            $this->line('Αν θες να προσθέσεις άλλη εταιρία/διαχειριστή, ξανατρέξε με --force.');

            return self::FAILURE;
        }

        // (1) Gather admin + company details.
        $name = (string) ($this->option('name') ?: $this->askIfInteractive('Όνομα διαχειριστή', 'Admin'));
        $email = (string) ($this->option('email') ?: $this->askIfInteractive('Email διαχειριστή (login)', 'admin@ekdosi.local'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Μη έγκυρο email: «{$email}».");

            return self::FAILURE;
        }

        $password = (string) $this->option('password');
        if ($password === '') {
            if ($this->option('no-interaction')) {
                $this->error('Δώσε --password σε non-interactive mode.');

                return self::FAILURE;
            }
            $password = (string) $this->secret('Κωδικός διαχειριστή');
            if ($password === '' || $password !== (string) $this->secret('Επιβεβαίωση κωδικού')) {
                $this->error('Οι κωδικοί δεν ταιριάζουν (ή είναι κενοί).');

                return self::FAILURE;
            }
        }

        // SEC-2: enforce the same 8-char minimum as the panel's user form, so a
        // --password flag (or a short prompt) can't seed a weak super_admin.
        if (mb_strlen($password) < 8) {
            $this->error('Ο κωδικός πρέπει να έχει τουλάχιστον 8 χαρακτήρες.');

            return self::FAILURE;
        }

        // From-bundle path: the first company (identity + settings + secrets +
        // operators) comes from a company:export .zip, not the flags below.
        if (((string) $this->option('bundle')) !== '') {
            return $this->installFromBundle($name, $email, $password);
        }

        $companyName = (string) ($this->option('company') ?: $this->askIfInteractive('Επωνυμία εταιρίας', 'Η Εταιρία μου ΑΕ'));
        $slug = Str::slug((string) ($this->option('slug') ?: $companyName)) ?: 'company';
        $country = strtoupper((string) $this->option('country')) ?: 'GR';
        $provider = (string) $this->option('provider') ?: 'gr-mydata';
        $afm = (string) ($this->option('afm') ?: $this->askIfInteractive('ΑΦΜ εταιρίας (προαιρετικό)', ''));

        $this->info("Εγκατάσταση: εταιρία «{$companyName}» ({$slug}, {$country}), διαχειριστής {$email}.");

        // (2) Everything in one transaction so a mid-way failure leaves no half-built tenant.
        $company = DB::transaction(function () use ($companyName, $slug, $country, $provider, $afm, $name, $email, $password): Company {
            $company = Company::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $companyName,
                    'country_code' => $country,
                    'einvoice_provider' => $provider,
                    'afm' => $afm ?: null,
                    'mydata_mode' => 'off',   // safe default — opt into AADE later from Setup
                ],
            );

            $admin = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => now()],
            );
            $admin->companies()->syncWithoutDetaching([$company->id]);

            return $company->setRelation('admin', $admin);
        });

        $admin = $company->getRelation('admin');

        // firstOrCreate only sets attributes on creation: a pre-existing admin
        // keeps its old password (the supplied --password is ignored). Warn so a
        // --force run isn't mistaken for a password reset.
        if (! $admin->wasRecentlyCreated) {
            $this->warn("Ο διαχειριστής {$admin->email} υπήρχε ήδη — ο κωδικός ΔΕΝ άλλαξε.");
        }

        // (3)+(4) Post-transaction wiring. The company+admin rows are already
        //     committed, so if anything here throws (e.g. shield:generate), the
        //     tenant is half-built — but every step below is idempotent, so the
        //     fix is simply to re-run with --force (the safeguard now sees the
        //     user and would otherwise refuse). We surface that explicitly.
        try {
            // shield:generate needs the company to exist first (tenant_model=Company
            // → it creates a super_admin role per tenant). NB: the CompanyObserver
            // already ran ensureStandardRoles when the row was created — but BEFORE
            // permissions existed, so those role maps are empty; the explicit
            // ensureStandardRoles below re-syncs them now that the perms exist (do
            // NOT remove it thinking the observer covered it).
            Artisan::call('shield:generate', [
                '--all' => true,
                '--panel' => 'admin',
                '--ignore-existing-policies' => true,
                '--no-interaction' => true,
            ]);

            $provisioner = app(TenantRoleProvisioner::class);
            $provisioner->ensureStandardRoles($company);
            $provisioner->assignSuperAdmin($admin, $company);

            // Standard Greek AADE lookups so the tenant can issue immediately.
            if (! $this->option('no-lookups') && $country === 'GR') {
                $this->seedLookups($company);
                $this->line('  ✓ Standard lookups (ΦΠΑ / τύποι παραστατικών / τρόποι πληρωμής / μονάδες) seeded.');
            }
        } catch (\Throwable $e) {
            $this->error('Η εγκατάσταση απέτυχε ΜΕΤΑ τη δημιουργία της εταιρίας/διαχειριστή: '.$e->getMessage());
            $this->warn('Η εταιρία/διαχειριστής δημιουργήθηκαν. Ξανατρέξε την ίδια εντολή με --force για να ολοκληρωθεί (όλα τα βήματα είναι idempotent).');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✓ Η εγκατάσταση ολοκληρώθηκε.');
        $this->line("  Εταιρία:      {$company->name}  (slug: {$company->slug})");
        $this->line("  Διαχειριστής: {$admin->email}  (super_admin)");
        $this->line('  Σύνδεση:      '.rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }

    /**
     * Provision the first company from a `company:export` bundle: the whole
     * tenant (identity, settings, sealed secrets, setup tables, assigned
     * operators) is restored, then the install admin is created + made
     * super_admin. Standard-lookup seeding is skipped — the bundle already
     * carries invoice types / VAT / payment methods.
     */
    private function installFromBundle(string $name, string $email, string $password): int
    {
        $file = (string) $this->option('bundle');
        if (! is_file($file)) {
            $this->error("Δεν βρέθηκε το αρχείο bundle: «{$file}».");

            return self::FAILURE;
        }

        try {
            $bundle = app(BundleArchive::class)->read($file);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $passphrase = $this->resolveBundlePassphrase((string) ($bundle['secrets']['mode'] ?? 'passphrase'));
        if ($passphrase === false) {
            return self::FAILURE; // message already emitted
        }

        // Verify the passphrase NOW (before creating anything). The importer opens
        // the secrets itself, but a typo would only surface there — after the admin
        // is created — stranding an orphan admin that forces --force on the retry.
        try {
            app(SecretsCodec::class)->open($bundle['secrets'], $passphrase !== '' ? $passphrase : null);
        } catch (\Throwable $e) {
            $this->error('Δεν αποκρυπτογραφούνται τα secrets του bundle (λάθος συνθηματικό ή κατεστραμμένο αρχείο): '.$e->getMessage());

            return self::FAILURE;
        }

        $slug = (string) ($bundle['manifest']['company']['slug'] ?? '');
        $companyName = (string) ($bundle['manifest']['company']['name'] ?? $slug);
        $this->info("Εγκατάσταση από bundle: εταιρία «{$companyName}» ({$slug}), διαχειριστής {$email}.");

        // Create the admin FIRST, with the specified password. The importer may
        // create bundle operators — if the admin's email is among them, doing it
        // first means the importer finds an EXISTING user and never mints the
        // admin with a random password (importUsers sets a password only on create).
        $admin = User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => now()],
        );
        if (! $admin->wasRecentlyCreated) {
            $this->warn("Ο διαχειριστής {$admin->email} υπήρχε ήδη — ο κωδικός ΔΕΝ άλλαξε.");
        }

        try {
            $summary = app(CompanyImporter::class)->run($bundle, [
                'new' => true,
                'execute' => true,
                'passphrase' => $passphrase !== '' ? $passphrase : null,
            ]);
        } catch (\Throwable $e) {
            // \Throwable (not just RuntimeException): a data-level import error
            // surfaces as a QueryException, which must still exit cleanly.
            // Undo the admin we just created (only if WE created it) so the retry
            // isn't blocked by an orphan user tripping the populated-install guard.
            if ($admin->wasRecentlyCreated) {
                $admin->delete();
            }
            $this->error('Αποτυχία εισαγωγής bundle: '.$e->getMessage());

            return self::FAILURE;
        }

        $company = Company::query()->where('slug', $summary['slug'])->first();
        if ($company === null) {
            $this->error('Η εισαγωγή ολοκληρώθηκε αλλά δεν βρέθηκε η εταιρία — ασυνέπεια, έλεγξε χειροκίνητα.');

            return self::FAILURE;
        }

        // Attach the admin + wire Shield/roles. NO lookup seeding (the bundle
        // carries the setup tables; seeding would duplicate/conflict).
        $admin->companies()->syncWithoutDetaching([$company->id]);

        try {
            Artisan::call('shield:generate', [
                '--all' => true,
                '--panel' => 'admin',
                '--ignore-existing-policies' => true,
                '--no-interaction' => true,
            ]);

            $provisioner = app(TenantRoleProvisioner::class);
            // Re-sync the managed-role permission maps now that shield:generate
            // has created the Permission rows (the importer's own provisioning ran
            // before them). Idempotent — fills the maps, clobbers nothing.
            $provisioner->ensureStandardRoles($company);
            // The install admin is super_admin and ONLY that: setRoleInCompany
            // strips any role the importer may have given them when their email is
            // also listed as a bundle operator (so they don't end up operator +
            // super_admin).
            $provisioner->setRoleInCompany($admin, $company, ShieldUtils::getSuperAdminName());
        } catch (\Throwable $e) {
            $this->error('Η εγκατάσταση απέτυχε ΜΕΤΑ την εισαγωγή: '.$e->getMessage());
            $this->warn('Η εταιρία/διαχειριστής δημιουργήθηκαν. Τρέξε «php artisan shield:sync-super-admin» για να ολοκληρωθεί.');

            return self::FAILURE;
        }

        $users = $summary['users'] ?? ['attach' => 0, 'create' => 0];

        $this->newLine();
        $this->info('✓ Η εγκατάσταση από bundle ολοκληρώθηκε.');
        $this->line("  Εταιρία:      {$company->name}  (slug: {$company->slug})");
        $this->line("  Διαχειριστής: {$admin->email}  (super_admin)");
        $this->line(sprintf('  Χειριστές:    +%d νέοι / %d σύνδεση (από το bundle)', $users['create'] ?? 0, $users['attach'] ?? 0));
        $this->line('  Σύνδεση:      '.rtrim((string) config('app.url'), '/').'/admin');

        return self::SUCCESS;
    }

    /**
     * Resolve the passphrase for a bundle: none for a raw bundle (returns ''),
     * else the flag or an interactive prompt. Returns false (and emits an error)
     * when a passphrase is required but unavailable in non-interactive mode.
     */
    private function resolveBundlePassphrase(string $mode): string|false
    {
        if ($mode === 'raw') {
            return '';
        }

        $passphrase = (string) $this->option('bundle-passphrase');
        if ($passphrase !== '') {
            return $passphrase;
        }

        if ($this->option('no-interaction')) {
            $this->error('Δώσε --bundle-passphrase για το κρυπτογραφημένο bundle.');

            return false;
        }

        $passphrase = (string) $this->secret('Συνθηματικό του bundle');
        if ($passphrase === '') {
            $this->error('Το συνθηματικό είναι κενό.');

            return false;
        }

        return $passphrase;
    }

    private function seedLookups(Company $company): void
    {
        // Same aggregate (single ordering) the create + provider-switch paths
        // use. CLI has no ambient tenant → CompanyScope is a no-op and the
        // seeder's explicit company_id is authoritative.
        app(MyDataLookupSeeder::class)->seedStandardLookups($company);
    }

    private function askIfInteractive(string $question, string $default): string
    {
        if ($this->option('no-interaction')) {
            return $default;
        }

        return (string) $this->ask($question, $default);
    }
}
