<?php

namespace App\Models;

use App\Enums\LeadActivityType;
use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lead — υποψήφιος πελάτης (mini-CRM, pre-customer). Lives until it is
 * converted; after that the Customer is the truth and the lead stays as the
 * read-only «how did they come to us» history, linked via
 * `converted_customer_id` (the customer side: Customer::originLead()).
 *
 * Only `name` is required — a lead is whoever we found, with whatever we know.
 * No money semantics whatsoever: never touches invoices / balances / myDATA.
 *
 * `last_activity_at` is a CACHE maintained by LeadActivityObserver (not
 * fillable, never logged). Every status change auto-appends a
 * `status_change` row to the timeline (see booted()) so the timeline is the
 * complete story, not only the manual contacts.
 */
class Lead extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasInternalNotes;
    use HasTags;
    use SoftDeletes;
    use TracksActivity;

    /** Mirror the DB default so an in-process instance is never status-less. */
    protected $attributes = [
        'status' => 'new',
    ];

    protected $fillable = [
        'company_id',
        'name',
        'contact_person',
        'phone',
        'mobile',
        'email',
        'website',
        'afm',
        'address1',
        'city',
        'postcode',
        'country',
        'occupation',
        'source',
        'referred_by_customer_id',
        'status',
        'lost_reason',
        'assigned_user_id',
        'next_action_at',
        'converted_customer_id',
        'converted_at',
        'notes',
    ];

    /**
     * Audited business columns — never the `last_activity_at` cache.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'name', 'contact_person', 'phone', 'mobile', 'email', 'website', 'afm',
            'address1', 'city', 'postcode', 'country', 'occupation', 'source',
            'referred_by_customer_id', 'status', 'lost_reason', 'assigned_user_id',
            'next_action_at', 'converted_customer_id', 'notes',
        ];
    }

    protected function casts(): array
    {
        return [
            'status' => LeadStatus::class,
            'source' => LeadSource::class,
            'next_action_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every status change becomes a timeline row (who / when / from → to,
        // with the lost reason as the body). Runs inside `updated`, where
        // getRawOriginal() still holds the pre-save value.
        static::updated(function (self $lead): void {
            if (! $lead->wasChanged('status')) {
                return;
            }

            $from = $lead->getRawOriginal('status');
            $to = $lead->status instanceof LeadStatus ? $lead->status->value : (string) $lead->status;

            $lead->timeline()->create([
                'company_id' => $lead->company_id,
                'user_id' => auth()->id(),
                'type' => LeadActivityType::StatusChange->value,
                'happened_at' => now(),
                'body' => $lead->status?->requiresReason() ? $lead->lost_reason : null,
                'meta' => ['from' => $from, 'to' => $to],
            ]);
        });
    }

    // ── Relations ──────────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referred_by_customer_id');
    }

    /** The customer this lead became (null until converted). */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'converted_customer_id');
    }

    /** Προσφορές issued to this lead before (or without) conversion. */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * A quote was issued to this lead: log it on the timeline and, if the lead
     * is still early in the funnel, move it to «Στάλθηκε προσφορά».
     */
    public function recordQuote(Quote $quote): void
    {
        $this->timeline()->create([
            'company_id' => $this->company_id,
            'user_id' => auth()->id(),
            'type' => LeadActivityType::Quote->value,
            'happened_at' => now(),
            'body' => 'Προσφορά '.($quote->code ?? '#'.$quote->id).($quote->subject ? ' — '.$quote->subject : ''),
            'meta' => ['quote_id' => $quote->id],
        ]);

        if (in_array($this->status, [LeadStatus::New, LeadStatus::Contacted, LeadStatus::Interested], true)) {
            $this->update(['status' => LeadStatus::Quoted]);
        }
    }

    /**
     * Χρονολόγιο — newest first. Named `timeline` (not `activities`) so it can
     * never collide with spatie/activitylog's subject relation.
     */
    public function timeline(): HasMany
    {
        return $this->hasMany(LeadActivity::class)
            ->orderByDesc('happened_at')
            ->orderByDesc('id');
    }

    // ── State ──────────────────────────────────────────────────────────────

    public function isConverted(): bool
    {
        return $this->converted_customer_id !== null;
    }

    public function isOpen(): bool
    {
        return $this->status instanceof LeadStatus && $this->status->isOpen();
    }

    /** Open + the next action date has passed. */
    public function isOverdue(): bool
    {
        return $this->isOpen()
            && $this->next_action_at !== null
            && $this->next_action_at->isPast();
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', LeadStatus::openValues());
    }

    /** Open leads whose `next_action_at` is in the past. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()
            ->whereNotNull('next_action_at')
            ->where('next_action_at', '<', now());
    }

    /** Open leads with no timeline row (or creation) in the last N days. */
    public function scopeStale(Builder $query, int $days = 14): Builder
    {
        $cutoff = now()->subDays($days);

        return $query->open()
            ->where(function (Builder $q) use ($cutoff): void {
                $q->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function (Builder $q2) use ($cutoff): void {
                        $q2->whereNull('last_activity_at')->where('created_at', '<', $cutoff);
                    });
            });
    }
}
