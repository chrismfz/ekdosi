<?php

namespace App\Support\Billing;

/**
 * Reads what a MyCS feed payload says outright (docs/bridges-connectors.md §12), where a WHMCS one carries client
 * custom fields resolved through the company's whmcs_custom_field_map: the `mycs` block, with the account's
 * recipient (ΑΦΜ, ΔΟΥ, activity, the document it wants), its «γκρινιάρης» flag and its credit/debit notes. Nothing to map.
 */
final class MycsPayload
{
    /** @param  array<string, mixed>|null  $payload */
    public static function is(?array $payload): bool
    {
        return is_array($payload['mycs'] ?? null);
    }

    /**
     * The value of a custom-field role (vatno, taxoffice, occupation, wantsinvoice, griniaris), as a WHMCS checkbox
     * or text would read; null when MyCS has none.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function role(array $payload, string $role): ?string
    {
        $recipient = is_array($payload['mycs']['recipient'] ?? null) ? $payload['mycs']['recipient'] : [];
        $value = match ($role) {
            'vatno' => $recipient['gr_vatno'] ?? null,
            'taxoffice' => $recipient['tax_office'] ?? null,
            'occupation' => $recipient['description'] ?? null,
            'wantsinvoice' => match (self::wantsInvoice($payload)) {
                true => 'on',
                false => '',
                null => null,
            },
            'griniaris' => match (self::immediate($payload)) {
                true => 'on',
                false => '',
                null => null,
            },
            default => null,
        };
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The account's choice (contacts.document_type): invoice → true, receipt or none → false, not chosen → null.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function wantsInvoice(array $payload): ?bool
    {
        return match ($payload['mycs']['recipient']['document_type'] ?? null) {
            'invoice' => true,
            'receipt', 'none' => false,
            default => null,
        };
    }

    /**
     * The account's «Άμεση τιμολόγηση»; null when MyCS did not say (unknown leaves the customer's flag alone, as an
     * unreadable WHMCS field does).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function immediate(array $payload): ?bool
    {
        $value = $payload['mycs']['immediate_invoicing'] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * The account's «Ignore invoicing»: MyCS's feed leaves such clients out, but an invoice read by id (or staged before
     * the flag was set) carries it, and it is never issued unattended.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function ignored(array $payload): bool
    {
        return (bool) ($payload['mycs']['ignore_invoicing'] ?? false);
    }

    /**
     * Staff's «Send to Ekdosi» in MyCS (E5/K3): they asked for a document of this invoice, which may be of an account
     * with «ignore invoicing» or not paid yet (issued on credit). A human issues it, never the unattended paths.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function requested(array $payload): bool
    {
        return self::requestedAt($payload) !== null;
    }

    /**
     * When MyCS staff asked (each «Send to Ekdosi» its own time), or null.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function requestedAt(?array $payload): ?string
    {
        $at = $payload['mycs']['requested_at'] ?? null;

        return is_string($at) && $at !== '' ? $at : null;
    }

    /**
     * MyCS's credit/debit notes on the invoice (its total counts them, its lines don't), in euros; 0 when none.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function adjustments(array $payload): float
    {
        $value = $payload['mycs']['adjustments'] ?? 0;

        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }
}
