<?php

namespace App\Console\Commands;

use App\Exceptions\Whmcs\WhmcsApiException;
use App\Exceptions\Whmcs\WhmcsAuthenticationFailed;
use App\Exceptions\Whmcs\WhmcsNotConfigured;
use App\Exceptions\Whmcs\WhmcsUnreachable;
use App\Models\Company;
use App\Services\Whmcs\HistoricalInvoiceMatcher;
use App\Services\Whmcs\WhmcsClient;
use App\Services\Whmcs\WhmcsClientFactory;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Recover the historical WHMCS#→ekdosi-ΤΠΥ link the legacy import couldn't
 * carry (no stored mapping survived — verified NULL/empty in prod). INFERS the
 * link from invoice content and writes confident matches into
 * whmcs_invoice_log.invoice_id (+ match_method/confidence). Ambiguous /
 * unmatched WHMCS invoices are left for operator review — never written.
 *
 *   php artisan whmcs:match-historical --tenant=myip            # last 12 months
 *   php artisan whmcs:match-historical --tenant=myip --months=3 # last quarter
 *   php artisan whmcs:match-historical --tenant=myip --since=2025-01-01
 *   php artisan whmcs:match-historical --tenant=myip --preview  # read-only
 *
 * Window matters: a long-running tenant (myip: invoices since 2007 — 16.7k
 * paid) would be murder to pull whole. The window caps BOTH the WHMCS API pull
 * and the ekdosi index. Default 12 months.
 *
 * Tiers (see HistoricalInvoiceMatcher):
 *   - line_text (HIGH):   needs the per-invoice line descriptions → one
 *                         GetInvoice call per WHMCS invoice (N+1 on the API,
 *                         bounded by the window). ON by default; --no-line-text
 *                         skips it and matches amount+date only (zero extra
 *                         API calls — only the GetInvoices list pages).
 *   - amount_date (MED):  total + date ±1, from the list shape alone.
 *
 * Pulls via getPaidInvoices (ALL Paid in the window) — NOT getPendingInvoices,
 * which drops already-filed rows (invoiced != 0); the historical invoices we
 * want are precisely the already-filed ones.
 *
 * Exit codes mirror whmcs:fetch-pending (3 not-configured, 4 auth, 5
 * unreachable, 6 unknown tenant).
 */
class WhmcsMatchHistorical extends Command
{
    protected $signature = 'whmcs:match-historical
        {--tenant= : Company slug. Required.}
        {--months=12 : Match WHMCS invoices issued within the last N months (ignored if --since is set).}
        {--since= : Explicit cutoff date YYYY-MM-DD (overrides --months).}
        {--no-line-text : Skip the high-confidence line-text tier (no per-invoice GetInvoice calls); match amount+date only.}
        {--min-confidence=medium : Lowest tier to ACCEPT: "high" (line_text only — safest) or "medium" (also amount_date).}
        {--diagnose : For unmatched invoices, print the WHMCS line text + nearest ekdosi product_descr so a text mismatch is visible. Implies --preview.}
        {--limit=100 : WHMCS list page size (WHMCS caps at ~100).}
        {--max-pages=50 : Safety cap on list pages to walk.}
        {--preview : Read-only; report what WOULD be linked, write nothing.}';

    protected $description = 'WHMCS bridge: infer + record the historical WHMCS#→ekdosi-ΤΠΥ links the legacy import could not carry (content-matched, confidence-tagged, idempotent).';

