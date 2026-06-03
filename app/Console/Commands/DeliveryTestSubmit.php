<?php

namespace App\Console\Commands;

use App\Models\DeliveryNote;
use App\Services\Delivery\DeliveryNoteSubmitter;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI to exercise the DeliveryNoteSubmitter (myDATA Δελτίο Αποστολής, 9.x)
 * outside the Filament panel — for sandbox-validating the 9.3 payload against
 * the AADE dev endpoint and diffing the generated XML.
 *
 * Defaults to a SAFE dry-run that just PRINTS the XML that would be sent (no
 * AADE call, no audit row). --execute performs the real submission (a deliberate
 * confirm ceremony), so the tenant must have dev myDATA credentials configured
 * and the host must reach the AADE dev endpoint.
 *
 * Usage:
 *   php artisan delivery:test-submit <delivery-note-id>             # dry-run, prints XML
 *   php artisan delivery:test-submit <delivery-note-id> --execute   # REAL submission to AADE
 */
class DeliveryTestSubmit extends Command
{
    protected $signature = 'delivery:test-submit
        {note : DeliveryNote ID (the numeric primary key, NOT the invcode)}
        {--execute : Actually POST to AADE (default is a dry-run that prints the XML)}';

    protected $description = 'Build/submit the myDATA Δελτίο Αποστολής XML for a delivery note. Dry-run (prints XML) by default; --execute for the real thing.';

    public function handle(): int
    {
        $note = DeliveryNote::find((int) $this->argument('note'));
        if (! $note) {
            $this->error("Delivery note #{$this->argument('note')} not found.");

            return self::FAILURE;
        }

        $tenant = $note->company;
        if (! $tenant) {
            $this->error("Delivery note #{$note->id} has no company association.");

            return self::FAILURE;
        }

        $this->line("Tenant: {$tenant->name} (#{$tenant->id}) — mode={$tenant->mydata_mode}");
        $this->line("Δελτίο: {$note->invcode} (#{$note->id}) — type={$note->mydata_type} state=".($note->mydata_state ?? 'draft'));
        $this->newLine();

        $submitter = new DeliveryNoteSubmitter($tenant);

        try {
            if ($this->option('execute')) {
                $this->warn('--execute set: posting to AADE. This is a REAL submission to the configured environment.');
                if (! $this->confirm('Continue?', false)) {
                    $this->line('Aborted.');

                    return self::SUCCESS;
                }
                $mark = $submitter->submit($note);
                $this->info("Submitted. MARK={$mark->mark}");
                if ($mark->invoice_url) {
                    $this->line("QR URL: {$mark->invoice_url}");
                }
                $this->line('delivery_state is now: '.$note->fresh()->delivery_state);
            } else {
                // Dry-run: print the exact XML the submitter would send. No
                // network, no audit row — safe to run anywhere.
                $this->line($submitter->previewXml($note));
            }
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
