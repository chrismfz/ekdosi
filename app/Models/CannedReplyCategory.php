<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A category of canned replies (Πυλώνας E) — WHMCS «Predefined Reply» categories
 * (e.g. Invoices, Πληρωμές).
 */
class CannedReplyCategory extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'name',
        'sort',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(CannedReply::class);
    }
}
