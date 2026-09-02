<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Ξανα-συγχρονίζει τους διαχειριζόμενους ρόλους (`company_admin` / `operator`)
 * κάθε tenant με τον κανονικό χάρτη δικαιωμάτων.
 *
 *   php artisan roles:reprovision [--tenant=SLUG] [--dry-run] [--prune] [--force]
 *
 * ΓΙΑΤΙ: το `shield:generate` φτιάχνει τα permission ROWS ενός νέου resource,
 * και το `shield:sync-super-admin` τα δίνει στον super_admin — αλλά ο
 * `operator` / `company_admin` ενός tenant τα έπαιρνε μόνο όταν κάποιος του
 * ξανα-ανέθετε τον ρόλο από τον picker. Δηλαδή ΚΑΘΕ νέο resource έμενε
 * αόρατο για τους χειριστές μέχρι να το θυμηθεί κάποιος (τα Leads ήταν
 * ακριβώς αυτή η περίπτωση). Αυτή η εντολή είναι το βήμα που έλειπε — τρέχει
 * και από το `deploy/update.sh` μετά το `shield:generate`.
 *
 * ΠΡΟΣΘΕΤΙΚΗ από προεπιλογή: δίνει ό,τι λείπει, ΔΕΝ αφαιρεί ποτέ — ώστε μια
 * χειροκίνητη προσαρμογή ρόλου σε έναν tenant να μη χάνεται σε ένα deploy.
 * Με `--prune` ευθυγραμμίζει πλήρως (αφαιρεί και τα επιπλέον), αφού δείξει τι
 * θα αφαιρεθεί και ρωτήσει.
 */
class RolesReprovision extends Command
{
    protected $signature = 'roles:reprovision
        {--tenant= : Μία εταιρεία (slug) — προεπιλογή: όλες}
        {--dry-run : Δείξε μόνο τι θα άλλαζε}
        {--prune : Αφαίρεσε και τα δικαιώματα εκτός του κανονικού χάρτη}
        {--force : Χωρίς ερώτηση επιβεβαίωσης (για το deploy)}';

    protected $description = 'Συγχρονίζει τους ρόλους company_admin/operator κάθε tenant με τον κανονικό χάρτη δικαιωμάτων.';

    public function handle(TenantRoleProvisioner $provisioner): int
    {
        $companies = Company::query()
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->orderBy('name')
            ->get();

        if ($companies->isEmpty()) {
            if ($this->option('tenant')) {
                $this->error('Δεν βρέθηκε εταιρεία: '.$this->option('tenant'));

                return self::INVALID;
            }
            $this->info('Καμία εταιρεία — τίποτα να συγχρονιστεί.');

            return self::SUCCESS;
        }

        $prune = (bool) $this->option('prune');
        $dryRun = (bool) $this->option('dry-run');

        // The managed roles that actually hold permissions (super_admin holds
        // none — the global Gate::before bypass covers it).
        $roles = [TenantRoleProvisioner::ROLE_COMPANY_ADMIN, TenantRoleProvisioner::ROLE_OPERATOR];

        /** @var list<array{company: Company, role: string, missing: list<string>, extra: list<string>}> $plan */
        $plan = [];
        foreach ($companies as $company) {
            foreach ($roles as $roleName) {
                // Snapshot BEFORE ensureManagedRolesExist(): that call backfills
                // a role holding NO permissions, and diffing after it would make
                // the report claim «already in sync» for work it just did.
                $role = $provisioner->findManagedRole($roleName, $company);
                $held = $role?->permissions()->pluck('name') ?? collect();

                $wanted = $provisioner->defaultPermissionsFor($roleName)->pluck('name');
                $missing = $wanted->diff($held)->values()->all();
                $extra = $held->diff($wanted)->values()->all();

                if ($missing !== [] || ($prune && $extra !== [])) {
                    $plan[] = ['company' => $company, 'role' => $roleName, 'missing' => $missing, 'extra' => $extra];
                }
            }
        }

        if ($plan === []) {
            $this->info('Όλοι οι ρόλοι είναι ήδη συγχρονισμένοι.');

            return self::SUCCESS;
        }

        $this->report($plan, $prune);

        if ($dryRun) {
            $this->comment('Dry-run — τίποτα δεν άλλαξε.');

            return self::SUCCESS;
        }

        if ($prune && ! $this->option('force') && ! $this->confirm(
            'Το --prune ΑΦΑΙΡΕΙ τα δικαιώματα εκτός του κανονικού χάρτη (χάνονται τυχόν χειροκίνητες προσαρμογές). Συνέχεια;',
            false,
        )) {
            $this->comment('Ακυρώθηκε.');

            return self::SUCCESS;
        }

        $granted = 0;
        $revoked = 0;
        foreach ($plan as $row) {
            // Create any missing role ROW (a company restored from a bundle, or
            // one whose picker never ran) — idempotent, and only now that we
            // have already measured what was missing.
            $provisioner->ensureManagedRolesExist($row['company']);

            $role = $provisioner->findManagedRole($row['role'], $row['company']);
            if ($role === null) {
                continue;
            }

            // Teams mode: permission writes go through the role row we already
            // resolved with an explicit company_id, so no ambient team leaks in.
            DB::transaction(function () use ($role, $row, $prune, &$granted, &$revoked): void {
                if ($row['missing'] !== []) {
                    // Idempotent: harmless if the row-creation backfill above
                    // already granted some of them.
                    $role->givePermissionTo($row['missing']);
                    $granted += count($row['missing']);
                }
                if ($prune && $row['extra'] !== []) {
                    foreach ($row['extra'] as $name) {
                        $role->revokePermissionTo($name);
                    }
                    $revoked += count($row['extra']);
                }
            });
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("✓ Δόθηκαν {$granted} δικαιώματα".($prune ? ", αφαιρέθηκαν {$revoked}" : '').'.');
        $this->line('  Οι χειριστές βλέπουν τα νέα resources μετά από νέο login (ή refresh).');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{company: Company, role: string, missing: list<string>, extra: list<string>}>  $plan
     */
    private function report(array $plan, bool $prune): void
    {
        $rows = [];
        foreach ($plan as $row) {
            $rows[] = [
                $row['company']->name,
                $row['role'],
                $row['missing'] === [] ? '—' : count($row['missing']).': '.$this->preview($row['missing']),
                $prune
                    ? ($row['extra'] === [] ? '—' : count($row['extra']).': '.$this->preview($row['extra']))
                    : ($row['extra'] === [] ? '—' : count($row['extra']).' (μένουν)'),
            ];
        }

        $this->table(['Εταιρεία', 'Ρόλος', 'Λείπουν → δίνονται', $prune ? 'Επιπλέον → αφαιρούνται' : 'Επιπλέον'], $rows);
    }

    /** @param  list<string>  $names */
    private function preview(array $names): string
    {
        $shown = array_slice($names, 0, 4);
        $more = count($names) - count($shown);

        return implode(', ', $shown).($more > 0 ? " +{$more}…" : '');
    }
}
