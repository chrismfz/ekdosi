<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Delete tenant roles whose company no longer exists — orphans left by a
 * deleted company (`roles.company_id` has no FK cascade to `companies`). They
 * block a later company that reuses the freed auto-increment id with a
 * "Duplicate entry '<id>-super_admin-web'" on provisioning.
 *
 * One-off cleanup for boxes that deleted a tenant before the CompanyObserver
 * delete-cleanup landed. Dry-run by default; pass --execute to delete.
 *
 *   php artisan ekdosi:prune-orphan-roles
 *   php artisan ekdosi:prune-orphan-roles --execute
 */
class PruneOrphanRoles extends Command
{
    protected $signature = 'ekdosi:prune-orphan-roles {--execute : Actually delete (default: dry-run preview)}';

    protected $description = 'Delete tenant roles whose company no longer exists (orphans that block a reused company id).';

    public function handle(): int
    {
        $orphans = DB::table('roles')
            ->whereNotNull('company_id')
            ->whereNotIn('company_id', fn ($q) => $q->select('id')->from('companies'))
            ->get(['id', 'name', 'company_id']);

        if ($orphans->isEmpty()) {
            $this->info('Καμία orphan role — τίποτα προς καθαρισμό.');

            return self::SUCCESS;
        }

        $this->warn($orphans->count().' orphan role(s) (company δεν υπάρχει πια):');
        foreach ($orphans->groupBy('company_id') as $companyId => $rows) {
            $this->line('  company_id '.$companyId.': '.$rows->pluck('name')->implode(', '));
        }

        if (! $this->option('execute')) {
            $this->info('Dry-run — τίποτα δεν διαγράφηκε. Τρέξε ξανά με --execute.');

            return self::SUCCESS;
        }

        // Pivots (model_has_roles / role_has_permissions) cascade from roles.
        $deleted = DB::table('roles')->whereIn('id', $orphans->pluck('id'))->delete();
        $this->info("Διαγράφηκαν {$deleted} orphan role(s).");

        return self::SUCCESS;
    }
}
