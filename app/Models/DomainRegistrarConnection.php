<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A per-tenant registrar account (Πυλώνας A / A0) — one row per
 * (company × registrar account). `label` is the operator-facing name
 * («Openprovider MyIP», «FORTH EPP»), `is_active` the enable toggle, `mode`
 * picks sandbox/production endpoints (fail-safe: only an explicit 'production'
 * is live), `config` the ENCRYPTED per-account creds. Runtime behaviour is
 * resolved by the `registrar` key through DomainRegistrarRegistry ('manual' =
 * the API-less adapter). Mirrors PaymentGatewayConnection.
 */
class DomainRegistrarConnection extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'registrar',
        'label',
        'is_active',
        'mode',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            // Encrypted at rest: config holds registrar API / EPP credentials.
            // Never stored plaintext, never logged.
            'config' => 'encrypted:array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
