<?php

namespace App\Support\Payments;

/**
 * Outcome of a gateway «Test connection» probe (creds + reachability). A gateway
 * with no remote to reach (e.g. the manual/offline one) returns ok immediately.
 */
final readonly class ConnectionTestResult
{
    public function __construct(
        public bool $ok,
        public string $message,
    ) {}

    public static function ok(string $message = 'OK'): self
    {
        return new self(true, $message);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
