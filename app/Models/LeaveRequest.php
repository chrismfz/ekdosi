<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Αίτημα άδειας. `days` = working days (Mon–Fri minus national + company
 * holidays), pre-computed by WorkingDays and editable by the approver. State
 * changes go through App\Services\Hr\LeaveWorkflow (approve/reject/cancel), which
 * also notifies the accountant. The `ergani_*` columns are reserved for the
 * later WTOLeave submission (docs/ergani/README.md §3–4).
 */
class LeaveRequest extends Model
{
    use BelongsToCompany;
    use TracksActivity;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'company_id',
        'employee_id',
        'type',
        'starts_on',
        'ends_on',
        'days',
        'status',
        'reason',
        'decision_note',
        'requested_by_user_id',
    ];

    /** @return list<string> */
    protected function loggedAttributes(): array
    {
        return [
            'employee_id', 'type', 'starts_on', 'ends_on', 'days', 'status',
            'reason', 'decision_note', 'decided_by_user_id',
        ];
    }

    protected function casts(): array
    {
        return [
            'type' => LeaveType::class,
            'status' => LeaveStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'days' => 'integer',
            'decided_at' => 'datetime',
            'accountant_notified_at' => 'datetime',
            'ergani_submitted_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /** Rows whose [starts_on, ends_on] intersects [$from, $to]. */
    public function scopeOverlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->whereDate('ends_on', '>=', $from->toDateString());
    }

    /** Pending + approved — the rows that still «hold» their dates. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [LeaveStatus::Pending->value, LeaveStatus::Approved->value]);
    }

    public function isPending(): bool
    {
        return $this->status === LeaveStatus::Pending;
    }

    public function isApproved(): bool
    {
        return $this->status === LeaveStatus::Approved;
    }

    /** Approved/cancelled news the accountant hasn't received yet (and an address exists to send it to). */
    public function accountantOwed(): ?string
    {
        return filled($this->company?->leave_notify_email) ? $this->accountant_owed_event : null;
    }

    public function periodLabel(): string
    {
        $from = $this->starts_on?->format('d/m/Y');
        $to = $this->ends_on?->format('d/m/Y');

        return $from === $to ? (string) $from : "{$from} – {$to}";
    }
}
