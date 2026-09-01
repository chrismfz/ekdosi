<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\SalesReconciler;
use App\Support\OperatorHealth\HealthRecorder;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI / cron parity for the live myDATA sales reconciliation that the
 * "Κονσόλα myDATA" Filament page runs interactively. Calls AADE
 * (RequestTransmittedDocs) for a tenant + window and prints the
 * agreement / discrepancy summary.
 *
 * Read-only: it never mutates local invoices or files anything at AADE.
 *
 * Exit codes:
 *   0 = ran, no discrepancies
 *   1 = error (bad tenant, missing creds, AADE unreachable)
 *   2 = ran, discrepancies found (useful for cron alerting)
 *
 * Usage:
 *   php artisan mydata:reconcile-sales --tenant=myip
 *   php artisan mydata:reconcile-sales --tenant=myip --from=2026-01-01 --to=2026-03-31
 */
class MyDataReconcileSales extends Command
{
    protected $signature = 'mydata:reconcile-sales
        {--tenant= : Company slug (or numeric id) to reconcile}
        {--from= : Window start (Y-m-d). Default: one month ago}
        {--to= : Window end (Y-m-d). Default: today}
        {--raw : Print the RAW AADE response XML instead of reconciling (diagnostic, read-only)}';

    protected $description = 'Cross-check locally-filed invoices against what AADE holds (RequestTransmittedDocs).';

    public function handle(): int
    {
        $tenantArg = $this->option('tenant');
        if (! $tenantArg) {
            $this->error('--tenant is required (company slug or id).');

            return self::FAILURE;
        }

        $tenant = Company::query()
            ->where(fn ($q) => $q
                ->where('slug', $tenantArg)
                ->orWhere('id', is_numeric($tenantArg) ? (int) $tenantArg : 0))
            ->first();

        if (! $tenant) {
            $this->error("Tenant '{$tenantArg}' not found.");

            return self::FAILURE;
        }

        try {
            $from = $this->option('from')
                ? Carbon::parse($this->option('from'))->startOfDay()
                : now()->subMonth()->startOfDay();
            $to = $this->option('to')
                ? Carbon::parse($this->option('to'))->endOfDay()
                : now()->endOfDay();
        } catch (Throwable $e) {
            $this->error('Invalid --from/--to date (expected Y-m-d): '.$e->getMessage());

            return $this->recordHealthAndReturn($tenant, self::FAILURE);
        }

        $this->line("Tenant : {$tenant->name} (#{$tenant->id})");
        $this->line("Window : {$from->format('d/m/Y')} – {$to->format('d/m/Y')}");

        if ($this->option('raw')) {
            try {
                $pages = (new SalesReconciler($tenant))->rawTransmittedDocs($from, $to);
            } catch (Throwable $e) {
                $this->error('Raw fetch failed: '.$e->getMessage());

                return $this->recordHealthAndReturn($tenant, self::FAILURE);
            }

            $this->newLine();
            $this->info('Raw RequestTransmittedDocs response ('.count($pages).' page(s)):');
            foreach ($pages as $i => $xml) {
                $this->newLine();
                $this->comment('───── page '.($i + 1).' ─────');
                $this->line($xml);
            }

            return $this->recordHealthAndReturn($tenant, self::SUCCESS);
        }

        try {
            $result = (new SalesReconciler($tenant))->reconcile($from, $to);
        } catch (Throwable $e) {
            $this->error('Reconciliation failed: '.$e->getMessage());

            return $this->recordHealthAndReturn($tenant, self::FAILURE);
        }

        $this->newLine();
        $this->table(
            ['Bucket', 'Count'],
            [
                ['AADE total', $result->aadeTotal],
                ['Local total', $result->localTotal],
                ['Matched', count($result->matched)],
                ['State mismatch', count($result->stateMismatch)],
                ['Content mismatch', count($result->contentMismatch)],
                ['Content incomplete', count($result->contentIncomplete)],
                ['Missing at AADE', count($result->missingAtAade)],
                ['Missing locally', count($result->missingLocally)],
                ['Duplicate local MARK', count($result->duplicateLocal)],
            ],
        );

        foreach ([
            'State mismatch' => $result->stateMismatch,
            'Content mismatch' => $result->contentMismatch,
            'Content incomplete' => $result->contentIncomplete,
            'Missing at AADE' => $result->missingAtAade,
            'Missing locally' => $result->missingLocally,
            'Duplicate local MARK' => $result->duplicateLocal,
        ] as $title => $rows) {
            if (empty($rows)) {
                continue;
            }

            $this->newLine();
            $this->warn($title.':');
            foreach ($rows as $row) {
                $code = $row->invcode ?? '(—)';
                $this->line("  {$code}  MARK={$row->mark}  {$row->problem}");
            }
        }

        if ($result->hasDiscrepancies()) {
            $this->newLine();
            $this->warn($result->discrepancyCount().' discrepancies found.');

            return $this->recordHealthAndReturn($tenant, 2, $result->discrepancyCount());
        }

        $this->newLine();
        $this->info('All local invoices agree with AADE.');

        return $this->recordHealthAndReturn($tenant, self::SUCCESS, 0);
    }

    private function recordHealthAndReturn(Company $tenant, int $exitCode, int $discrepancies = 0): int
    {
        app(HealthRecorder::class)->recordMyDataReconcile($tenant, $exitCode, $discrepancies);

        return $exitCode;
    }
}
