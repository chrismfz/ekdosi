<?php

namespace App\Contracts;

/**
 * One transport a company backup bundle can be pushed to (Phase 4). Pluggable —
 * resolved by BackupDestinationRegistry from `config/ekdosi.php → backup.destinations`,
 * mirroring EInvoiceSubmitterFactory / BillingSourceRegistry. v1 driver: Local
 * (a Laravel disk, the Download source). SFTP / FTP / S3 land in Slice 4b.
 */
interface BackupDestination
{
    /** Stable key used in the destinations list + run log (e.g. 'local', 'sftp'). */
    public function key(): string;

    /** Greek label for the UI. */
    public function label(): string;

    /**
     * Push the already-built bundle file to this destination.
     *
     * @param  string  $bundlePath  Absolute path to the local .zip artifact.
     * @param  string  $slug  Company slug (for per-tenant foldering).
     * @param  array<string,mixed>  $config  Driver config from the destination entry.
     * @return string A human-readable stored location (path / uri) for the run log.
     */
    public function push(string $bundlePath, string $slug, array $config): string;

    /**
     * Drop artifacts beyond the retention policy at this destination.
     *
     * @param  int  $keep  Keep the newest N bundles (0 = unlimited).
     * @param  int|null  $days  Also drop anything older than N days.
     * @param  array<string,mixed>  $config
     * @return int Number of artifacts removed.
     */
    public function prune(string $slug, int $keep, ?int $days, array $config): int;
}
