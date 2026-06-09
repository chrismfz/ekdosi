<?php

namespace App\Services\Backup;

use App\Contracts\BackupDestination;
use InvalidArgumentException;

/**
 * Resolves a BackupDestination by key from `config/ekdosi.php → backup.destinations`
 * (key → class), mirroring EInvoiceSubmitterFactory / BillingSourceRegistry. New
 * transports (SFTP/FTP/S3 — Slice 4b) drop in via config; no call-site change.
 */
class BackupDestinationRegistry
{
    public function for(string $key): BackupDestination
    {
        $map = (array) config('ekdosi.backup.destinations', []);
        $class = $map[$key] ?? null;

        if ($class === null || ! is_a($class, BackupDestination::class, true)) {
            throw new InvalidArgumentException("Unknown backup destination driver: {$key}");
        }

        return app($class);
    }

    /** @return list<string> the configured driver keys */
    public function available(): array
    {
        return array_keys((array) config('ekdosi.backup.destinations', []));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, (array) config('ekdosi.backup.destinations', []));
    }
}
