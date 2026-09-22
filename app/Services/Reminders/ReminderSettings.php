<?php

namespace App\Services\Reminders;

use App\Models\Company;
use App\Models\InvoiceReminder;
use Carbon\CarbonImmutable;

/** A tenant's reminder settings, read once from `companies`. */
final class ReminderSettings
{
    public const MODE_REVIEW = 'review';

    public const MODE_AUTO = 'auto';

    /**
     * @param  array<string, int>  $stages  stage => day offset from the due date (pre_due is negative), ascending
     * @param  array<string, array{subject?: ?string, body?: ?string}>  $templates  per-stage overrides
     * @param  string  $templateLocale  the language the overrides are written in (the tenant's)
     */
    private function __construct(
        public readonly bool $enabled,
        public readonly string $mode,
        public readonly CarbonImmutable $since,
        public readonly array $stages,
        public readonly float $minBalance,
        public readonly bool $attachPdf,
        public readonly array $templates,
        public readonly string $templateLocale,
    ) {}

    public static function for(Company $company): self
    {
        $stages = array_filter([
            InvoiceReminder::STAGE_PRE_DUE => $company->reminder_pre_due_days !== null ? -1 * (int) $company->reminder_pre_due_days : null,
            InvoiceReminder::STAGE_FIRST => $company->reminder_first_days,
            InvoiceReminder::STAGE_SECOND => $company->reminder_second_days,
            InvoiceReminder::STAGE_FINAL => $company->reminder_final_days,
        ], static fn ($days): bool => $days !== null);
        asort($stages);

        return new self(
            enabled: (bool) $company->reminders_enabled,
            mode: $company->reminders_mode === self::MODE_AUTO ? self::MODE_AUTO : self::MODE_REVIEW,
            // Never «everything ever owed» on the day it is switched on: only documents
            // due from this date. Unset (shouldn't happen — the settings form requires
            // it) behaves as «from today».
            since: CarbonImmutable::parse($company->reminders_since ?? 'today')->startOfDay(),
            stages: array_map('intval', $stages),
            minBalance: (float) ($company->reminder_min_balance ?? 0),
            attachPdf: (bool) $company->reminder_attach_pdf,
            templates: is_array($company->reminder_templates) ? $company->reminder_templates : [],
            templateLocale: self::templateLocaleOf($company),
        );
    }

    /** The operator writes the overrides in the tenant's language (el unless it is English-first). */
    public static function templateLocaleOf(Company $company): string
    {
        return $company->default_language === 'en' ? 'en' : 'el';
    }

    /**
     * A per-stage override, or null to use the translated default. Overrides are
     * single-language, so a customer in another language gets the translated
     * default instead of text they may not read.
     */
    public function template(string $stage, string $part, string $locale): ?string
    {
        if ($locale !== $this->templateLocale) {
            return null;
        }

        $value = trim((string) ($this->templates[$stage][$part] ?? ''));

        return $value === '' ? null : $value;
    }
}