    public function handle(WhmcsClientFactory $factory, HistoricalInvoiceMatcher $matcher): int
    {
        $slug = (string) $this->option('tenant');
        if ($slug === '') {
            $this->error('--tenant=SLUG is required.');

            return Command::INVALID;
        }

        $tenant = Company::query()->where('slug', $slug)->first();
        if ($tenant === null) {
            $this->error("No tenant with slug='{$slug}'.");

            return 6;
        }

        $since = $this->resolveSince();
        $diagnose = (bool) $this->option('diagnose');
        $withLineText = ! (bool) $this->option('no-line-text') || $diagnose;
        $preview = (bool) $this->option('preview') || $diagnose;
        $minConfidence = strtolower((string) $this->option('min-confidence')) === 'high' ? 'high' : 'medium';

        $this->info("Tenant: {$tenant->name} (slug={$tenant->slug})");
        $this->line('Window: invoices issued on/after '.$since->toDateString()
            .' · tier: '.($withLineText ? 'line_text + amount_date' : 'amount_date only')
            .' · accept: '.$minConfidence.'+'
            .($preview ? ' · PREVIEW (no writes)' : ''));

        try {
            $client = $factory->for($tenant);
        } catch (WhmcsNotConfigured $e) {
            $this->error($e->getMessage());

            return 3;
        }

        // Build the ekdosi side index ONCE.
        $index = $matcher->buildIndex($tenant, $since);
        $this->line('ekdosi index: '.count($index['gross']).' live invoice(s) in window, '
            .count($index['text']).' distinct line-text key(s).');

        // Walk the WHMCS paid invoices in the window (DESC, early-stop on date).
        $sinceStr = $since->toDateString();
        $limit = max(1, (int) $this->option('limit'));
        $maxPages = max(1, (int) $this->option('max-pages'));

        $stats = ['seen' => 0, 'high' => 0, 'medium' => 0, 'none' => 0, 'written' => 0,
            'skipped_manual' => 0, 'zero' => 0, 'below_threshold' => 0];
        $samples = [];
        $diagSamples = [];

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $rows = $client->getPaidInvoices(limit: $limit, offset: $page * $limit, minDate: $sinceStr);
                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $stats['seen']++;
                    $whmcsId = (int) ($row['id'] ?? 0);
                    $total = round((float) ($row['total'] ?? 0), 2);
                    $date = (string) ($row['date'] ?? '');
                    if ($whmcsId <= 0) {
                        continue;
                    }
                    if ($total <= 0.0) {
                        $stats['zero']++;

                        continue;   // €0 invoices never produced a ΤΠΥ
                    }

                    $descriptions = [];
                    if ($withLineText) {
                        $descriptions = $this->lineDescriptions($client, $whmcsId);
                    }

                    $match = $matcher->match(
                        ['total' => $total, 'date' => $date, 'descriptions' => $descriptions],
                        $index,
                    );

                    if ($match === null) {
                        $stats['none']++;
                        // --diagnose: show WHY line_text missed — the WHMCS text
                        // next to the nearest ekdosi product_descr. Reveals a
                        // fixable format drift (period/prefix) vs a true absence.
                        if ($diagnose && count($diagSamples) < 15 && $descriptions !== []) {
                            $diagSamples[] = $this->diagnoseLine($whmcsId, $total, $descriptions, $index);
                        }

                        continue;
                    }

                    $stats[$match['confidence'] === 'high' ? 'high' : 'medium']++;
                    if (count($samples) < 10) {
                        $samples[] = sprintf('  WHMCS #%d (%s, %.2f€) → invoice_id %d [%s/%s]',
                            $whmcsId, $date, $total, $match['invoice_id'], $match['method'], $match['confidence']);
                    }

                    // Confidence gate: never WRITE below the accepted tier. A
                    // medium (amount_date) match is reported but not persisted
                    // when --min-confidence=high (the safe choice tenant-wide,
                    // where a common renewal price collides easily).
                    $accepted = $minConfidence === 'medium' || $match['confidence'] === 'high';

                    if (! $preview && $accepted) {
                        if ($matcher->persist($tenant, $whmcsId, $match)) {
                            $stats['written']++;
                        } else {
                            $stats['skipped_manual']++;
                        }
                    } elseif (! $accepted) {
                        $stats['below_threshold']++;
                    }
                }

