<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound quote-email send log — twin of InvoiceMailLog. One row per attempt.
 */
class QuoteMailLog extends Model
{
    use BelongsToCompany;
    use HasFactory;

    protected $fillable = [
        'company_id',
        'quote_id',
        'recipient',
        'cc_list',
        'bcc_list',
        'from_address',
        'subject',
        'trigger',
        'status',
        'error_message',
        'queued_at',
        'sent_at',
        'failed_at',
        'triggered_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'cc_list' => 'array',
            'bcc_list' => 'array',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
