<?php

namespace App\Models;

use App\Enums\HolidayRule;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Hr\GreekHolidays;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Τοπική αργία / κλειστό γραφείο — a tenant's extra non-working day on top of
 * the national ones (GreekHolidays). There is no official machine-readable list
 * of local holidays (they are set per region by decision), so the admin keeps
 * the ones the office actually observes.
 */
class CompanyHoliday extends Model
{
    use BelongsToCompany;

    protected $attributes = [
        'is_active' => true,
    ];

    protected $fillable = [
        'company_id',
        'name',
        'rule',
        'month',
        'day',
        'easter_offset',
        'date',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rule' => HolidayRule::class,
            'month' => 'integer',
            'day' => 'integer',
            'easter_offset' => 'integer',
            'date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The concrete date in $year, or null when the rule doesn't fall in it. */
    public function dateInYear(int $year): ?string
    {
        return match ($this->rule) {
            HolidayRule::Fixed => ($this->month && $this->day && checkdate($this->month, $this->day, $year))
                ? sprintf('%04d-%02d-%02d', $year, $this->month, $this->day)
                : null,
            HolidayRule::Easter => $this->easter_offset === null
                ? null
                : GreekHolidays::orthodoxEaster($year)->addDays($this->easter_offset)->toDateString(),
            HolidayRule::Once => ($this->date && (int) $this->date->format('Y') === $year)
                ? $this->date->toDateString()
                : null,
            default => null,
        };
    }

    public function ruleLabel(): string
    {
        return match ($this->rule) {
            HolidayRule::Fixed => sprintf('%02d/%02d κάθε χρόνο', $this->day, $this->month),
            HolidayRule::Easter => sprintf('Πάσχα %+d ημ.', $this->easter_offset),
            HolidayRule::Once => (string) $this->date?->format('d/m/Y'),
            default => '—',
        };
    }
}
