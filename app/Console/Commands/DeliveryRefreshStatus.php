<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Services\Delivery\DeliveryLifecycleService;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «Έλεγχος κατάστασης» on a schedule — re-reads from AADE (RequestDeliveryNoteStatus,
 * READ-ONLY) the δελτία 9.3 and ΤΔΑ whose movement another party can still move:
 * a recipient scanning the QR («αναμένεται ο παραλήπτης» → «Παραδόθηκε») or
 * rejecting, a carrier starting / delivering / returning (DeliveryLifecycleService::
 * OPEN_STATES). Keeps «Ανεπιβεβαίωτα» and the δελτίο pages honest without anyone
 * pressing the button.
 *
 * Per myDATA-readable tenant, explicit company_id (CLAUDE.md CLI rule); least-recently-
 * CHECKED first (movement_checked_at), capped per run, a small gap between AADE calls; only the last
 * 90 days (a movement older than that won't move any more). A per-document failure
 * (usually a transient AADE hiccup) is logged and skipped — the next run retries.
 *
 * Usage:
 *   php artisan delivery:refresh-status                 # all myDATA-readable tenants
 *   php artisan delivery:refresh-status --tenant=nexon --limit=100
 */
class DeliveryRefreshStatus extends Command
{
    protected $signature = 'delivery:refresh-status
        {--tenant= : Company slug or id (default: all myDATA-readable)}
        {--limit=40 : Max documents per tenant per run}
        {--days=90 : Only documents issued in the last N days}
        {--gap=1 : Seconds to wait between AADE calls}';

    protected $description = 'READ-ONLY: re-read from AADE the status of open digital-movement δελτία/ΤΔΑ (Έλεγχος κατάστασης).';

    /** Test seam: a MockHandler threaded into the lifecycle service (no network). */
    public static ?MockHandler $testHandler = null;

    public function handle(): int
    {
        $tenants = $this->resolveTenants();
        $limit = max(1, (int) $this->option('limit'));
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $gap = static::$testHandler === null ? max(0, (int) $this->option('gap')) : 0;

        foreach ($tenants as $tenant) {
            $docs = $this->openDocuments($tenant, $since, $limit);
            if ($docs->isEmpty()) {
                continue;
            }

            $svc = new DeliveryLifecycleService($tenant, static::$testHandler);
            $changed = $failed = 0;

            foreach ($docs as $i => $doc) {
                if ($i > 0 && $gap > 0) {
                    sleep($gap);
                }
                try {
                    $changed += $svc->refreshStatus($doc)['changed'] ? 1 : 0;
                    // Poll bookkeeping only — a raw update: no updated_at bump, no activity
                    // log, never touches the document itself. Drives the least-recently-
                    // checked-first order (a no-change refresh doesn't save the row).
                    DB::table($doc->getTable())->where('id', $doc->getKey())->update(['movement_checked_at' => now()]);
                } catch (Throwable $e) {
                    $failed++;
                    Log::warning('delivery:refresh-status failed for a document', [
                        'company' => $tenant->slug, 'doc' => $doc->invcode, 'error' => get_class($e).': '.$e->getMessage(),
                    ]);
                }
            }

            $this->line("✓ {$tenant->slug}: ελέγχθηκαν {$docs->count()}, άλλαξαν {$changed}".($failed ? ", απέτυχαν {$failed}" : ''));
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, DeliveryNote|Invoice> least-recently-checked (never-checked) first */
    private function openDocuments(Company $tenant, $since, int $limit): Collection
    {
        $notes = DeliveryNote::query()
            ->where('company_id', $tenant->getKey())
            ->where('mydata_state', 'VALID')
            ->whereIn('delivery_state', DeliveryLifecycleService::OPEN_STATES)
            ->where('issued_at', '>=', $since)
            ->orderByRaw('movement_checked_at IS NOT NULL, movement_checked_at')   // never-checked first
            ->limit($limit)
            ->get();

        $tdas = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->where('is_delivery_note', true)
            ->where('without_digital_transport_tracking', false)
            ->where('mydata_state', 'VALID')
            ->whereIn('delivery_state', DeliveryLifecycleService::OPEN_STATES)
            ->where('issued_at', '>=', $since)
            ->orderByRaw('movement_checked_at IS NOT NULL, movement_checked_at')
            ->limit($limit)
            ->get();

        return $notes->concat($tdas)
            ->sortBy(fn ($d) => $d->movement_checked_at?->getTimestamp() ?? 0)
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, Company> */
    private function resolveTenants(): Collection
    {
        if ($arg = $this->option('tenant')) {
            $tenant = Company::findBySlugOrId($arg);
            if (! $tenant) {
                $this->error("Tenant '{$arg}' not found.");

                return collect();
            }

            return collect([$tenant]);
        }

        return Company::myDataReadable();
    }
}
