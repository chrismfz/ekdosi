<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Services\WhmcsInbox\WhmcsIncomeClassifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tenant's «τι είναι» declaration for a WHMCS product group (or a single
 * product) → the §8.6 income bucket its invoice lines file under. See the
 * create-table migration for the group-first rationale; resolved
 * product→group→fallback by {@see WhmcsIncomeClassifier}.
 */
class WhmcsIncomeMap extends Model
{
    use BelongsToCompany;

    public const SCOPE_GROUP = 'group';

    public const SCOPE_PRODUCT = 'product';

    protected $fillable = [
        'company_id',
        'scope',
        'whmcs_key',
        'income_class_category',
        'income_class',
        // Revenue-by-category (#2): the ekdosi business ProductCategory this WHMCS
        // group/product's lines belong to — the reporting axis, independent of the
        // §8.6 income class above.
        'product_category_id',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'whmcs_key' => 'integer',
            'product_category_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
