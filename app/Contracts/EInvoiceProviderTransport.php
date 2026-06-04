<?php

namespace App\Contracts;

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
     * Submit a built document for filing. $documentXml is whatever the provider
     * accepts — usually the AADE InvoicesDoc XML from AadeInvoiceDocument::toXml()
     * (SBZ passthrough), optionally wrapped with a provider extension (InvoSign).
     */
    public function send(string $documentXml, ProviderCredentials $credentials): ProviderResult;

    /** Cancel a previously-filed document by its MARK. */
    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult;

    /**
     * Re-fetch the state of a document (by MARK) — the idempotency guard for the
     * ambiguous "timeout after send" case (§14.4): query before any retry so a
     * filing that actually succeeded is adopted, not duplicated.
     */
    public function status(string $mark, ProviderCredentials $credentials): ProviderResult;

    /** Smoke-test credentials + reachability (the Company "Test connection" action). */
    public function ping(ProviderCredentials $credentials): bool;
}
