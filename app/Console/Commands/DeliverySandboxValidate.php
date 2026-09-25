<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\WritesDeliveryReport;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\InvoiceType;
use App\Services\Delivery\DeliveryLifecycleService;
use App\Services\Delivery\DeliveryNoteSubmitter;
use App\Services\InvoiceNumberer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * End-to-end sandbox validation of the whole Δελτίο Αποστολής feature against
 * the tenant's configured myDATA environment. Creates a throwaway TEST δελτίο,
 * then runs the ISSUER chain — ΕΚΔΟΣΗ (SendInvoices) → ΕΝΑΡΞΗ (RegisterTransfer)
 * → ΕΛΕΓΧΟΣ (RequestDeliveryNoteStatus) → [ΑΚΥΡΩΣΗ] — and writes a .txt report
 * (every step + the request/response XML). The «ίδια μέσα» outcome (confirmOutcome, we
 * as carrier) is not part of this harness; a third-party carrier's / the recipient's
 * outcome needs a second tenant — see docs/delivery-two-party-sandbox.md.
 *
 * Dry-run by default (builds the XML, NO AADE call). Pass --execute to actually
 * file at AADE (the tenant must be in sandbox mode with dev credentials and the
 * host must reach the AADE dev endpoint).
 *
 *   php artisan delivery:sandbox-validate --tenant=myip                     # dry-run
 *   php artisan delivery:sandbox-validate --tenant=myip --execute           # real round-trip
 *   php artisan delivery:sandbox-validate --tenant=myip --execute --cancel  # + cancel at the end
 */
class DeliverySandboxValidate extends Command
{
    use WritesDeliveryReport;

    protected $signature = 'delivery:sandbox-validate
        {--tenant= : Company slug (default: first gr-mydata tenant)}
        {--execute : Actually POST to AADE (default: dry-run, build XML only)}
        {--cancel : Also cancel the δελτίο at the end of the chain}
        {--report= : Report file path under storage/app (default: delivery-sandbox-<ts>.txt)}';

    protected $description = 'End-to-end sandbox validation of the Δελτίο Αποστολής feature; writes a .txt report.';

    public function handle(): int
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');

        $this->section('ΨΗΦΙΑΚΗ ΔΙΑΚΙΝΗΣΗ — Sandbox validation');
        $this->kv('Tenant', "{$tenant->name} (#{$tenant->id}, ΑΦΜ {$tenant->afm})");
        $this->kv('myDATA mode', (string) $tenant->mydata_mode);
        $this->kv('Λειτουργία', $execute ? 'EXECUTE — πραγματικές κλήσεις AADE' : 'DRY-RUN — μόνο XML, καμία κλήση');
        $this->kv('Ημ/νία', now()->toDateTimeString());

        $type = InvoiceType::query()
            ->where('company_id', $tenant->id)
            ->where('mydata_type', 'like', '9%')
            ->orderBy('id')
            ->first();
        if (! $type) {
            $this->error('Ο tenant δεν έχει τύπο διακίνησης 9.x (π.χ. ΔΑΠ/9.3). Τρέξε τον seeder τύπων ή φτιάξε έναν στο Setup → Invoice Types.');

            return self::FAILURE;
        }
        $this->kv('Τύπος', "{$type->code} / {$type->mydata_type}");

        $note = $this->createTestNote($tenant, $type);
        $this->kv('Δοκιμαστικό δελτίο', "{$note->invcode} (#{$note->id})");

        $submitter = new DeliveryNoteSubmitter($tenant);

        if (! $execute) {
            $this->section('DRY-RUN — XML έκδοσης (δεν στάλθηκε)');
            $this->logLine($submitter->previewXml($note));
            $path = $this->writeReport($this->option('report'));
            $this->newLine();
            $this->warn('Dry-run μόνο. Ξανατρέξε με --execute (σε host με dev creds + πρόσβαση AADE) για πραγματικό round-trip.');
            $this->info("Report: {$path}");

            return self::SUCCESS;
        }

