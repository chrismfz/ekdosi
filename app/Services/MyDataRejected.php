<?php

namespace App\Services;

use RuntimeException;

/**
 * Thrown when AADE rejects an invoice submission (status != Success). Carries
 * the exact request + response XML so callers — the mydata:test-submit command,
 * a future UI panel — can surface the round-trip instead of losing it. The
 * submitter ALSO persists a forensic REJECTED `mydata_marks` row, so the
 * rejection is visible in the invoice's myDATA history; this exception is the
 * in-band copy for the throw path. Mirrors App\Services\Delivery\DeliveryNoteRejected.
 */
class MyDataRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $requestXml = '',
        public readonly string $responseXml = '',
    ) {
        parent::__construct($message);
    }
}
