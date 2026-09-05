<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A per-tenant payment method (Πυλώνας B / B0) — one row per (company × gateway).
 * The WHMCS-style «Τρόποι πληρωμής» list: `label` is the customer-facing name,
 * `is_active` the enable toggle, `sort` the order, `config` the ENCRYPTED
 * per-connection settings/creds. Runtime behaviour is resolved by the `gateway`
 * key through PaymentGatewayRegistry. Mirrors BillingConnection.
 */
class PaymentGatewayConnection extends Model
{
    use BelongsToCompany;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'gateway',
        'label',
        'is_active',
        'sort',
        'config',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            // Encrypted at rest: config holds API secrets / webhook secrets and
            // display settings. Never stored plaintext, never logged.
            'config' => 'encrypted:array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
