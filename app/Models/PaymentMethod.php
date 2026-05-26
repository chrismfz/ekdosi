<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'legacy_id',
        'description',
        'due_days',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
