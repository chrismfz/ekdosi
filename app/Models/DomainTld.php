<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant's TLD catalogue row (Πυλώνας A / A1) — rules + routing for one TLD:
 * which registrar connection handles it, term/label limits, grace/redemption
 * windows, capability flags. Prices are the DomainTldPrice children (explicit
 * per-year, per-operation). docs/domains/README.md §3.2.
 */
class DomainTld extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'tld',
        'registrar_connection_id',
        'min_years',
        'max_years',
        'min_chars',
        'max_chars',
        'allow_idn',
        'allow_transfer',
        'grace_period_days',
        'grace_fee',
        'redemption_period_days',
        'redemption_fee',
        'dns_management',
        'email_forwarding',
        'id_protection',
        'epp_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'min_years' => 'integer',
            'max_years' => 'integer',
            'min_chars' => 'integer',
            'max_chars' => 'integer',
            'allow_idn' => 'boolean',
            'allow_transfer' => 'boolean',
            'grace_period_days' => 'integer',
            'grace_fee' => 'decimal:2',
            'redemption_period_days' => 'integer',
            'redemption_fee' => 'decimal:2',
            'dns_management' => 'boolean',
            'email_forwarding' => 'boolean',
            'id_protection' => 'boolean',
            'epp_code' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function registrarConnection(): BelongsTo
    {
        return $this->belongsTo(DomainRegistrarConnection::class, 'registrar_connection_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(DomainTldPrice::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
