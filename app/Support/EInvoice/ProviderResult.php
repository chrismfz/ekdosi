<?php

namespace App\Support\EInvoice;

/**
 * Uniform outcome of a provider transport op (send/cancel/status). The provider
 * survey (docs/paroxos/research/providers-survey.md) found the response contract
 * is the SAME across providers — mark + authenticationCode/signature + uid + qr —
 * so one DTO serves all of them. GrProviderSubmitter (P2) maps this onto the
 * mydata_marks row + the invoice mirror columns.
 *
 * Immutable. Build via ok()/failed() rather than the constructor at call sites.
 */
final class ProviderResult
{
    /**
     * @param  list<string>  $errors  Human-readable provider errors ("[code] message").
     * @param  string|null  $raw  Verbatim provider response (for the audit row).
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?string $mark = null,
        public readonly ?string $uid = null,
        public readonly ?string $authenticationCode = null,
        public readonly ?string $qrUrl = null,
        public readonly ?string $cancellationMark = null,
        public readonly ?string $deliveryState = null,
        public readonly array $errors = [],
        public readonly ?string $raw = null,
        /** The exact payload the transport SENT (e.g. InvoSign's augmented xml_arxeio) — stored as the mark's request for debugging. */
        public readonly ?string $requestPayload = null,
        /** PROV-009: the provider account's REMAINING QUOTA after this filing (InvoSign `remaining_invoices`); null when the provider doesn't report it. */
        public readonly ?int $remainingInvoices = null,
        /** PROV-009: recipient email(s) the provider notified for this document (InvoSign `receptionEmails`); null/empty when none. */
        public readonly ?string $receptionEmails = null,
    ) {}

    public static function ok(
        ?string $mark = null,
        ?string $uid = null,
        ?string $authenticationCode = null,
        ?string $qrUrl = null,
        ?string $cancellationMark = null,
        ?string $deliveryState = null,
        ?string $raw = null,
        ?string $requestPayload = null,
        ?int $remainingInvoices = null,
        ?string $receptionEmails = null,
    ): self {
        return new self(
            success: true,
            mark: $mark,
            uid: $uid,
            authenticationCode: $authenticationCode,
            qrUrl: $qrUrl,
            cancellationMark: $cancellationMark,
            deliveryState: $deliveryState,
            raw: $raw,
            requestPayload: $requestPayload,
            remainingInvoices: $remainingInvoices,
            receptionEmails: $receptionEmails,
        );
    }

    /**
     * @param  list<string>  $errors
     */
    public static function failed(array $errors = [], ?string $raw = null, ?string $requestPayload = null): self
    {
        return new self(success: false, errors: $errors, raw: $raw, requestPayload: $requestPayload);
    }

    /** Joined error string for logs / exception messages. */
    public function errorMessage(): string
    {
        return implode('; ', $this->errors) ?: 'unknown provider error';
    }
}
