<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ΕΡΓΑΝΗ ΙΙ call (submit / cancel), with the full request + response —
 * APPEND-ONLY legal audit, the source of truth behind leave_requests.ergani_*
 * (the same role mydata_marks plays for myDATA). Never updated or deleted.
 */
class ErganiSubmission extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'leave_request_id', 'work_card_event_id', 'overtime_declaration_id', 'user_id', 'document', 'action', 'environment',
        'ok', 'http_status', 'protocol', 'ergani_id', 'submit_date', 'message', 'request', 'response',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'http_status' => 'integer',
            'request' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function workCardEvent(): BelongsTo
    {
        return $this->belongsTo(WorkCardEvent::class);
    }

    public function overtimeDeclaration(): BelongsTo
    {
        return $this->belongsTo(OvertimeDeclaration::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
