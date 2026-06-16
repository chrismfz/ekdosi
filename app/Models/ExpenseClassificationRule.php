<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An auto-classification rule (#5): «supplier ΑΦΜ (+ optional myDATA type) →
 * expense classification (E3 type + category2_x)». Applied by ExpenseClassifier
 * on import and on the «Εφαρμογή κανόνων» bulk action. Tenant-owned.
 */
class ExpenseClassificationRule extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'supplier_afm',
        'invoice_type',
        'classification_type',
        'classification_category',
        'label',
        'is_active',
        'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
