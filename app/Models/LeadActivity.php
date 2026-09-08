<?php

namespace App\Models;

use App\Enums\LeadActivityType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use App\Models\Observers\LeadActivityObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a lead's χρονολόγιο: «πήραμε τηλέφωνο (δεν απάντησε)», «στείλαμε
 * email», «ραντεβού», σημείωση, or a system row (status change / quote /
 * conversion). Typed so the per-operator activity report can count them.
 *
 * Freely editable/deletable (owner decision — keep it simple); accountability
 * comes from TracksActivity (who changed/deleted what shows in «Ιστορικό»).
 * LeadActivityObserver keeps `leads.last_activity_at` in sync.
 */
#[ObservedBy(LeadActivityObserver::class)]
class LeadActivity extends Model
{
    use BelongsToCompany;
    use TracksActivity;

    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    protected $fillable = [
        'company_id',
        'lead_id',
        'user_id',
        'type',
        'direction',
        'outcome',
        'happened_at',
        'body',
        'meta',
    ];

    /**
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return ['type', 'direction', 'outcome', 'happened_at', 'body'];
    }

    protected function casts(): array
    {
        return [
            'type' => LeadActivityType::class,
            'happened_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function directionOptions(): array
    {
        return [
            self::DIRECTION_OUTBOUND => 'Εμείς → αυτοί',
            self::DIRECTION_INBOUND => 'Αυτοί → εμείς',
        ];
    }

    public function directionLabel(): ?string
    {
        return $this->direction === null ? null : (self::directionOptions()[$this->direction] ?? $this->direction);
    }

    /** Greek label of the outcome for this row's type (raw value if unknown). */
    public function outcomeLabel(): ?string
    {
        if ($this->outcome === null || $this->outcome === '') {
            return null;
        }

        return $this->type?->outcomes()[$this->outcome] ?? $this->outcome;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
