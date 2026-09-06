<?php

namespace App\Models;

use App\Casts\MaybeEncrypted;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A support department (Πυλώνας E) — the routing unit, WHMCS «Support Departments»
 * parity. Carries its own mailbox config (IMAP poll, Phase 3) and per-department
 * toggles. The IMAP password is encrypted at rest (same cast as the other tenant
 * secrets); it is edited in the Settings Cluster, never hardcoded.
 */
class TicketDepartment extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'imap_host',
        'imap_port',
        'imap_username',
        'imap_password',
        'imap_encryption',
        'imap_folder',
        'clients_only',
        'autoresponder',
        'feedback_on_close',
        'prevent_client_closure',
        'is_hidden',
        'sort',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = ['imap_password'];

    protected function casts(): array
    {
        return [
            'imap_password' => MaybeEncrypted::class,
            'imap_port' => 'integer',
            'clients_only' => 'boolean',
            'autoresponder' => 'boolean',
            'feedback_on_close' => 'boolean',
            'prevent_client_closure' => 'boolean',
            'is_hidden' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Operators who own/watch this department. */
    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_department_user');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /** True iff this department has enough config to poll a mailbox (Phase 3). */
    public function canPollMail(): bool
    {
        return ! empty($this->imap_host)
            && ! empty($this->imap_username)
            && ! empty($this->imap_password);
    }
}
