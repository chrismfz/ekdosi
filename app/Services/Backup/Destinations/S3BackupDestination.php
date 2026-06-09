<?php

namespace App\Services\Backup\Destinations;

/**
 * Push backups to S3 / S3-compatible storage (league/flysystem-aws-s3-v3) —
 * AWS, Backblaze B2, MinIO, Wasabi, DigitalOcean Spaces, etc. Config: key,
 * secret, region, bucket, endpoint (optional, for compatibles),
 * path_style (optional, for MinIO et al.), path (key prefix).
 */
class S3BackupDestination extends FlysystemBackupDestination
{
    public function key(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return 'S3 / συμβατό (B2, MinIO, Spaces…)';
    }

    protected function diskConfig(array $config): array
    {
        return array_filter([
            'driver' => 's3',
            'key' => $config['key'] ?? null,
            'secret' => $config['secret'] ?? null,
            'region' => $config['region'] ?? 'us-east-1',
            'bucket' => $config['bucket'] ?? null,
            'endpoint' => $config['endpoint'] ?? null,
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
            'throw' => true,
        ], static fn ($v) => $v !== null && $v !== '');
    }
}
