<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\MyData\MyDataLookupSeeder;
use App\Services\TenantRoleProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
 *   php artisan ekdosi:install
 *   php artisan ekdosi:install --email=me@co.gr --password=… --company="ACME ΑΕ" --afm=… --no-interaction
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

    private function seedLookups(Company $company): void
    {
        $lookups = app(MyDataLookupSeeder::class);
        $lookups->seedVatCategories($company);
        $lookups->seedInvoiceTypes($company);
        $lookups->seedPaymentMethods($company);
        $lookups->seedDistributionAims($company);
        $lookups->seedMetricUnits($company);
        $lookups->seedDeliveryMethods($company);
        $lookups->seedProductCategories($company);
    }

    private function askIfInteractive(string $question, string $default): string
    {
        if ($this->option('no-interaction')) {
            return $default;
        }

        return (string) $this->ask($question, $default);
    }
}
