<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Ticket;
use App\Models\User;

/**
 * Expands {{token}} placeholders in a canned reply against a ticket's context
 * (Πυλώνας E). Plain-text substitution (the result goes into a Textarea, not
 * HTML), so values are inserted raw. An UNKNOWN token is left verbatim — a typo
 * stays visible instead of silently blanking, and lets an operator paste literal
 * braces without surprise.
 */
class CannedReplyExpander
{
    /**
     * The tokens offered to operators (shown as a hint on the canned-reply form).
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return [
            '{{customer.name}}', '{{customer.afm}}',
            '{{ticket.reference}}', '{{ticket.subject}}',
            '{{company.name}}', '{{company.phone}}', '{{company.email}}',
            '{{company.ibans}}', '{{operator.name}}',
        ];
    }

    public static function expand(string $template, Ticket $ticket, ?User $operator = null): string
    {
        $company = $ticket->company;
        $customer = $ticket->customer;

        $map = [
            'customer.name' => $customer?->name ?? $ticket->requester_name ?? '',
            'customer.afm' => (string) ($customer?->afm ?? ''),
            'ticket.reference' => $ticket->reference,
            'ticket.subject' => (string) $ticket->subject,
            'company.name' => (string) ($company?->name ?? ''),
            'company.phone' => (string) ($company?->phone ?? ''),
            'company.email' => (string) ($company?->email ?? ''),
            'company.ibans' => self::ibans($company),
            'operator.name' => (string) ($operator?->name ?? ''),
        ];

        return preg_replace_callback(
            '/\{\{\s*([a-z_.]+)\s*\}\}/i',
            function (array $m) use ($map): string {
                $key = strtolower(trim($m[1]));

                return array_key_exists($key, $map) ? $map[$key] : $m[0];
            },
            $template,
        );
    }

    /** The tenant's bank accounts as «BANK: IBAN» lines (for the «Τραπεζικοί λογαριασμοί» reply). */
    private static function ibans(?Company $company): string
    {
        if ($company === null) {
            return '';
        }

        return BankAccount::query()
            ->where('company_id', $company->getKey())
            ->orderBy('bank_name')
            ->get(['bank_name', 'iban'])
            ->map(fn (BankAccount $a): string => trim(($a->bank_name ? $a->bank_name.': ' : '').$a->iban))
            ->filter()
            ->implode("\n");
    }
}
