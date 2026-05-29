<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DeliveryMethod extends Model
{
    use BelongsToCompany;

    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'description',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
