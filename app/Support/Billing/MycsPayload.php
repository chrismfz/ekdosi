<?php

namespace App\Support\Billing;

use App\Models\PendingWhmcsInvoice;

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
     * What a MyCS invoice reported «Paid» still owes (`mycs.balance`, docs ekdosi-bridge «Paid χωρίς (όλα) τα χρήματα»):
     * MyCS lets staff mark an invoice Paid by hand with no (or not all the) money, and says so here. > 0 → «Paid» is
     * not (all) money: issued on credit, never a full receipt, never auto-issued or auto-synced. 0.0 for anything else
     * (a WHMCS payload, not Paid, settled, overpaid — a negative balance).
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function shortfall(?array $payload): float
    {
        if (! self::is($payload) || strcasecmp((string) ($payload['status'] ?? ''), 'Paid') !== 0) {
            return 0.0;
        }
        $balance = $payload['mycs']['balance'] ?? null;

        return is_numeric($balance) && (float) $balance > 0.005 ? round((float) $balance, 2) : 0.0;
    }

    /**
     * What the customer HAS paid on a «Paid» invoice with a shortfall: the document value (MyCS `total` is net of the
     * account credit applied, so total + credit — PendingWhmcsInvoice::documentTotalOf) − balance (≥ 0). Account
     * credit counts as paid, as everywhere else in ekdosi. Meaningful only when shortfall() > 0.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function collected(array $payload): float
    {
        return max(0.0, round(PendingWhmcsInvoice::documentTotalOf($payload) - self::shortfall($payload), 2));
    }

    /**
     * The date (Y-m-d) of the latest incoming MyCS transaction (net of refunds), or null. MyCS sends `transactions` as a flat list of
     * {transid, gateway, date, amount, refunded}.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function latestTransactionDate(array $payload): ?string
    {
        $rows = $payload['transactions'] ?? [];
        if (! is_array($rows)) {
            return null;
        }
        $latest = null;
        foreach ($rows as $row) {
            $date = is_array($row) ? substr((string) ($row['date'] ?? ''), 0, 10) : '';
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && $date !== '0000-00-00'
                // Net of what was refunded: a payment refunded in full is not money that came in.
                && (float) ($row['amount'] ?? 0) - (float) ($row['refunded'] ?? 0) > 0.005 && ($latest === null || $date > $latest)) {
                $latest = $date;
            }
        }

        return $latest;
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
     * A hash of a MyCS invoice's line routing (`third_party`), which the stored snapshot does not keep (the ingestor
     * moves it to third_party_resolution): what the refresh before issuing compares (R2-2 of #703).
     */
    public static function routingHash(mixed $thirdParty): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            ksort($value);

            return array_map($sort, $value);
        };

        return md5((string) json_encode($sort($thirdParty)));
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
