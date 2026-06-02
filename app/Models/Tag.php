<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-scoped, operator-defined label attachable to customers / suppliers /
 * products / invoices via the `taggables` morph pivot (see the HasTags trait).
 * `is_pinned` promotes the tag to a fast-filter tab on lists that use it.
 */
class Tag extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'color',
        'is_pinned',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
