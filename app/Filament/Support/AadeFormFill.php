<?php

namespace App\Filament\Support;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Services\AadeRegistryLookup;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

/**
 * Shared "look up an ΑΦΜ in the GSIS registry from a Filament form" helper.
 *
 * The customer and supplier forms both have a "Άντληση από ΑΑΔΕ" button that
 * resolves an AFM to identity fields. This centralises the tenant + AFM
 * checks and the four failure cases (no tenant / empty AFM / bad creds /
 * not-found / unreachable), each surfaced as an operator notification, so
 * the two forms can't drift. Callers map the returned record's fields
 * themselves (they fill different columns).
 */
class AadeFormFill
{
    /**
     * @return AadeRegistryRecord|null  the record on success, or null after
     *         a notification has been shown for the failure.
     */
    public static function lookup(?string $afm): ?AadeRegistryRecord
    {
        $tenant = Filament::getTenant();
        if (! $tenant) {
            Notification::make()->title('Λείπει το tenant context.')->warning()->send();

            return null;
        }

        $afm = trim((string) $afm);
        if ($afm === '') {
            Notification::make()->title('Συμπληρώστε πρώτα ΑΦΜ.')->warning()->send();

            return null;
        }

        try {
            return app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm);
        } catch (AadeCredentialsInvalid) {
            Notification::make()
                ->title('Λείπουν ή είναι άκυρα τα διαπιστευτήρια GSIS')
                ->body('Ρυθμίστε τα στο Company → AADE registry (GSIS).')
                ->danger()->send();
        } catch (AadeAfmNotFound) {
            Notification::make()
                ->title('Το ΑΦΜ δεν βρέθηκε ή είναι ανενεργό στο μητρώο ΑΑΔΕ')
                ->body('Ελέγξτε τα ψηφία ή συμπληρώστε χειροκίνητα.')
                ->warning()->send();
        } catch (AadeUnreachable) {
            Notification::make()
                ->title('Το μητρώο ΑΑΔΕ δεν είναι προσβάσιμο')
                ->body('Δοκιμάστε ξανά σε λίγο ή συμπληρώστε χειροκίνητα.')
                ->warning()->send();
        }

        return null;
    }

    /**
     * Assign one AADE-sourced value to a form field, honouring the mode:
     *   - $overwrite = false → fill only when the field is empty (operator's
     *     typed value wins; AADE fills gaps). The "import" button.
     *   - $overwrite = true  → AADE is the source of truth; replace whatever is
     *     there (only when AADE actually returned a value). The "correct from
     *     AADE" button — for when the customer typed something wrong.
     *
     * Centralised so the customer + supplier forms apply the SAME rule; each
     * form still owns its own field list (they differ — e.g. kad_primary).
     */
    public static function assign(callable $get, callable $set, string $field, ?string $value, bool $overwrite): void
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return;   // never blank out a field with an empty AADE value
        }
        if ($overwrite || empty($get($field))) {
            $set($field, $value);
        }
    }
}
