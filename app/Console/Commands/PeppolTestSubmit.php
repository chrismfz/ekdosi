<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\Peppol\PeppolInvoiceDocument;
use Illuminate\Console\Command;

/**
 * Dry-run the PEPPOL BIS 3.0 (EN 16931) UBL build for an invoice — the
 * PEPPOL twin of `mydata:test-submit`. Prints the UBL XML and runs the
 * library's EN 16931 + PEPPOL validation. READ-ONLY: nothing is sent
 * (the Access-Point transport is Phase 2). Useful to eyeball the document
 * an Estonian tenant would file before any provider is wired.
 *
 *   php artisan peppol:test-submit <invoiceId>          # print XML + validate
 *   php artisan peppol:test-submit <invoiceId> --quiet-xml   # only the validation verdict
 */
class PeppolTestSubmit extends Command
{
    protected $signature = 'peppol:test-submit
        {invoice : Invoice id}
        {--quiet-xml : Skip printing the XML, only show the validation result}';

    protected $description = 'Build/validate the PEPPOL BIS 3.0 UBL for an invoice (dry-run, read-only).';

    public function handle(PeppolInvoiceDocument $doc): int
    {
        $invoice = Invoice::query()->withoutGlobalScopes()->find($this->argument('invoice'));
        if ($invoice === null) {
            $this->error('Δεν βρέθηκε τιμολόγιο με αυτό το id.');

            return self::FAILURE;
        }

        try {
            $xml = $doc->xml($invoice);
        } catch (\Throwable $e) {
            $this->error('Αποτυχία δημιουργίας UBL: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('quiet-xml')) {
            $this->line($xml);
            $this->newLine();
        }

        $error = $doc->validate($invoice);
        if ($error === null) {
            $this->info("✓ Έγκυρο PEPPOL BIS 3.0 (EN 16931) — invcode {$invoice->invcode}.");

            return self::SUCCESS;
        }

        $this->error('✗ Validation: '.$error);

        return self::FAILURE;
    }
}
