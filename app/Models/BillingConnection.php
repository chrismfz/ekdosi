<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 0 (Bridges/Connectors — docs/bridges-connectors.md): one row per
 * (company × connected billing system). A tenant can have several (WHMCS + a
 * WooCommerce shop, two shops, …); each is independently toggleable via
 * `is_active` (the future Company «Γέφυρες» tab on/off switch).
 *
 * This is the multi-source REGISTRY. The actual per-source credentials still
 * live where they do today (WHMCS → `companies.whmcs_*`); Phase 1 moves them
 * into `config`. The matching runtime behaviour is resolved by
 * App\Services\Billing\BillingSourceRegistry from the `source` key.
 */
class BillingConnection extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    public const SOURCE_WHMCS = 'whmcs';

    protected $fillable = [
        'company_id',
        'source',
        'label',
        'is_active',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Idempotently ensure a connection row exists for (company, source). Used by
     * the seeder and (later) when an operator configures a source. Does not
     * touch an existing row's label/is_active/config.
     */
    public static function ensureFor(Company $company, string $source, ?string $label = null): self
    {
        return static::query()->firstOrCreate(
            ['company_id' => $company->id, 'source' => $source],
            ['label' => $label, 'is_active' => true],
        );
    }
}
