<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use App\Observers\VatCategoryObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(VatCategoryObserver::class)]
class VatCategory extends Model
{
    use BelongsToCompany;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'description',
        'rate',
        'vat_exemption_category',
        'mydata_vat_category',
        'long_description',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_default' => 'boolean',
            'vat_exemption_category' => 'integer',
            'mydata_vat_category' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
