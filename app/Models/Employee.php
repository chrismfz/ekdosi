<?php

namespace App\Models;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Approved κανονική άδεια days charged to the given year. A leave that crosses
     * New Year (29/12–3/1) is split by its working days in each year — not charged
     * whole to the start year.
     */
    public function annualLeaveTaken(int $year): int
    {
        return self::annualLeaveTakenMap((int) $this->company_id, $year, (int) $this->getKey())[$this->getKey()] ?? 0;
    }

    public function annualLeaveRemaining(int $year): int
    {
        return (int) $this->annual_leave_days - $this->annualLeaveTaken($year);
    }

    /**
     * Approved κανονική άδεια days per employee charged to $year, in ONE query
     * (lists/calendars; annualLeaveTaken() is the per-record form).
     *
     * @return array<int, int> employee_id => days
     */
    public static function annualLeaveTakenMap(int $companyId, int $year, ?int $employeeId = null): array
    {
        $jan1 = CarbonImmutable::create($year, 1, 1);

        $map = [];
        LeaveRequest::query()
            ->where('company_id', $companyId)
            ->when($employeeId !== null, fn (Builder $query) => $query->where('employee_id', $employeeId))
            ->where('status', LeaveStatus::Approved->value)
            ->where('type', LeaveType::Annual->value)
            ->whereDate('starts_on', '<=', $jan1->endOfYear()->toDateString())
            ->whereDate('ends_on', '>=', $jan1->toDateString())
            ->get(['id', 'employee_id', 'starts_on', 'ends_on', 'days'])
            ->each(function (LeaveRequest $leave) use (&$map, $year, $companyId): void {
                $map[$leave->employee_id] = ($map[$leave->employee_id] ?? 0) + self::daysInYear($leave, $year, $companyId);
            });

        return $map;
    }

    /**
     * The part of $leave->days that falls in $year. Split by working days (the
     * company's calendar) cumulatively, so the yearly parts always add up to the
     * stored `days` — even when an approver adjusted it by hand.
     */
    public static function daysInYear(LeaveRequest $leave, int $year, int $companyId): int
    {
        $from = CarbonImmutable::parse($leave->starts_on)->startOfDay();
        $to = CarbonImmutable::parse($leave->ends_on)->startOfDay();
        $days = (int) $leave->days;
        if ((int) $from->format('Y') === $year && (int) $to->format('Y') === $year) {
            return $days;
        }

        $calc = WorkingDays::for($companyId);
        $total = $calc->count($from, $to);
        if ($total <= 0) {
            return (int) $from->format('Y') === $year ? $days : 0;
        }
        // Days charged up to the end of year $y (0 before the leave starts).
        $upTo = function (int $y) use ($calc, $from, $to, $total, $days): int {
            $end = CarbonImmutable::create($y, 12, 31);
            if ($end->lt($from)) {
                return 0;
            }

            return (int) round($days * $calc->count($from, $end->lt($to) ? $end : $to) / $total);
        };

        return $upTo($year) - $upTo($year - 1);
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
