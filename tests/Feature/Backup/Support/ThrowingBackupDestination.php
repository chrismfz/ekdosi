<?php

namespace Tests\Feature\Backup\Support;

use App\Contracts\BackupDestination;
use RuntimeException;

/** A destination that always fails its upload — to exercise the failure-alert path. */
class ThrowingBackupDestination implements BackupDestination
{
    public function key(): string
    {
        return 'boom';
    }

    public function label(): string
    {
        return 'Boom';
    }

    public function push(string $bundlePath, string $slug, array $config): string
    {
        throw new RuntimeException('boom upload failed');
    }

    public function prune(string $slug, int $keep, ?int $days, array $config): int
    {
        return 0;
    }
}
