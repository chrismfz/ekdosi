<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDeliveryReport;
use App\Models\DeliveryNote;
use App\Services\Delivery\DeliveryLifecycleService;
use Illuminate\Console\Command;

/**
 * Run the e-transport lifecycle (Β' φάση) on an ALREADY-FILED δελτίο — the one
 * you issued from the UI or via delivery:test-submit --execute. Runs the
 * applicable steps in order: ΕΝΑΡΞΗ (RegisterTransfer) → ΠΑΡΑΔΟΣΗ
 * (ConfirmDeliveryOutcome) → ΕΛΕΓΧΟΣ (RequestDeliveryNoteStatus) → [ΑΚΥΡΩΣΗ].
 *
 * Default just prints the plan (current state + applicable steps). --execute
 * makes the real AADE calls and writes a .txt report.
 *
 *   php artisan delivery:test-lifecycle <id>                       # show the plan
 *   php artisan delivery:test-lifecycle <id> --execute             # run register→confirm→status
 *   php artisan delivery:test-lifecycle <id> --execute --cancel    # + cancel
 */
class DeliveryTestLifecycle extends Command
{
    use WritesDeliveryReport;

    protected $signature = 'delivery:test-lifecycle
        {note : DeliveryNote ID (numeric PK)}
        {--execute : Actually call AADE (default prints the plan only)}
        {--cancel : Also cancel the δελτίο at the end}
        {--report= : Report file path under storage/app}';

    protected $description = 'Drive the e-transport lifecycle (ΕΝΑΡΞΗ/ΠΑΡΑΔΟΣΗ/ΕΛΕΓΧΟΣ/ΑΚΥΡΩΣΗ) on a filed δελτίο.';

    public function handle(): int
    {
        $note = DeliveryNote::find((int) $this->argument('note'));
        if (! $note || ! $note->company) {
            $this->error("Delivery note #{$this->argument('note')} not found (or no company).");

            return self::FAILURE;
        }

        if (empty($note->mydata_mark) || $note->mydata_state !== 'VALID') {
            $this->error("Το δελτίο {$note->invcode} δεν είναι εκδομένο/VALID στο myDATA — έκδοσέ το πρώτα (delivery:test-submit --execute ή «Έκδοση» στο UI).");

            return self::FAILURE;
        }

        $this->section('Κύκλος ζωής διακίνησης');
        $this->kv('Δελτίο', "{$note->invcode} (#{$note->id})");
        $this->kv('myDATA', "VALID, MARK={$note->mydata_mark}");
        $this->kv('delivery_state', (string) ($note->delivery_state ?? '—'));

        if (! $this->option('execute')) {
            $this->section('ΣΧΕΔΙΟ (dry — δεν εκτελείται)');
            $this->kv('Έναρξη', $note->delivery_state === 'registered' ? 'ΘΑ ΤΡΕΞΕΙ' : 'παράλειψη (state ≠ registered)');
            $this->kv('Παράδοση', 'ΘΑ ΤΡΕΞΕΙ μετά την έναρξη (FULL)');
            $this->kv('Έλεγχος', 'ΘΑ ΤΡΕΞΕΙ');
            $this->kv('Ακύρωση', $this->option('cancel') ? 'ΘΑ ΤΡΕΞΕΙ' : 'όχι (χωρίς --cancel)');
            $this->writeReport($this->option('report'));
            $this->warn('Plan μόνο. Ξανατρέξε με --execute για πραγματικές κλήσεις AADE.');

            return self::SUCCESS;
        }

        $lifecycle = new DeliveryLifecycleService($note->company);
        $ok = true;

        if ($note->fresh()->delivery_state === 'registered') {
            $ok = $this->step('ΕΝΑΡΞΗ ΔΙΑΚΙΝΗΣΗΣ (RegisterTransfer)', $note, fn () => $lifecycle->registerTransfer($note)) && $ok;
        }
        if ($note->fresh()->delivery_state === 'in_transit') {
            $ok = $this->step('ΠΑΡΑΔΟΣΗ (ConfirmDeliveryOutcome / FULL)', $note, fn () => $lifecycle->confirmDelivery($note, 'FULL')) && $ok;
        }
        $ok = $this->step('ΕΛΕΓΧΟΣ ΚΑΤΑΣΤΑΣΗΣ (RequestDeliveryNoteStatus)', $note, fn () => $lifecycle->refreshStatus($note)) && $ok;
        if ($this->option('cancel')) {
            $ok = $this->step('ΑΚΥΡΩΣΗ (CancelInvoice)', $note, fn () => $lifecycle->cancel($note, 'sandbox validation')) && $ok;
        }

        $this->appendMarkXml($note->fresh());
        $this->section('ΣΥΝΟΨΗ');
        $this->kv('ΑΠΟΤΕΛΕΣΜΑ', $ok ? 'PASS ✓' : 'FAIL ✗');

        $path = $this->writeReport($this->option('report'));
        $this->info("Report: {$path}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
