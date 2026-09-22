<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment reminder for one document (invoice or offered προτιμολόγιο):
 * planned by ReminderPlanner, sent by SendInvoiceReminder. Lifecycle:
 *
 *   awaiting_approval ─(operator «Αποστολή»)─┐
 *   queued ──────────────────────────────────┴→ sending → sent | failed
 *   awaiting_approval → skipped    (operator «Παράλειψη»)
 *   awaiting_approval | queued → cancelled   (paid / opted out / superseded before it went)
 *
 * A failed reminder can be sent again from the «Υπενθυμίσεις» page. A row
 * CANCELLED before any send attempt (`attempts` = 0) releases its `auto_stage`
 * (NULL; `stage` keeps the label), so the stage can be planned again if the
 * document qualifies again (reminders back on, an email added, a payment
 * reversed). A row that was ever attempted keeps it — it may have arrived, and
 * the customer must never get the same stage twice. A superseded row keeps it
 * too (the later stage outranks it anyway).
 */
class InvoiceReminder extends Model
{
    use BelongsToCompany;

    public const STAGE_PRE_DUE = 'pre_due';

    public const STAGE_FIRST = 'first';

    public const STAGE_SECOND = 'second';

    public const STAGE_FINAL = 'final';

    public const STAGE_MANUAL = 'manual';

    public const STATUS_AWAITING = 'awaiting_approval';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_CANCELLED = 'cancelled';

    public const KIND_INVOICE = 'invoice';

    public const KIND_PROFORMA = 'proforma';

    /** The automatic ladder, in escalation order. */
    public const AUTO_STAGES = [self::STAGE_PRE_DUE, self::STAGE_FIRST, self::STAGE_SECOND, self::STAGE_FINAL];

    /** Operator-facing labels (the panel is Greek). */
    public const STAGE_LABELS = [
        self::STAGE_PRE_DUE => 'Πριν τη λήξη',
        self::STAGE_FIRST => '1η υπενθύμιση',
        self::STAGE_SECOND => '2η υπενθύμιση',
        self::STAGE_FINAL => '3η (τελευταία)',
        self::STAGE_MANUAL => 'Χειροκίνητη',
    ];

    public const STATUS_LABELS = [
        self::STATUS_AWAITING => 'Προς έγκριση',
        self::STATUS_QUEUED => 'Στην ουρά',
        self::STATUS_SENDING => 'Αποστέλλεται',
        self::STATUS_SENT => 'Εστάλη',
        self::STATUS_FAILED => 'Απέτυχε',
        self::STATUS_SKIPPED => 'Παραλείφθηκε',
        self::STATUS_CANCELLED => 'Ακυρώθηκε',
    ];

    protected $fillable = [
        'company_id', 'invoice_id', 'customer_id', 'stage', 'auto_stage', 'document_kind',
        'due_date', 'days_overdue', 'balance', 'status', 'reason', 'recipient', 'subject',
        'error_message', 'trigger', 'triggered_by_user_id', 'sent_at', 'attempts',
    ];

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'days_overdue' => 'integer',
            'attempts' => 'integer',
            'balance' => 'decimal:2',
            'sent_at' => 'datetime',
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

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /** Can the operator (still) send it from the page? */
    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_AWAITING, self::STATUS_FAILED], true);
    }
}
