<?php

namespace App\Contracts;

use App\Models\Invoice;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;

/**
 * The "ΠΩΣ" axis of the e-invoice-provider design (docs/paroxos/
 * implementation-plan.md §2.2): a thin transport adapter per ΥΠΑΗΕΣ provider.
 *
 * It knows ONLY how to talk to one provider's API — endpoint, auth, and how to
 * read mark / authenticationCode / qrUrl / delivery out of the response. It does
 * NOT build the invoice payload (that's AadeInvoiceDocument, the "ΤΙ" axis) nor
 * touch lifecycle / mydata_marks (that's GrProviderSubmitter). Adding a provider
 * = one class implementing this + one line in config/ekdosi.php → einvoice.providers.
 *
 * The response contract is uniform across providers (mark + auth code + qr + uid),
 * so every implementation returns the same ProviderResult — see providers-survey.md.
 */
interface EInvoiceProviderTransport
{
    /** Stable provider key (e.g. 'invosign', 'sbz'); matches the registry key. */
    public function key(): string;

    /**
     * Submit a built document for filing. $documentXml is the canonical AADE
     * InvoicesDoc XML from AadeInvoiceDocument::toXml() — an AADE-passthrough
     * provider (e.g. SBZ) POSTs it as-is. The $invoice is ALSO passed so a provider
     * that needs more than the AADE core (e.g. InvoSign appends an extension block
     * built from issuer/counterpart/line data) can read it; such transports may
     * ignore $documentXml and build their own payload.
     */
    public function send(Invoice $invoice, string $documentXml, ProviderCredentials $credentials): ProviderResult;

    /** Cancel a previously-filed document by its MARK. */
    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult;

    /**
     * Re-fetch the state of a document by its INVOICE COORDINATES (issuer / series /
     * AA / issueDate / type) — NOT by MARK, because the case this exists for is
     * "send() timed out and I never got a MARK back" (§14.4). The transport reads
     * whatever coordinates it needs off the Invoice (e.g. InvoSign's
     * invoice_status.php takes issuer_vatNumber/series/aa/issueDate/invoiceType).
     * A successful result carries the MARK so GrProviderSubmitter can ADOPT it
     * instead of blindly re-filing (which would double-issue).
     */
    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult;

    /** Smoke-test credentials + reachability (the Company "Test connection" action). */
    public function ping(ProviderCredentials $credentials): bool;
}
