<?php

namespace App\Services\Backup\Destinations;

/**
 * Push backups to an FTP / FTPS server (league/flysystem-ftp). Config: host,
 * port, username, password, ssl (FTPS), passive, path (remote base dir).
 */
class FtpBackupDestination extends FlysystemBackupDestination
{
    public function key(): string
    {
        return 'ftp';
    }

    public function label(): string
    {
        return 'FTP / FTPS';
    }

    protected function diskConfig(array $config): array
    {
        return array_filter([
            'driver' => 'ftp',
            'host' => $config['host'] ?? null,
            'port' => (int) ($config['port'] ?? 21),
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'ssl' => (bool) ($config['ssl'] ?? false),
            'passive' => (bool) ($config['passive'] ?? true),
            'root' => '',
            'timeout' => 30,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
