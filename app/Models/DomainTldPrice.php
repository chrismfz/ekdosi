<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One price row per (TLD × operation × explicit year × currency) — Πυλώνας A,
 * docs/domains/README.md §3.3. `cost` = registrar cost (A2 sync / manual),
 * `price` = sell; `is_enabled` = the WHMCS «-1 disables this term».
 */
class DomainTldPrice extends Model
{
    use BelongsToCompany;
    use HasFactory;

    public const OPERATIONS = ['register', 'transfer', 'renewal', 'restore', 'redemption'];

    protected $fillable = [
        'company_id',
        'domain_tld_id',
        'operation',
        'years',
        'currency',
        'cost',
        'price',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'years' => 'integer',
            'cost' => 'decimal:2',
            'price' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function tld(): BelongsTo
    {
        return $this->belongsTo(DomainTld::class, 'domain_tld_id');
    }
}
