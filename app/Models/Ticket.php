<?php

namespace App\Models;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'datetime',
            'closed_at' => 'datetime',
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
}