        $lifecycle = new DeliveryLifecycleService($tenant);
        $ok = true;

        $ok = $this->step('ΕΚΔΟΣΗ (SendInvoices)', $note, fn () => $submitter->submit($note)) && $ok;

        if ($note->fresh()->mydata_state === 'VALID') {
            $ok = $this->step('ΕΝΑΡΞΗ ΔΙΑΚΙΝΗΣΗΣ (RegisterTransfer)', $note, fn () => $lifecycle->registerTransfer($note)) && $ok;
            // The outcome isn't driven here (our «ίδια μέσα» one = confirmOutcome; a third
            // party's needs a second tenant — docs/delivery-two-party-sandbox.md).
            $ok = $this->step('ΕΛΕΓΧΟΣ ΚΑΤΑΣΤΑΣΗΣ (RequestDeliveryNoteStatus)', $note, fn () => $lifecycle->refreshStatus($note)) && $ok;
            if ($this->option('cancel')) {
                $ok = $this->step('ΑΚΥΡΩΣΗ (CancelInvoice)', $note, fn () => $lifecycle->cancel($note, 'sandbox validation')) && $ok;
            }
        } else {
            $this->kv('Διακοπή', 'Η έκδοση δεν επέστρεψε VALID — ο κύκλος ζωής παραλείφθηκε.');
        }

        $this->appendLifecycleHistory($note->fresh());
        $this->appendMarkXml($note->fresh());

        $this->section('ΣΥΝΟΨΗ');
        $this->kv('ΑΠΟΤΕΛΕΣΜΑ', $ok ? 'PASS ✓ — όλα τα βήματα πέρασαν' : 'FAIL ✗ — δες τα σφάλματα παραπάνω');

        $path = $this->writeReport($this->option('report'));
        $this->newLine();
        $this->info("Report: {$path}");

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function resolveTenant(): ?Company
    {
        $slug = $this->option('tenant');
        $tenant = $slug
            ? Company::where('slug', $slug)->first()
            : Company::where('einvoice_provider', 'gr-mydata')->orderBy('id')->first();

        if (! $tenant) {
            $this->error($slug ? "Δεν βρέθηκε tenant με slug '{$slug}'." : 'Δεν βρέθηκε gr-mydata tenant.');
        }

        return $tenant;
    }

    private function createTestNote(Company $tenant, InvoiceType $type): DeliveryNote
    {
        return DB::transaction(function () use ($tenant, $type) {
            // Δελτίο Αποστολής → 9.x type; opt out of the monetary 9.x guard (MYD-003).
            $alloc = app(InvoiceNumberer::class)->allocate($tenant, $type->code, allowMovementType: true);

            $note = DeliveryNote::create([
                'company_id' => $tenant->id,
                'delivery_type_id' => $type->id,
                'invcode' => $alloc->invcode,
                'code' => $alloc->code,
                'issued_at' => now(),
                'mydata_type' => $type->mydata_type,
                'move_purpose' => 8, // Ενδοδιακίνηση — no real counterpart needed
                'local_status' => 'draft',
                'vehicle_number' => 'ΔΟΚ1234',
                'transport_type' => 6, // Λοιπά μεταφορικά μέσα
                'carrier_afm' => $tenant->afm,
                'loading_street' => $tenant->address ?: 'Έδρα',
                'loading_postcode' => $tenant->postcode ?: '10431',
                'loading_city' => $tenant->city ?: 'Αθήνα',
                'delivery_street' => 'Αποθήκη δοκιμής',
                'delivery_postcode' => $tenant->postcode ?: '10431',
                'delivery_city' => $tenant->city ?: 'Αθήνα',
                'notes' => 'SANDBOX VALIDATION — δοκιμαστικό δελτίο (διαγράψτε ελεύθερα)',
            ]);

            $note->lines()->create([
                'company_id' => $tenant->id,
                'qty' => 1,
                'measurement_unit' => 1, // τεμάχια
                'product_descr' => 'Δοκιμαστικό είδος (sandbox)',
            ]);

            return $note->fresh('lines');
        });
    }
}
