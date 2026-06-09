<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One company-backup run (Phase 4 audit log). Written by CompanyBackupRunner.
 * NOT exported with the company (operational/VM-specific — see
 * CompanyExporter::INTENTIONALLY_EXCLUDED).
 */
class CompanyBackupRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'started_at',
        'finished_at',
        'trigger',
        'bucket',
        'secrets_mode',
        'destinations',
        'bytes',
        'status',
        'message',
        'bundle_path',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'destinations' => 'array',
            'bytes' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isDownloadable(): bool
    {
        return $this->bundle_path !== null && is_file($this->bundle_path);
    }
}
