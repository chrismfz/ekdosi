<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per send ATTEMPT for an invoice (see migration docblock for
 * lifecycle). Read-mostly. The ONLY writer is the SendInvoiceEmail
 * job: handle() creates the row, transitions queued → sending → sent
 * / failed inline as it works through the send pipeline; failed()
 * reconciles the row to 'failed' on terminal exhaustion of retries.
 * No external Mail event listeners feed this table (the Laravel
 * Mailer events fire AFTER our row is already in its terminal state,
 * so they'd be redundant — and would be wrong, since they'd write
 * to the FAILED log row a 'sent' status if the post-send hook fires
 * before the worker realises the SMTP transport rejected).
 *
 * Surface on ViewInvoice via MailLogRelationManager showing the
 * latest rows (status + recipient + timestamp + error).
 */
class InvoiceMailLog extends Model
{
    use BelongsToCompany;

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
        'send_key',
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
