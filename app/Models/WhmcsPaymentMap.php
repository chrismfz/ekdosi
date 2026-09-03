<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's «ποιος τρόπος πληρωμής» declaration for a WHMCS gateway → the ekdosi
 * {@see PaymentMethod} a WHMCS invoice on that gateway is issued with (which in
 * turn carries the §8.12 payment type + due-days). Resolved by
 * {@see App\Services\WhmcsInbox\WhmcsPaymentMethodResolver}; unmapped gateways fall
 * back to the invoice type default.
 */
class WhmcsPaymentMap extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'whmcs_gateway',
        'payment_method_id',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'payment_method_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /** Canonical form of a WHMCS gateway key (trim + lower-case) — the stored + lookup form. */
    public static function normaliseGateway(?string $gateway): string
    {
        return mb_strtolower(trim((string) $gateway), 'UTF-8');
    }
}
