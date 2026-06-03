<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A reseller-style group of provisioning targets sharing credentials (NATIVE,
 * WHMCS-independent). `secret_encrypted` is encrypted at rest (Laravel cast,
 * like the myDATA/GSIS creds on companies). Schema-only placeholder — no live
 * API in this phase.
 */
class ServerGroup extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'module',
        'username',
        'secret_encrypted',
        'meta',
        'notes',
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

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }
}
