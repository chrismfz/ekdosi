<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per Messages API turn — the AI «Βοηθός» billing + cap source of truth.
 * Token counts come straight from the response `usage` block (authoritative);
 * `cost_estimate` is our own calculation (AiPricing), reconciled to the single
 * monthly Anthropic invoice. Append-only; created_at only (no updated_at).
 */
class AiUsageLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage_log';

    protected $fillable = [
        'company_id',
        'user_id',
        'conversation_id',
        'model',
        'input_tokens',
        'output_tokens',
        'cache_read_tokens',
        'cache_write_tokens',
        'cost_estimate',
    ];

    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_write_tokens' => 'integer',
            'cost_estimate' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
