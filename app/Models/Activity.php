<?php

namespace App\Models;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Tenant-aware activity log. Extends the package model only to stamp
 * `company_id` on write, so a per-tenant feed (App\Filament\Pages\ActivityFeed)
 * can scope without a polymorphic join on every read. The audited models
 * (Invoice / Customer / Payment) all carry `company_id`, so the subject is the
 * authoritative source; the ambient tenant is a fallback for the rare write
 * with no subject.
 *
 * Also the single home for rendering an activity for humans (the Greek subject
 * label + the per-field diff), shared by the «Ιστορικό» relation manager and
 * the feed page.
 *
 * NB: relies on the package's buffer mode being OFF (default) so the `creating`
 * event fires per row — bulk-buffered inserts would bypass it.
 */
class Activity extends SpatieActivity
{
    protected static function booted(): void
    {
        static::creating(function (self $activity): void {
            if ($activity->company_id !== null) {
                return;
            }

            $subject = $activity->subject; // in-memory subject set via performedOn()

            $activity->company_id = ($subject !== null && isset($subject->company_id))
                ? $subject->company_id
                : app(CompanyContext::class)->id();
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Greek label for the subject type (Τιμολόγιο / Πελάτης / Πληρωμή).
     */
    public function subjectLabel(): string
    {
        return match ($this->subject_type) {
            Invoice::class => 'Τιμολόγιο',
            Customer::class => 'Πελάτης',
            Payment::class => 'Πληρωμή',
            ServiceContract::class => 'Υπηρεσία',
            default => class_basename((string) $this->subject_type),
        };
    }

    /**
     * The per-field diff (v5 `attribute_changes` column: `attributes` = new,
     * `old` = previous) as "field: old → new" lines. Created rows have no `old`.
     *
     * @return list<string>
     */
    public function changeLines(): array
    {
        $changes = $this->attribute_changes;
        $new = (array) ($changes['attributes'] ?? []);
        $old = (array) ($changes['old'] ?? []);

        $lines = [];
        foreach ($new as $field => $value) {
            $to = self::scalar($value);
            $lines[] = array_key_exists($field, $old)
                ? "{$field}: ".self::scalar($old[$field]).' → '.$to
                : "{$field}: {$to}";
        }

        return $lines;
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return '∅';
        }
        if (is_bool($value)) {
            return $value ? 'ναι' : 'όχι';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '—';
    }
}
