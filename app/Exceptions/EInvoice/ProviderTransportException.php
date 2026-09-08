<?php

namespace App\Exceptions\EInvoice;

use RuntimeException;
use Throwable;

/**
 * A transport-LEVEL failure talking to an e-invoice provider (timeout / connection
 * refused / HTTP non-2xx) — distinct from a provider business rejection (which comes
 * back as a ProviderResult with success=false). Carries the EXACT payload we tried
 * to send so GrProviderSubmitter can record a forensic PROVIDER_FAILED row: on an
 * ambiguous transport failure the document MAY already have filed at the provider,
 * so "what we attempted to send" is what lets an operator find/cancel it manually.
 */
class ProviderTransportException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $attemptedPayload = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
