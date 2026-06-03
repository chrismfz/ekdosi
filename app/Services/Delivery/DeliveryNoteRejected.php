<?php

namespace App\Services\Delivery;

use RuntimeException;

/**
 * Thrown when AADE rejects a delivery-note submission (status != Success).
 * Carries the exact request + response XML so callers — notably the
 * delivery sandbox commands' report writer — can record the round-trip for
 * debugging, instead of losing it (the rejection happens BEFORE any
 * delivery_marks audit row is persisted).
 */
class DeliveryNoteRejected extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $requestXml = '',
        public readonly string $responseXml = '',
    ) {
        parent::__construct($message);
    }
}
