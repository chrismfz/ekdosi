<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One declared υπερωρία (ΕΡΓΑΝΗ WTOOv): employee, day, from–to. Never deleted —
 * ΕΡΓΑΝΗ has no API to withdraw an overtime declaration, so neither does ekdosi.
 */
class OvertimeDeclaration extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id', 'employee_id', 'work_date', 'from_time', 'to_time', 'note', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function erganiSubmissions(): HasMany
    {
        return $this->hasMany(ErganiSubmission::class);
    }

    /** When the overtime starts (Athens) — ΕΡΓΑΝΗ only accepts a declaration BEFORE this. */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->work_date->toDateString().' '.$this->from_time, 'Europe/Athens');
    }

    public function hasStarted(): bool
    {
        return $this->startsAt()->lte(now());
    }

    /** «Δε 28/09 18:00–20:00 (2ω)» */
    public function slotLabel(): string
    {
        $minutes = self::minutes($this->from_time, $this->to_time);

        return $this->work_date->format('d/m/Y').' '.$this->from_time.'–'.$this->to_time
            .' ('.intdiv($minutes, 60).'ω'.($minutes % 60 ? ' '.($minutes % 60).'\'' : '').')';
    }

    public static function minutes(string $from, string $to): int
    {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));

        return ($th * 60 + $tm) - ($fh * 60 + $fm);
    }
}
