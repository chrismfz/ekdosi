<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\GoLive\GoLiveCheckReport;
use Illuminate\Console\Command;

/**
 * Per-tenant cutover-readiness gate. Read-only — consolidates the «can this
 * tenant issue real documents?» checks into one pass/warn/fail report so the
 * operator doesn't go terminal-archaeology before flipping a tenant live.
 *
 * Delegates the gate logic to GoLiveCheckReport (reuses Codes + Company
 * predicates + OperatorHealthReport). Pairs with the manual steps in
 * docs/go-live-runbook.md (the Firebird usage-checks + the real AADE
 * production smoke-test, which CAN'T be automated here).
 *
 * Exit codes (mirrors mydata:preflight): 0 = ready (warnings allowed),
 * 1 = command error (missing/unknown tenant), 2 = at least one FAIL gate.
 *
 * Usage:
 *   php artisan ekdosi:go-live-check --tenant=myip
 *   php artisan ekdosi:go-live-check --tenant=myip --json
 */
class GoLiveCheck extends Command
{
    protected $signature = 'ekdosi:go-live-check {--tenant= : Company slug or id (required)} {--json}';

    protected $description = 'Per-tenant cutover-readiness gate (read-only): can this tenant issue real documents?';

    public function handle(): int
    {
        $arg = $this->option('tenant');
        if (empty($arg)) {
            $this->error('--tenant is required (slug or id).');

            return self::FAILURE;
        }

        $tenant = Company::findBySlugOrId((string) $arg);
        if ($tenant === null) {
            $this->error("Tenant '{$arg}' not found.");

            return self::FAILURE;
        }

        $report = app(GoLiveCheckReport::class)->build($tenant);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return $report['overall'] === 'ready' ? self::SUCCESS : 2;
        }

        $this->renderTable($report);

        return $report['overall'] === 'ready' ? self::SUCCESS : 2;
    }

    /** @param array<string,mixed> $report */
    private function renderTable(array $report): void
    {
        $t = $report['tenant'];
        $this->newLine();
        $this->line("<options=bold>Go-live check — {$t['name']} (#{$t['id']}, {$t['provider']})</>");
        $this->line(str_repeat('─', 60));

        foreach ($report['gates'] as $g) {
            [$icon, $color] = match ($g['status']) {
                'pass' => ['✓', 'green'],
                'warn' => ['⚠', 'yellow'],
                'fail' => ['✗', 'red'],
                default => ['–', 'gray'],   // skip
            };
            $label = str_pad($g['label'], 32);
            $this->line("<fg={$color}>  {$icon}</> {$label} <fg=gray>{$g['detail']}</>");
        }

        $s = $report['summary'];
        $this->line(str_repeat('─', 60));
        $this->line("Σύνοψη: {$s['pass']} ✓ · {$s['warn']} ⚠ · {$s['fail']} ✗ · {$s['skip']} –");

        if ($report['overall'] === 'ready') {
            $this->newLine();
            $this->info('✓ ΕΤΟΙΜΟΣ — καμία αποτυχία. (Δες το runbook για το χειροκίνητο AADE production smoke-test πριν την τελική μετάβαση.)');
        } else {
            $this->newLine();
            $this->error('✗ ΟΧΙ ΕΤΟΙΜΟΣ — διόρθωσε τα ✗ παραπάνω πριν την έκδοση πραγματικών παραστατικών.');
        }
    }
}
