<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One «χτύπημα» of the Ψηφιακή Κάρτα Εργασίας (in / out). The movement itself
 * is the source of truth; ergani_* caches its WRKCardSE submission (full
 * exchange in ergani_submissions). Never deleted: ΕΡΓΑΝΗ has no API to withdraw
 * a card, so neither does ekdosi.
 */
class WorkCardEvent extends Model
{
    use BelongsToCompany;

    public const IN = 'in';

    public const OUT = 'out';

    /** f_aitiologia codes for a submission later than 15' (from the ΕΡΓΑΝΗ SDKs). */
    public const LATE_REASONS = [
        '001' => 'Διακοπή ρεύματος',
        '002' => 'Βλάβη στα συστήματα του εργοδότη',
        '003' => 'Μη διαθεσιμότητα του ΕΡΓΑΝΗ',
    ];

    protected $fillable = [
        'company_id', 'employee_id', 'type', 'occurred_at', 'reference_date', 'source',
        'created_by_user_id', 'note',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'reference_date' => 'date',
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
        return $this->hasMany(ErganiSubmission::class)->latest('id');
    }

    public function typeLabel(): string
    {
        return $this->type === self::IN ? 'Είσοδος' : 'Έξοδος';
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            'kiosk' => 'Γραφείο (tablet/QR)',
            'admin' => 'Διαχειριστής',
            default => 'Κινητό / υπολογιστής',
        };
    }

    /** Minutes between the movement and now — ΕΡΓΑΝΗ allows 15 before it's «εκπρόθεσμη». */
    public function isLate(): bool
    {
        return $this->occurred_at->lt(now()->subMinutes(15));
    }
}
