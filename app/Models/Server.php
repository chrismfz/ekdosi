<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single provisioning target (cPanel/Mailcow/DirectAdmin/license/antivirus
 * host) that ekdosi can eventually reach DIRECTLY with its own credentials — no
 * WHMCS in between. Per-server creds; fall back to the group's when blank.
 * `secret_encrypted` encrypted at rest. Schema-only placeholder for now.
 */
class Server extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'server_group_id',
        'name',
        'module',
        'hostname',
        'api_endpoint',
        'username',
        'secret_encrypted',
        'meta',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'secret_encrypted' => 'encrypted',
            'meta' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function serverGroup(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class);
    }

    /** Effective module: this server's own, else the group's default. */
    public function effectiveModule(): ?string
    {
        return $this->module ?: $this->serverGroup?->module;
    }
}