                // getPaidInvoices early-stops on minDate within a page, so a
                // short page means we've crossed the window edge.
                if (count($rows) < $limit) {
                    break;
                }
            }
        } catch (WhmcsAuthenticationFailed $e) {
            $this->error("WHMCS authentication failed: {$e->getMessage()}");

            return 4;
        } catch (WhmcsUnreachable $e) {
            $this->error("WHMCS unreachable: {$e->getMessage()}");

            return 5;
        } catch (WhmcsApiException $e) {
            $this->error("WHMCS error: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->report($stats, $samples, $preview);

        if ($diagnose && $diagSamples !== []) {
            $this->line('');
            $this->warn('Diagnostics — unmatched WHMCS line text vs nearest ekdosi product_descr:');
            foreach ($diagSamples as $d) {
                $this->line($d);
            }
            $this->line('');
            $this->line('If the texts are CLOSE but not equal (period format, prefix, an operator edit),');
            $this->line('line_text matching needs a tweak. If they are UNRELATED, the ekdosi side has no');
            $this->line('such invoice in the window — widen --months or accept --min-confidence=medium.');
        }

        return Command::SUCCESS;
    }

    /**
     * Build a one-line diagnostic for an unmatched WHMCS invoice: its first
     * line text + the closest ekdosi product_descr by similarity. Shows
     * whether line_text failed on a fixable format drift or a true absence.
     *
     * @param  list<string>  $descriptions
     * @param  array{text: array<string, array<int, true>>, amountDate: array<string, array<int, true>>, gross: array<int, float>}  $index
     */
    private function diagnoseLine(int $whmcsId, float $total, array $descriptions, array $index): string
    {
        $whmcsText = trim($descriptions[0]);
        $needle = mb_strtolower(preg_replace('/\s+/u', ' ', $whmcsText) ?? $whmcsText);

        $best = null;
        $bestScore = -1.0;
        foreach (array_keys($index['text']) as $candidate) {
            similar_text($needle, $candidate, $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $candidate;
            }
        }

        return sprintf("  #%d (%.2f€)\n    WHMCS:  %s\n    ekdosi: %s  (%.0f%% similar)",
            $whmcsId, $total, $whmcsText, $best ?? '—', $bestScore < 0 ? 0 : $bestScore);
    }

    /** @return list<string> the WHMCS invoice's line descriptions (one GetInvoice call). */
    private function lineDescriptions(WhmcsClient $client, int $whmcsInvoiceId): array
    {
        $invoice = $client->getInvoice($whmcsInvoiceId);
        if ($invoice === null) {
            return [];
        }
        $items = $invoice['items']['item'] ?? [];
        if (! empty($items) && ! array_is_list($items)) {
            $items = [$items];   // WHMCS returns a single item as an object
        }

        $out = [];
        foreach ($items as $item) {
            $descr = trim((string) ($item['description'] ?? ''));
            if ($descr !== '') {
                $out[] = $descr;
            }
        }

        return $out;
    }

    private function resolveSince(): Carbon
    {
        $sinceOpt = (string) ($this->option('since') ?? '');
        if ($sinceOpt !== '') {
            try {
                return Carbon::parse($sinceOpt)->startOfDay();
            } catch (\Throwable $e) {
                $this->warn("Could not parse --since='{$sinceOpt}'; falling back to --months.");
            }
        }

        return Carbon::now()->subMonths(max(1, (int) $this->option('months')))->startOfDay();
    }

    /**
     * @param  array<string, int>  $stats
     * @param  list<string>  $samples
     */
    private function report(array $stats, array $samples, bool $preview): void
    {
        $this->line('');
        $this->table(['Metric', 'Count'], [
            ['WHMCS invoices seen', $stats['seen']],
            ['  €0 skipped (no ΤΠΥ)', $stats['zero']],
            ['HIGH (line_text)', $stats['high']],
            ['MEDIUM (amount_date)', $stats['medium']],
            ['  below threshold (not written)', $stats['below_threshold']],
            ['No confident match → review', $stats['none']],
            [$preview ? 'WOULD write' : 'Links written', $preview ? ($stats['high'] + $stats['medium'] - $stats['below_threshold']) : $stats['written']],
            ['Skipped (manual link kept)', $stats['skipped_manual']],
        ]);

        if ($samples !== []) {
            $this->line('Sample matches:');
            foreach ($samples as $s) {
                $this->line($s);
            }
        }

        if ($preview) {
            $this->warn('PREVIEW — nothing was written. Re-run without --preview to persist.');
        } else {
            $this->info('Done. Ambiguous/unmatched WHMCS invoices were left for operator review (not written).');
        }
    }
}
