<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Εργαζόμενος — the staff roster for leaves (and later the ΕΡΓΑΝΗ work card).
 * `last_name`/`first_name`/`afm` are what ΕΡΓΑΝΗ expects verbatim (f_eponymo /
 * f_onoma / f_afm). `user_id` links the panel login (an operator) so they can
 * request their own leave. No payroll data by design — the accountant keeps it.
 */
class Employee extends Model
{
    use BelongsToCompany;
    use SoftDeletes;
    use TracksActivity;

    protected $attributes = [
        'is_active' => true,
        'annual_leave_days' => 20,
        'ergani_branch' => 0,
    ];

    protected $fillable = [
        'company_id',
        'user_id',
        'afm',
        'last_name',
        'first_name',
        'email',
        'ergani_branch',
        'annual_leave_days',
        'hired_at',
        'is_active',
        'notes',
        'has_work_card',
        'card_pin_hash',
    ];

    /** The tablet PIN is a secret — never serialized (JSON, Livewire, exports, logs). */
    protected $hidden = ['card_pin_hash'];

    /** @return list<string> */
    protected function loggedAttributes(): array
    {
        return [
            'user_id', 'afm', 'last_name', 'first_name', 'email', 'ergani_branch',
            'annual_leave_days', 'hired_at', 'is_active', 'notes', 'has_work_card',
        ];
    }

    protected function casts(): array
    {
        return [
            'hired_at' => 'date',
            'is_active' => 'boolean',
            'has_work_card' => 'boolean',
            'card_pin_locked_until' => 'datetime',
            'card_pin_failures' => 'integer',
            'annual_leave_days' => 'integer',
            'ergani_branch' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workCardEvents(): HasMany
    {
        return $this->hasMany(WorkCardEvent::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    /** Approved κανονική άδεια days that START in the given year. */
    public function annualLeaveTaken(int $year): int
    {
        return (int) $this->leaveRequests()
            ->where('status', LeaveStatus::Approved->value)
            ->where('type', LeaveType::Annual->value)
            ->whereYear('starts_on', $year)
            ->sum('days');
    }

    public function annualLeaveRemaining(int $year): int
    {
        return (int) $this->annual_leave_days - $this->annualLeaveTaken($year);
    }

    /**
     * Approved κανονική άδεια days per employee of a company for a year, in ONE
     * query (for lists/calendars — annualLeaveTaken() is the per-record form).
     *
     * @return array<int, int> employee_id => days
     */
    public static function annualLeaveTakenMap(int $companyId, int $year): array
    {
        return LeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', LeaveStatus::Approved->value)
            ->where('type', LeaveType::Annual->value)
            ->whereYear('starts_on', $year)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, SUM(days) as taken')
            ->pluck('taken', 'employee_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** The employee record of a panel user within a company (null when not staff). */
    public static function forUser(?User $user, int $companyId): ?self
    {
        if ($user === null) {
            return null;
        }

        return static::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user->getKey())
            ->first();
    }
}
