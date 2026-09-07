<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ticket watcher / CC (Πυλώνας E, Phase 4). Either an operator (`user_id`, who
 * gets the in-panel bell) or a plain `email` (Cc'd on outbound replies) — never
 * both. `source` = how the watch was created (manual | participant | cc). See the
 * migration for the model.
 */
class TicketWatcher extends Model
{
    use BelongsToCompany;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PARTICIPANT = 'participant';

    public const SOURCE_CC = 'cc';

    protected $fillable = [
        'company_id',
        'ticket_id',
        'user_id',
        'email',
        'source',
    ];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Display name: the operator's name, else the external email. */
    public function label(): string
    {
        return $this->user?->name ?: (string) $this->email;
    }

    /** How the watch came to be, in Greek. */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_PARTICIPANT => 'απάντησε',
            self::SOURCE_CC => 'CC',
            default => 'χειροκίνητα',
        };
    }
}
