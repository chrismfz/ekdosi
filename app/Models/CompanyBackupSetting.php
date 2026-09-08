<?php

namespace App\Models;

use App\Casts\MaybeEncrypted;
use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company automated-backup policy (Phase 4). The passphrase is encrypted at
 * rest. `destinations` is a list of FLAT entries (`{driver, ...config}`: host,
 * port, bucket, path…) resolved by BackupDestinationRegistry. NOT exported with
 * the company — backup config is VM-specific (see
 * CompanyExporter::INTENTIONALLY_EXCLUDED).
 */
class CompanyBackupSetting extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'enabled',
        'frequency',
        'run_at_time',
        'bucket',
        'secrets_mode',
        'passphrase',
        'retention_keep',
        'retention_days',
        'destinations',
    ];

    /** Keep the backup passphrase out of array/JSON serialization. */
    protected $hidden = ['passphrase'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'passphrase' => MaybeEncrypted::class,
            'retention_keep' => 'integer',
            'retention_days' => 'integer',
            'destinations' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The configured destinations with `local` GUARANTEED present (the Download
     * source + retention target) — the single home of the "local always" rule.
     * Each entry is flat: a `driver` key plus that driver's config (host, port,
     * bucket, path…), passed as-is to the BackupDestination.
     *
     * @return list<array{driver:string}&array<string,mixed>>
     */
    public function destinationList(): array
    {
        $list = array_values(array_filter(
            (array) $this->destinations,
            static fn ($d) => is_array($d) && ! empty($d['driver']),
        ));

        if (! in_array('local', array_column($list, 'driver'), true)) {
            array_unshift($list, ['driver' => 'local']);
        }

        return $list;
    }

    public function wantsFull(): bool
    {
        return $this->bucket === 'full';
    }

    public function hasRemoteDestination(): bool
    {
        foreach ($this->destinationList() as $d) {
            if (($d['driver'] ?? 'local') !== 'local') {
                return true;
            }
        }

        return false;
    }

    /**
     * Is a backup due now, given the timestamp of the LAST run (any status)?
     * Hourly cron → fires once per period at/after run_at_time. `$lastRunAt` is
     * the last ATTEMPT regardless of ok/failed: a failed attempt still counts as
     * "ran this period" so a misconfigured tenant is NOT re-dumped every hour —
     * the operator sees the failed run and fixes it / re-runs manually.
     */
    public function isDue(?CarbonInterface $lastRunAt, CarbonInterface $now): bool
    {
        if (! in_array($this->frequency, ['daily', 'weekly', 'monthly'], true)) {
            return false; // 'off' / unknown → never scheduled
        }

        [$h, $m] = array_pad(explode(':', $this->run_at_time ?: '02:00'), 2, '0');
        if ($now->lt($now->copy()->setTime((int) $h, (int) $m, 0))) {
            return false; // not yet at today's configured run time
        }

        if ($lastRunAt === null) {
            return true; // never attempted
        }

        return match ($this->frequency) {
            'daily' => $lastRunAt->lt($now->copy()->startOfDay()),
            'weekly' => $lastRunAt->lt($now->copy()->startOfWeek()),
            'monthly' => $lastRunAt->lt($now->copy()->startOfMonth()),
            default => false,
        };
    }
}
