<?php

namespace App\Services\Backup\Destinations;

/**
 * Push backups to an SFTP server (league/flysystem-sftp-v3). Auth by password or
 * private key. Config (from the destinations Repeater): host, port, username,
 * password | private_key (+ key_passphrase), path (remote base dir).
 */
class SftpBackupDestination extends FlysystemBackupDestination
{
    public function key(): string
    {
        return 'sftp';
    }

    public function label(): string
    {
        return 'SFTP';
    }

    protected function diskConfig(array $config): array
    {
        return array_filter([
            'driver' => 'sftp',
            'host' => $config['host'] ?? null,
            'port' => filled($config['port'] ?? null) ? (int) $config['port'] : 22,
            'username' => $config['username'] ?? null,
            'password' => $config['password'] ?? null,
            'privateKey' => $config['private_key'] ?? null,
            'passphrase' => $config['key_passphrase'] ?? null,
            'root' => '', // per-company foldering is done by baseDir()/{slug}
            'timeout' => 30,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
