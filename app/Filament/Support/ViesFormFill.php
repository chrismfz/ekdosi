<?php

namespace App\Filament\Support;

use App\DTOs\ViesResult;
use App\Exceptions\Vies\ViesInvalidFormat;
use App\Exceptions\Vies\ViesUnavailable;
use App\Services\ViesLookup;
use Filament\Notifications\Notification;

/**
 * Shared "validate an EU VAT number against VIES from a Filament form" helper —
 * the EU twin of AadeFormFill (which does Greek AFMs via GSIS).
 *
 * Customer and Supplier forms both get a «Επαλήθευση VIES» action next to the
 * vat_vies field. This centralises the lookup + the failure notifications so
 * the two forms can't drift; callers map the returned record's identity fields
 * themselves (where VIES publishes them).
 */
class ViesFormFill
{
    /**
     * Run the check and SHOW the validity outcome as a notification.
     *
     * @param  string|null  $vat      The VAT id (with or without country prefix).
     * @param  string|null  $country  Customer/Supplier country, used when $vat
     *                                has no prefix of its own.
     * @return ViesResult|null  the record (valid or not), or null after a
     *                          failure notification (bad format / unavailable).
     */
    public static function check(?string $vat, ?string $country): ?ViesResult
    {
        $vat = trim((string) $vat);
        if ($vat === '') {
            Notification::make()->title('Συμπληρώστε πρώτα αριθμό ΦΠΑ (VIES).')->warning()->send();

            return null;
        }

        try {
            $result = app(ViesLookup::class)->check($vat, $country);
        } catch (ViesInvalidFormat $e) {
            Notification::make()
                ->title('Μη έγκυρη μορφή ΑΦΜ/ΦΠΑ για VIES')
                ->body($e->getMessage())
                ->warning()->send();

            return null;
        } catch (ViesUnavailable $e) {
            Notification::make()
                ->title('Η υπηρεσία VIES δεν είναι προσβάσιμη')
                ->body($e->getMessage().' Δοκιμάστε ξανά σε λίγο ή συμπληρώστε χειροκίνητα.')
                ->warning()->send();

            return null;
        }

        if ($result->valid) {
            Notification::make()
                ->title('Έγκυρο ΦΠΑ VIES: '.$result->fullVatId())
                ->body($result->hasIdentity()
                    ? $result->name
                    : 'Έγκυρο (η χώρα δεν δημοσιεύει επωνυμία/διεύθυνση).')
                ->success()->send();
        } else {
            Notification::make()
                ->title('ΜΗ έγκυρο ΦΠΑ VIES: '.$result->fullVatId())
                ->body('Το VIES δεν αναγνωρίζει αυτόν τον αριθμό ως ενεργό ενδοκοινοτικό ΦΠΑ.')
                ->danger()->send();
        }

        return $result;
    }

    /**
     * Assign one VIES-sourced value to a form field. Same rule as
     * AadeFormFill::assign: never blanks a field with an empty value;
     * $overwrite=false fills only-when-empty, $overwrite=true replaces.
     */
    public static function assign(callable $get, callable $set, string $field, ?string $value, bool $overwrite): void
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '' || $value === '---') {
            return;
        }
        if ($overwrite || empty($get($field))) {
            $set($field, $value);
        }
    }
}
