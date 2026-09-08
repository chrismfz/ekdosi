<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\EInvoice\Transports\InvoSignDocument;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Support\EInvoice\ProviderCredentials;
use Illuminate\Console\Command;
use Throwable;

/**
 * Provider test-submit (P4). Default is a DRY-RUN: builds and prints the exact
 * payload the provider transport would send (the canonical AADE InvoicesDoc, plus
 * the InvoSign extension for invosign tenants) WITHOUT contacting the provider and
 * WITHOUT persisting anything.
 *
 * --execute actually POSTs to the provider (sandbox/production per the tenant's
 * mode) and prints the ProviderResult — but does NOT persist a mydata_marks row
 * (it's a connectivity/payload probe, not a real filing; use the invoice lifecycle
 * for that). Three-step ceremony like mydata:test-submit.
 *
 *   php artisan einvoice:provider-test-submit <invoiceId>            # dry-run, prints payload
 *   php artisan einvoice:provider-test-submit <invoiceId> --execute  # REAL provider call (no persist)
 */
class EInvoiceProviderTestSubmit extends Command
{
    protected $signature = 'einvoice:provider-test-submit
        {invoice : Invoice ID (numeric PK)}
        {--execute : Actually POST to the provider (default is dry-run)}';

    protected $description = 'Build (and optionally send) the provider payload for an invoice. Dry-run by default.';

    public function handle(ProviderTransportRegistry $registry): int
    {
        $invoice = Invoice::find((int) $this->argument('invoice'));
        if (! $invoice) {
            $this->error('Invoice not found.');

            return self::FAILURE;
        }

        $tenant = $invoice->company;
        if (! $tenant || $tenant->einvoice_provider !== 'gr-provider') {
            $this->error('Invoice tenant is not a provider tenant (einvoice_provider=gr-provider).');

            return self::FAILURE;
        }

        $key = (string) $tenant->einvoice_provider_key;
        $this->line("Tenant : {$tenant->name} — provider={$key} mode={$tenant->einvoice_provider_mode}");
        $this->line("Invoice: {$invoice->invcode} (#{$invoice->id})");
        $this->newLine();

        try {
            $document = new AadeInvoiceDocument($tenant);
            $aadeXml = $document->toXml($document->build($invoice));
            $payload = $key === 'invosign' ? InvoSignDocument::augment($aadeXml, $invoice) : $aadeXml;
        } catch (Throwable $e) {
            $this->error('Could not build the payload: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('execute')) {
            $this->line('--- PAYLOAD (dry-run, not sent) ---');
            $this->line($payload);

            return self::SUCCESS;
        }

        $transport = $registry->for($key);
        if ($transport instanceof NullProviderTransport) {
            $this->error("Provider «{$key}» has no registered transport — cannot execute.");

            return self::FAILURE;
        }

        $this->warn('--execute: POSTing to the provider. This is a REAL call (sandbox or production per the tenant mode).');
        if (! $this->confirm('Continue?', false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        try {
            $result = $transport->send($invoice, $aadeXml, ProviderCredentials::fromCompany($tenant));
        } catch (Throwable $e) {
            $this->error('Provider call failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result->success) {
            $this->info('Provider accepted. ΜΑΡΚ='.($result->mark ?? '—'));
            $this->line('Auth code: '.($result->authenticationCode ?? '—'));
            $this->line('QR: '.($result->qrUrl ?? '—'));
            $this->newLine();
            $this->comment('NOTE: nothing was persisted — issue via the invoice lifecycle for a real filing.');

            return self::SUCCESS;
        }

        $this->error('Provider rejected: '.$result->errorMessage());

        return self::FAILURE;
    }
}
