<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A canned (predefined) reply (Πυλώνας E). `body` may carry template tokens
 * (e.g. {{customer.name}}, {{company.iban}}) expanded when inserted into a reply
 * — the token engine lands with the operator UI (Phase 1b).
 */
class CannedReply extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'canned_reply_category_id',
        'title',
        'body',
        'sort',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CannedReplyCategory::class, 'canned_reply_category_id');
    }
}
