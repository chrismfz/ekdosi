<?php

declare(strict_types=1);

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

/**
 * Kind of a timeline row on a lead. The first four are logged by hand (the
 * quick-add buttons); the rest are written by the system (status hook,
 * quote / conversion actions).
 */
enum LeadActivityType: string implements HasColor, HasIcon, HasLabel
{
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Note = 'note';
    case StatusChange = 'status_change';
    case Quote = 'quote';
    case Converted = 'converted';

    public function getLabel(): string
    {
        return match ($this) {
            self::Call => 'Τηλέφωνο',
            self::Email => 'Email',
            self::Meeting => 'Ραντεβού',
            self::Note => 'Σημείωση',
            self::StatusChange => 'Αλλαγή κατάστασης',
            self::Quote => 'Προσφορά',
            self::Converted => 'Μετατροπή σε πελάτη',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Call => 'info',
            self::Email => 'primary',
            self::Meeting => 'warning',
            self::Note => 'gray',
            self::StatusChange => 'gray',
            self::Quote => 'warning',
            self::Converted => 'success',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Call => 'heroicon-o-phone',
            self::Email => 'heroicon-o-envelope',
            self::Meeting => 'heroicon-o-users',
            self::Note => 'heroicon-o-pencil-square',
            self::StatusChange => 'heroicon-o-arrow-path',
            self::Quote => 'heroicon-o-document-text',
            self::Converted => 'heroicon-o-check-badge',
        };
    }

    /** Logged by an operator (offered in the quick-add / edit forms). */
    public function isManual(): bool
    {
        return in_array($this, [self::Call, self::Email, self::Meeting, self::Note], true);
    }

    /**
     * Counts as reaching out («επαφή») for `leads.last_activity_at` / «Αδρανή»:
     * calls, emails, meetings and quotes — NOT notes or system rows, so a
     * note or a status change can never make a lead look worked on.
     */
    public function isContact(): bool
    {
        return in_array($this, [self::Call, self::Email, self::Meeting, self::Quote], true);
    }

    /** @return list<string> */
    public static function contactValues(): array
    {
        return array_map(
            fn (self $t): string => $t->value,
            array_values(array_filter(self::cases(), fn (self $t): bool => $t->isContact())),
        );
    }

    /** Has a meaningful outbound/inbound direction. */
    public function hasDirection(): bool
    {
        return in_array($this, [self::Call, self::Email], true);
    }

    /**
     * Outcome vocabulary per type (value => Greek label). Empty = no outcome field.
     *
     * @return array<string, string>
     */
    public function outcomes(): array
    {
        return match ($this) {
            self::Call => [
                'answered' => 'Απάντησε',
                'no_answer' => 'Δεν απάντησε',
                'callback' => 'Να ξαναπάρουμε',
                'wrong_number' => 'Λάθος αριθμός',
                'not_interested' => 'Δεν ενδιαφέρεται',
            ],
            self::Email => [
                'sent' => 'Στάλθηκε',
                'replied' => 'Απάντησε',
                'bounced' => 'Επέστρεψε (bounce)',
            ],
            self::Meeting => [
                'held' => 'Έγινε',
                'no_show' => 'Δεν ήρθε',
                'rescheduled' => 'Μεταφέρθηκε',
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    public static function manualOptions(): array
    {
        return collect(self::cases())
            ->filter(fn (self $t): bool => $t->isManual())
            ->mapWithKeys(fn (self $t): array => [$t->value => $t->getLabel()])
            ->all();
    }
}
