<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ParsesTenantAndYears;
use App\Models\E3YearSnapshot;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * The SCHEDULED twin of mydata:import-expenses + mydata:e3-snapshot: keeps a
 * tenant's expenses in step with myDATA without anyone pressing «Εισαγωγή
 * αδέσποτων» / «Λήψη δικών μας εξόδων» / «Ανανέωση Ε3» — supplier docs, our
 * self-declared 13/14/17.x, AND the Ε3 snapshot, which also counts what the
 * accountant filed under THEIR credentials (RequestTransmittedDocs only returns
 * what WE submitted — spec §4.2.2). Without it «Φορολογικά» reads missing
 * expenses as profit.
 *
 * Takes only --tenant (TenantScheduleSweep calls it per myDATA-readable company)
 * and picks the window itself: the running year, plus the previous one until the
 * end of July — the accountant keeps posting last year's 17.x until the Ε3 is
 * filed. Delegates to mydata:import-expenses (same quarter loop, rate-limit
 * handling, idempotency by MARK) with --hold-manual, so a doc that looks like a
 * hand-typed expense is left for the operator instead of being booked twice.
 */
class MyDataSyncExpenses extends Command
{
    use ParsesTenantAndYears;

    /** Last month (inclusive) in which the previous year is still re-scanned. */
    public const PREVIOUS_YEAR_UNTIL_MONTH = 7;

    /** Older Ε3 snapshots without the month split re-fetched per run (one AADE call each). */
    public const HEAL_PER_RUN = 3;

    protected $signature = 'mydata:sync-expenses
        {--tenant= : Company slug or id (required)}
        {--gap=2 : Seconds to wait between AADE calls}';

    protected $description = 'Refresh the Ε3 snapshot + import new myDATA expenses (supplier docs + our 13/14/17.x) for the running year (+ last year until July). Idempotent; scheduled.';

    public function handle(): int
    {
        $tenant = $this->tenantOption();
        if (! $tenant) {
            return self::FAILURE;
        }

        if (! $tenant->canReadMyData()) {
            $this->info("{$tenant->slug}: δεν διαβάζει από myDATA — παράλειψη.");

            return self::SUCCESS;
        }

        // NEWEST year first: if AADE rate-limits part-way, the running year — what
        // «Φορολογικά» needs — is already in; last year's re-scan is the part that waits.
        // Per year: the Ε3 snapshot FIRST (one cheap call; it is what carries the
        // accountant's entries filed under THEIR credentials), then the documents.
        $failed = [];
        foreach (array_reverse(self::windowYears(now())) as $year) {
            $steps = [
                'Ε3' => ['mydata:e3-snapshot', ['--tenant' => $tenant->slug, '--year' => [$year]]],
                'έξοδα' => ['mydata:import-expenses', [
                    '--tenant' => $tenant->slug, '--year' => [$year],
                    '--hold-manual' => true, '--gap' => (int) $this->option('gap'),
                ]],
            ];
            foreach ($steps as $label => [$command, $args]) {
                if ($this->call($command, $args) !== self::SUCCESS) {
                    $failed[] = "{$label} {$year}";
                }
            }
        }

        // One-off heal: an older snapshot fetched before the month split existed
        // (`monthly` null) shows local months under an Ε3 year row on «Φορολογικά»
        // until re-fetched. Outside the window nothing else refreshes it, so do it
        // here — a few per night; once refreshed it never matches again.
        $stale = E3YearSnapshot::query()
            ->where('company_id', $tenant->getKey())
            ->whereNull('monthly')
            ->whereNotIn('year', self::windowYears(now()))
            ->orderByDesc('year')
            ->limit(self::HEAL_PER_RUN)
            ->pluck('year');
        foreach ($stale as $year) {
            if ($this->call('mydata:e3-snapshot', ['--tenant' => $tenant->slug, '--year' => [(int) $year]]) !== self::SUCCESS) {
                $failed[] = "Ε3 {$year}";
            }
        }

        // Throw, don't return: TenantScheduleSweep reacts only to exceptions
        // (report() → error log, then the next tenant), so a quiet exit code would
        // leave a rate-limited/failed night looking like a success.
        if ($failed !== []) {
            throw new RuntimeException("mydata:sync-expenses {$tenant->slug}: απέτυχε ".implode(', ', $failed).' (όριο/σφάλμα myDATA) — ξανατρέχει την επόμενη νύχτα.');
        }

        return self::SUCCESS;
    }

    /** @return list<int> ascending */
    public static function windowYears(CarbonInterface $today): array
    {
        $year = (int) $today->year;

        return $today->month <= self::PREVIOUS_YEAR_UNTIL_MONTH ? [$year - 1, $year] : [$year];
    }
}
