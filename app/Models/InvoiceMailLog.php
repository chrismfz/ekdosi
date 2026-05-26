<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per send ATTEMPT for an invoice (see migration docblock for
 * lifecycle). Read-mostly; the only writers are SendInvoiceEmail (job
 * lifecycle hooks) and the Mail event listeners in
 * App\Providers\AppServiceProvider that bridge Mail::MessageSending /
 * MessageSent / MessageFailed to log row updates.
 *
 * Surface on ViewInvoice as an infolist section showing the latest
 * few rows (status + recipient + timestamp + error).
 */
class InvoiceMailLog extends Model
{
    use HasFactory;

    protected $table = 'invoice_mail_log';

    protected $fillable = [
        'company_id',
        'invoice_id',
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
            'cc_list'   => 'array',
            'bcc_list'  => 'array',
            'queued_at' => 'datetime',
            'sent_at'   => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }
}
