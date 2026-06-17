<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\Customer;

/**
 * Total outstanding receivables (ανεξόφλητα) for the current company — the same
 * canonical, cache-independent figure the dashboard + «Ηλικίωση οφειλών» use
 * (credit-term invoices with payments, summed via Customer::withOutstandingBalance).
 */
class OutstandingReceivablesTool implements AssistantTool
{
    public function name(): string
    {
        return 'outstanding_receivables';
    }

    public function description(): string
    {
        return 'Δώσε το συνολικό ανεξόφλητο υπόλοιπο (τι μας χρωστάνε οι πελάτες) της '
            .'τρέχουσας εταιρείας, και πόσοι πελάτες έχουν ανοιχτό υπόλοιπο. Χρησιμοποίησέ '
            .'το για «πόσα μας χρωστάνε», «ανεξόφλητα», «οφειλές πελατών». Χωρίς παραμέτρους.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function permission(): ?string
    {
        return 'View:Customer';
    }

    public function run(Company $tenant, array $input): array
    {
        $debtors = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->withOutstandingBalance($tenant->getKey())
            ->onlyDebtors()
            ->get(['customers.id', 'outstanding_balance']);

        $total = round((float) $debtors->sum('outstanding_balance'), 2);

        return [
            'outstanding_total' => $total,
            'debtor_count' => $debtors->count(),
            'currency' => 'EUR',
        ];
    }
}
