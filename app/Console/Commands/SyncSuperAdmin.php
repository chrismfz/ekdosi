<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Console\Command;

/**
 * Backfill / repair the per-tenant `super_admin` role under Shield teams mode.
 *
 * Why: a company created outside the seeder (e.g. from the Companies UI) never
 * got a `super_admin` role for its team, so an admin switching into it lost the
 * super-admin bypass and saw a stripped menu. The CompanyObserver now creates
 * the role for new companies; this command fixes companies created BEFORE that
 * (and is a safety net you can re-run anytime — it's idempotent).
 *
 * Default (no --user): ensure every company HAS a super_admin role, then assign
 * it within each company to every user who is already super_admin in at least
 * one of their teams (so an existing admin regains all their tenants without
 * granting super_admin to per-tenant operators).
 *
 * With --user=email: force-assign super_admin to that user in every company
 * they're attached to (the "make me admin everywhere" escape hatch).
 *
 * Usage:
 *   php artisan shield:sync-super-admin
 *   php artisan shield:sync-super-admin --user=admin@ekdosi.local
 *   php artisan shield:sync-super-admin --company=nexon
 */
class SyncSuperAdmin extends Command
{
    protected $signature = 'shield:sync-super-admin
        {--user= : Force-assign super_admin to this user (email) in all their companies}
        {--company= : Limit role creation to one company (slug or id)}';

    protected $description = 'Ensure the per-tenant super_admin role exists and is assigned (Shield teams mode).';

    public function handle(TenantRoleProvisioner $provisioner): int
    {
        $companies = $this->option('company')
            ? collect([Company::findBySlugOrId($this->option('company'))])->filter()
            : Company::all();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        // 1) Ensure the roles exist for each target company: super_admin AND
        //    the standard non-super roles (company_admin, operator) with their
        //    permission sets. Re-running re-syncs the permission maps, so this
        //    is also how you refresh roles after shield:generate adds new
        //    resource permissions. (Assigning company_admin/operator to a
        //    specific user is done in the per-tenant role picker UI.)
        foreach ($companies as $company) {
            $provisioner->ensureSuperAdminRole($company);
            $provisioner->ensureStandardRoles($company);
            $this->line("✓ roles ensured (super_admin, company_admin, operator) for: {$company->slug}");
        }

        // 2) Assignment.
        if ($email = $this->option('user')) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                $this->error("User '{$email}' not found.");

                return self::FAILURE;
            }
            foreach ($user->companies as $company) {
                $provisioner->assignSuperAdmin($user, $company);
                $this->line("→ assigned super_admin to {$email} in {$company->slug}");
            }

            $this->info('Done.');

            return self::SUCCESS;
        }

        // No --user: re-assign to anyone already super_admin somewhere, so an
        // existing admin regains tenants they lost (without touching operators).
        $assigned = 0;
        foreach (User::with('companies')->get() as $user) {
            if (! $provisioner->isSuperAdminAnywhere($user)) {
                continue;
            }
            foreach ($user->companies as $company) {
                $provisioner->assignSuperAdmin($user, $company);
                $assigned++;
            }
            $this->line("→ re-synced super_admin for {$user->email} across their tenants");
        }

        $this->info("Done. {$assigned} (user × tenant) assignments ensured.");

        return self::SUCCESS;
    }
}
