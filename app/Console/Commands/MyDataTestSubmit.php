<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\EInvoice\AadeInvoiceDocument;
use App\Services\MyDataRejected;
use App\Services\MyDataSubmitter;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI shortcut to exercise the MyDataSubmitter flow without UI.
 * Useful for debugging XML differences against the legacy filed
 * versions, scripted integration tests, and verifying credentials
 * outside the Filament panel context.
 *
 * Defaults to --dry-run (safe). To actually submit you have to pass
 * --execute, which is a deliberate three-step ceremony:
 *   1. Invoice must exist
 *   2. Tenant must be configured for myDATA
 *   3. Operator must explicitly opt into the real submission
 *
 * Usage:
 *   php artisan mydata:test-submit <invoice-id>                  # dry-run, prints XML
 *   php artisan mydata:test-submit <invoice-id> --print-only     # dry-run, no audit row
 *   php artisan mydata:test-submit <invoice-id> --execute        # REAL submission
 */
class MyDataTestSubmit extends Command
{
    protected $signature = 'mydata:test-submit
        {invoice : Invoice ID (NOT invcode — the numeric primary key)}
        {--execute : Actually POST to AADE (default is dry-run)}
        {--print-only : Print the XML to stdout WITHOUT persisting a DRY_RUN audit row}';

    protected $description = 'Build the myDATA submission XML for an invoice. Dry-run by default; --execute for the real thing.';

    public function handle(): int
    {
        $invoiceId = (int) $this->argument('invoice');
        $invoice = Invoice::find($invoiceId);
        if (! $invoice) {
            $this->error("Invoice #{$invoiceId} not found.");

            return self::FAILURE;
        }

        $tenant = $invoice->company;
        if (! $tenant) {
            $this->error("Invoice #{$invoiceId} has no company association.");

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $printOnly = (bool) $this->option('print-only');

        if ($execute && $printOnly) {
            $this->error('--execute and --print-only are mutually exclusive.');

            return self::FAILURE;
        }

        $this->line("Tenant : {$tenant->name} (#{$tenant->id}) — mode={$tenant->mydata_mode}");
        $this->line("Invoice: {$invoice->invcode} (#{$invoice->id})");
        $this->newLine();

        $submitter = new MyDataSubmitter($tenant);

        try {
            if ($execute) {
                $this->warn('--execute set: posting to AADE. This is a REAL submission.');
                if (! $this->confirm('Continue?', false)) {
                    $this->line('Aborted.');

                    return self::SUCCESS;
                }
                $mark = $submitter->submit($invoice);
                $this->info("Submitted. MARK={$mark->mark}");
                if ($mark->invoice_url) {
                    $this->line("QR URL: {$mark->invoice_url}");
                }
            } elseif ($printOnly) {
                // Print without persisting an audit row. Useful for
                // CI / scripted XML diffing against a golden file. Builds
                // the payload via the same AadeInvoiceDocument the submitter
                // uses — no reflection needed since the P0 factor-out.
                $document = new AadeInvoiceDocument($tenant);
                $payload = $document->build($invoice);
                $this->line($document->toXml($payload));
            } else {
                $mark = $submitter->previewXml($invoice);
                $this->info("Dry-run recorded (mydata_marks row #{$mark->id}).");
                $this->line('Inspect the XML via:');
                $this->line('   SELECT request FROM mydata_marks WHERE id = '.$mark->id);
                $this->line('Or open the invoice view page; the DRY_RUN row is in the myDATA history.');
            }
        } catch (MyDataRejected $e) {
            // AADE rejected the document — surface the round-trip so the
            // operator can see WHAT was sent and WHY it was refused.
            $this->error('AADE rejected: '.$e->getMessage());
            $this->newLine();
            $this->line('--- REQUEST ---');
            $this->line($e->requestXml);
            $this->newLine();
            $this->line('--- RESPONSE (AADE) ---');
            $this->line($e->responseXml);
            $this->newLine();
            $this->line('A REJECTED row was written to mydata_marks (visible in the invoice myDATA history).');

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());
            $this->line('Exception class: '.get_class($e));
            if ($prev = $e->getPrevious()) {
                $this->line('Caused by: '.get_class($prev).' — '.$prev->getMessage());
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
