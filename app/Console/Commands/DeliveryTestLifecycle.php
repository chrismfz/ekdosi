<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDeliveryReport;
use App\Models\DeliveryNote;
use App\Services\Delivery\DeliveryLifecycleService;
use Illuminate\Console\Command;

/**
 * Run the ISSUER's e-transport lifecycle (Β' φάση) on an ALREADY-FILED δελτίο — the
 * one you issued from the UI or via delivery:test-submit --execute. Runs the issuer's
 * applicable steps in order: ΕΝΑΡΞΗ (RegisterTransfer) → ΕΛΕΓΧΟΣ (RequestDeliveryNoteStatus)
 * → [ΑΚΥΡΩΣΗ].
 *
 * The «ίδια μέσα» OUTCOME (ConfirmDeliveryOutcome after OUR RegisterTransfer — we are the
 * carrier; sandbox 2026-09-25) is `DeliveryLifecycleService::confirmOutcome`, not run here.
 * A third-party carrier's / the recipient's outcome and the ΕΠΙΣΤΡΟΦΗ from a state only they
 * can produce (confirmReturn from `in_transit` is [828]) need a SECOND tenant; see
 * `docs/delivery-two-party-sandbox.md`.
 *
 *   php artisan delivery:test-lifecycle <id>                       # show the plan
 *   php artisan delivery:test-lifecycle <id> --execute             # run register→status
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

    protected $description = 'Drive the ISSUER e-transport lifecycle (ΕΝΑΡΞΗ/ΕΛΕΓΧΟΣ/ΑΚΥΡΩΣΗ) on a filed δελτίο.';

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

        $this->section('Κύκλος ζωής διακίνησης (εκδότης)');
        $this->kv('Δελτίο', "{$note->invcode} (#{$note->id})");
        $this->kv('myDATA', "VALID, MARK={$note->mydata_mark}");
        $this->kv('delivery_state', (string) ($note->delivery_state ?? '—'));

        if (! $this->option('execute')) {
            $this->section('ΣΧΕΔΙΟ (dry — δεν εκτελείται)');
            $this->kv('Έναρξη', $note->delivery_state === 'registered' ? 'ΘΑ ΤΡΕΞΕΙ' : 'παράλειψη (state ≠ registered)');
            $this->kv('Έλεγχος', 'ΘΑ ΤΡΕΞΕΙ');
            $this->kv('Ακύρωση', $this->option('cancel') ? 'ΘΑ ΤΡΕΞΕΙ' : 'όχι (χωρίς --cancel)');
            $this->kv('Παράδοση/Επιστροφή', 'εκτός εκδότη — recipient/carrier (βλ. docs/delivery-two-party-sandbox.md)');
            $this->writeReport($this->option('report'));
            $this->warn('Plan μόνο. Ξανατρέξε με --execute για πραγματικές κλήσεις AADE.');

            return self::SUCCESS;
        }

        $lifecycle = new DeliveryLifecycleService($note->company);
        $ok = true;

        if ($note->fresh()->delivery_state === 'registered') {
            $ok = $this->step('ΕΝΑΡΞΗ ΔΙΑΚΙΝΗΣΗΣ (RegisterTransfer)', $note, fn () => $lifecycle->registerTransfer($note)) && $ok;
        }
        // NB: our own «ίδια μέσα» outcome is confirmOutcome (not exercised here); a third-party
        // carrier's / the recipient's outcome and a confirmReturn source state need a second
        // tenant per docs/delivery-two-party-sandbox.md.
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
