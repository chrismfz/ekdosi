<?php

namespace App\Services\Pos;

use App\Actions\PosSaleNotIssued;
use App\Models\Invoice;
use App\Services\EInvoiceSubmitterFactory;
use App\Services\InvoiceNumberer;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Issue a «Ταμείο» draft — a sale (11.1) or a return credit note (11.4) — the ONE way:
 * a filing tenant files it (the e-invoice submitter assigns the ΑΑ, files, sets
 * MARK/QR and flips it active — which moves the stock); a non-filing tenant gets the
 * same atomic number + activate as «Οριστικοποίηση». A failure leaves the DRAFT and
 * throws PosSaleNotIssued with its id, so the till never re-rings it as a new document.
 */
class PosIssuer
{
    public function __construct(private readonly EInvoiceSubmitterFactory $submitters) {}

    public function issue(Invoice $invoice): void
    {
        try {
            if ($invoice->isIssuedAtFinalize()) {
                DB::transaction(function () use ($invoice): void {
                    if ($invoice->code === null) {
                        app(InvoiceNumberer::class)->assign($invoice);
                    }
                    $invoice->update(['local_status' => 'active']);
                });
            } else {
                $this->submitters->for($invoice->company)->submit($invoice);
            }
        } catch (Throwable $e) {
            // A step AFTER the filing committed (auto-email queueing…) may throw on
            // an invoice that IS issued — that's issued, not a failure.
            if ($invoice->refresh()->local_status === 'active') {
                report($e);

                return;
            }
            throw new PosSaleNotIssued((int) $invoice->getKey(), $e);
        }

        // «Nothing threw» isn't «issued»: a submitter that returned without
        // promoting the draft must not print as issued.
        if ($invoice->refresh()->local_status !== 'active') {
            throw new PosSaleNotIssued((int) $invoice->getKey(), new RuntimeException('το παραστατικό δεν οριστικοποιήθηκε'));
        }
    }
}
