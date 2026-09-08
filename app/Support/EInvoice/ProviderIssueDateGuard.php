<?php

namespace App\Support\EInvoice;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Ordinary online issue through a ΥΠΑΗΕΣ provider (InvoSign) requires the
 * document IssueDate to equal the CURRENT Greece-local date — InvoSign
 * validation error 238. Unlike the direct-myDATA path (which AADE accepts
 * backdated within its window), the provider rejects a yesterday/tomorrow date.
 *
 * We have no Transmission-Failure (offline/backdated) issue flow yet (PROV-008),
 * so any non-today provider issue date is a hard LOCAL error surfaced before the
 * outbound request — never a silently backdated call that the provider bounces.
 * A genuine connectivity-delay case must go through the documented Transmission
 * Failure procedure once PROV-008 exists. PROV-020.
 */
final class ProviderIssueDateGuard
{
    public const TZ = 'Europe/Athens';

    public static function assertIssuedToday(?CarbonInterface $issuedAt, string $docCode): void
    {
        $ref = $docCode !== '' ? $docCode : '(χωρίς κωδικό)';

        if ($issuedAt === null) {
            throw new RuntimeException(
                "Το παραστατικό {$ref} δεν έχει ημερομηνία έκδοσης· η online έκδοση "
                .'μέσω παρόχου απαιτεί τη σημερινή ημερομηνία.'
            );
        }

        $issue = $issuedAt->copy()->setTimezone(self::TZ)->toDateString();
        $today = now()->setTimezone(self::TZ)->toDateString();

        if ($issue !== $today) {
            throw new RuntimeException(
                "Η ημερομηνία έκδοσης του {$ref} ({$issue}) δεν είναι η σημερινή ({$today}). "
                .'Η κανονική online έκδοση μέσω παρόχου απαιτεί ημερομηνία = σήμερα (InvoSign 238). '
                .'Αν είναι πρόχειρο, διορθώστε την ημερομηνία (Επεξεργασία, ή «Ημερομηνία έκδοσης → '
                .'σήμερα»)· αν είναι οριστικοποιημένο, επαναφέρετέ το πρώτα σε πρόχειρο. Σε '
                .'πραγματική αδυναμία διαβίβασης, ακολουθήστε τη διαδικασία Transmission Failure '
                .'(όταν υλοποιηθεί).'
            );
        }
    }
}
