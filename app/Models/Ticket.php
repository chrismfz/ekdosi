<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A support ticket (Πυλώνας E). Belongs to a department and — when the requester
 * matches a registered account — a Customer (null = GUEST, with `requester_email`
 * always kept). Status/priority are enums; the status is driven by who posts
 * (App\Actions\Support\PostTicketMessage), never typed by hand. Attachments +
 * tags reuse the existing polymorphic morphs; «Ιστορικό» via TracksActivity.
 */
class Ticket extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory, HasTags, SoftDeletes, TracksActivity;

    protected $fillable = [
        'company_id',
        'reference',
        'ticket_department_id',
        'customer_id',
        'requester_email',
        'requester_name',
        'subject',
        'status',
        'priority',
        'assigned_to',
        'opened_via',
        'last_reply_at',
        'last_reply_role',
        'closed_at',
        'rating',
        'rating_comment',
        'rated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
            'rating' => 'integer',
            'rated_at' => 'datetime',
        ];
    }

    /**
     * Audited business columns (never a cache column). See TracksActivity.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return ['status', 'priority', 'ticket_department_id', 'assigned_to', 'customer_id', 'subject'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'ticket_department_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The operator this ticket is assigned to (nullable). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('id');
    }

    /** Watchers / CC for this ticket (operators AND external emails). */
    public function watchers(): HasMany
    {
        return $this->hasMany(TicketWatcher::class);
    }

    /**
     * Add (idempotently) an operator watcher. Returns the row (existing or new).
     * `source` is only set on creation — a manual watch never gets downgraded to
     * a participant one by a later reply.
     */
    public function watch(User $user, string $source = TicketWatcher::SOURCE_MANUAL): TicketWatcher
    {
        return $this->watchers()->firstOrCreate(
            ['user_id' => $user->id],
            ['company_id' => $this->company_id, 'source' => $source],
        );
    }

    public function unwatch(User $user): void
    {
        $this->watchers()->where('user_id', $user->id)->delete();
    }

    public function isWatchedBy(User $user): bool
    {
        return $this->watchers()->where('user_id', $user->id)->exists();
    }

    /**
     * Add (idempotently) an external email watcher (Bcc'd on outbound replies).
     * Blank emails are ignored; the address is stored lowercased so the unique
     * index and the Cc de-dup are case-insensitive. Returns null for a blank.
     */
    public function addEmailWatcher(?string $email, string $source = TicketWatcher::SOURCE_MANUAL): ?TicketWatcher
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') {
            return null;
        }

        return $this->watchers()->firstOrCreate(
            ['email' => $email],
            ['company_id' => $this->company_id, 'source' => $source],
        );
    }

    /**
     * The User models watching this ticket (operator watchers only). The read is
     * already constrained by ticket_id, so it bypasses CompanyScope — it must
     * return the same set whether it runs in the panel (ambient tenant) or in the
     * poller/queue (no context), never filtered by a stale ambient company.
     *
     * @return Collection<int, User>
     */
    public function operatorWatcherUsers(): Collection
    {
        return $this->watchers()->withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter()->values();
    }

    /**
     * Lowercased external watcher email addresses (for the reply Bcc). Bypasses
     * CompanyScope for the same reason as {@see operatorWatcherUsers} — the reply
     * job may run with no/other ambient context.
     *
     * @return list<string>
     */
    public function watcherEmailAddresses(): array
    {
        return $this->watchers()->withoutGlobalScope(CompanyScope::class)
            ->whereNotNull('email')->pluck('email')
            ->map(fn ($e): string => mb_strtolower(trim((string) $e)))
            ->filter()->unique()->values()->all();
    }

    /** Messages a customer may see (excludes operator-only internal notes). */
    public function publicMessages(): HasMany
    {
        return $this->messages()->where('is_internal_note', false);
    }

    /** The requester's display name — the linked customer, else the raw email name. */
    public function requesterLabel(): string
    {
        return $this->customer?->name
            ?: ($this->requester_name ?: (string) $this->requester_email);
    }

    /** GUEST = no linked customer account. */
    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    /**
     * The customer may rate iff the ticket is Closed AND its department invites
     * feedback (`feedback_on_close`). Re-rating while still closed is allowed (a
     * misclick fix); reopening the ticket makes this false again.
     */
    public function canBeRated(): bool
    {
        return $this->status === TicketStatus::Closed
            && (bool) ($this->department?->feedback_on_close);
    }

    public function isRated(): bool
    {
        return $this->rating !== null;
    }

    /**
     * Store a customer satisfaction rating (1–5, clamped) + an optional comment.
     * Callers gate on {@see canBeRated} + ownership first — this only writes.
     */
    public function recordRating(int $rating, ?string $comment = null): void
    {
        $comment = trim((string) $comment);

        $this->forceFill([
            'rating' => max(1, min(5, $rating)),
            'rating_comment' => $comment !== '' ? $comment : null,
            'rated_at' => now(),
        ])->save();
    }
}
