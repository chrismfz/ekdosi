<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-company automated-backup policy (Phase 4). The passphrase is encrypted at
 * rest. `destinations` is a list of `{driver, config}` resolved by
 * BackupDestinationRegistry. NOT exported with the company — backup config is
 * VM-specific (see CompanyExporter::INTENTIONALLY_EXCLUDED).
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

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'passphrase' => 'encrypted',
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
     *
     * @return list<array{driver:string, config?:array<string,mixed>}>
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
}
