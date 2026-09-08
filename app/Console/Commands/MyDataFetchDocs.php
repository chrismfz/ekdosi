<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\MyData\FirebedCredentials;
use Carbon\Carbon;
use Firebed\AadeMyData\Http\RequestDocs;
use Firebed\AadeMyData\Http\RequestVatInfo;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * READ-ONLY spike for the Έξοδα / Expenses phase (E0).
 *
 * Calls myDATA `RequestDocs` (docs OTHERS filed against us = expenses) and,
 * optionally, `RequestVatInfo` (εισροές–εκροές ΦΠΑ) for a tenant + window,
 * and prints either a short summary or the RAW response XML (`--raw`).
 *
 * Purpose: capture real payload shapes on the sandbox/production host so we
 * can lock the expense-document model (header-only vs per-line) BEFORE
 * writing the `expenses` table. Mutates nothing locally and files nothing.
 *
 * Usage:
 *   php artisan mydata:fetch-docs --tenant=myip --from=2026-01-01 --to=2026-03-31 --raw
 *   php artisan mydata:fetch-docs --tenant=myip --vat --grouped --raw
 *
 * Tip: pipe --raw to a file and share it back, e.g.
 *   php artisan mydata:fetch-docs --tenant=myip --raw > docs/samples/requestdocs-sample.xml
 */
class MyDataFetchDocs extends Command
{
    protected $signature = 'mydata:fetch-docs
        {--tenant= : Company slug (or numeric id)}
        {--from= : Window start (Y-m-d). Default: one month ago}
        {--to= : Window end (Y-m-d). Default: today}
        {--vat : Also call RequestVatInfo (εισροές–εκροές ΦΠΑ)}
        {--grouped : GroupedPerDay=true for RequestVatInfo (else per-invoice)}
        {--raw : Print the full response XML instead of a summary}';

    protected $description = 'READ-ONLY: fetch expense docs (RequestDocs) and optional VAT info (RequestVatInfo) from myDATA.';

    public function handle(): int
    {
        $tenantArg = $this->option('tenant');
        if (! $tenantArg) {
            $this->error('--tenant is required (company slug or id).');

            return self::FAILURE;
        }

        $tenant = Company::findBySlugOrId($tenantArg);

        if (! $tenant) {
            $this->error("Tenant '{$tenantArg}' not found.");

            return self::FAILURE;
        }

        try {
            $this->initFirebed($tenant);

            $from = ($this->option('from') ? Carbon::parse($this->option('from')) : now()->subMonth())->format('d/m/Y');
            $to = ($this->option('to') ? Carbon::parse($this->option('to')) : now())->format('d/m/Y');

            $this->info("RequestDocs — tenant={$tenant->slug} window={$from}..{$to}");

            // mark MUST be '' (non-null) per the AADE GET contract; firebed
            // mirrors the RequestTransmittedDocs signature.
            $docs = new RequestDocs;
            $docs->handle('', $from, $to);
            $this->dumpResponse($docs->getResponseXML(), 'RequestDocs');

            if ($this->option('vat')) {
                $grouped = (bool) $this->option('grouped');
                $this->newLine();
                $this->info('RequestVatInfo — GroupedPerDay='.($grouped ? 'true' : 'false'));
                $vat = new RequestVatInfo;
                $vat->handle($from, $to, null, $grouped);
                $this->dumpResponse($vat->getResponseXML(), 'RequestVatInfo');
            }

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            // Our own guard messages — safe to show verbatim.
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('myDATA call failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Initialise firebed credentials for the tenant. Delegates to the
     * shared FirebedCredentials (provider/mode/creds/decrypt guards) — the
     * read-only path needs no MockHandler.
     */
    private function initFirebed(Company $tenant): void
    {
        FirebedCredentials::init($tenant);
    }

    private function dumpResponse(?string $xml, string $label): void
    {
        $xml ??= '';

        if ($this->option('raw')) {
            $this->line($xml);

            return;
        }

        // Lightweight, shape-agnostic summary (we're still discovering the
        // exact element names — --raw is the authoritative capture).
        $invoices = substr_count($xml, '<invoice>') + substr_count($xml, '<invoice ');
        $cancels = substr_count($xml, '<cancelledInvoice');
        $vatRows = substr_count($xml, '<VatInfo') + substr_count($xml, '<vatInfo');

        $this->table(['metric', 'count'], [
            ['response bytes', mb_strlen($xml)],
            ['<invoice> elements', $invoices],
            ['<cancelledInvoice> elements', $cancels],
            ['<VatInfo> elements', $vatRows],
        ]);
        $this->comment("Run with --raw to print the full {$label} XML (and pipe it to a file to share).");
    }
}
