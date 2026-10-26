<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Mycs\MycsReversalWatcher;
use Illuminate\Console\Command;

/**
 * K2 of the MyCS Bridge: tell staff when a sale ekdosi issued a document for was refunded, cancelled or unpaid again in
 * MyCS (MycsReversalWatcher). Read only; a few rows a run, in turn.
 */
class MycsWatchReversals extends Command
{
    protected $signature = 'mycs:watch-reversals {--tenant= : One company slug (default: every company on MyCS Bridge).} {--limit=50 : Rows checked per company per run.}';

    protected $description = 'MyCS Bridge: bell the staff when an issued invoice was refunded, cancelled or unpaid again in MyCS (a credit note may be needed). Read only.';

    public function handle(MycsReversalWatcher $watcher): int
    {
        $slug = (string) ($this->option('tenant') ?? '');
        $tenants = Company::query()->where('mycs_enabled', true)
            ->when($slug !== '', fn ($q) => $q->where('slug', $slug))
            ->get()->filter(fn (Company $c) => $c->usesMycs());
        foreach ($tenants as $tenant) {
            $result = $watcher->run($tenant, max(1, (int) $this->option('limit')));
            $this->line("{$tenant->slug}: checked {$result['checked']}, flagged {$result['flagged']}");
        }

        return self::SUCCESS;
    }
}
