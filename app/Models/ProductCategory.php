<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductCategory extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'description_short',
        'description',
        'markup',
        // MYD-5: optional per-category myDATA E3 income classification override
        // (a mixed goods+services invoice files each line under its category's
        // class; unset → falls back to the invoice type's default).
        'mydata_income_class',
        'mydata_income_class_category',
    ];

    protected function casts(): array
    {
        return [
            'markup' => 'decimal:2',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
