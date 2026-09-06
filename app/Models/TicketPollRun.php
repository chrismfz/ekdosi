<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One IMAP poll of a department's mailbox (Πυλώνας E, Phase 3b) — the health record
 * the «Test σύνδεσης»/MCP surfaces read for «last poll: connected, N fetched, N
 * opened, errors». Runtime data (not portable).
 */
class TicketPollRun extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'ticket_department_id',
        'connected',
        'fetched',
        'processed',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'connected' => 'boolean',
            'fetched' => 'integer',
            'processed' => 'integer',
            'errors' => 'array',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(TicketDepartment::class, 'ticket_department_id');
    }
}
