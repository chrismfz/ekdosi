<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Customers\CustomerResource;
use App\Models\Company;
use App\Models\Customer;

/**
 * The customers who owe the most — name + ΑΦΜ + open balance + a deep-link to
 * each one's Καρτέλα. The per-customer breakdown the aggregate
 * outstanding_receivables tool can't give («ποιοι συγκεκριμένα μου χρωστάνε»).
 */
class ListTopDebtorsTool implements AssistantTool
{
    public function name(): string
    {
        return 'list_top_debtors';
    }

    public function description(): string
    {
        return 'Δώσε τους πελάτες με τα μεγαλύτερα ανοιχτά υπόλοιπα (όνομα, ΑΦΜ, ποσό) και '
            .'ΕΝΑ link στην Καρτέλα του καθενός. Χρησιμοποίησέ το για «ποιοι μου χρωστάνε», '
            .'«ποιοι συγκεκριμένα», «οι μεγαλύτεροι οφειλέτες». Προαιρετικό όριο (προεπιλογή 10).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Πόσους να επιστρέψει (1–50, προεπιλογή 10).'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Customer';
    }

    public function run(Company $tenant, array $input): array
    {
        $limit = max(1, min(50, (int) ($input['limit'] ?? 10)));

        $debtors = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->withOutstandingBalance($tenant->getKey())
            ->onlyDebtors()
            ->orderByDesc('outstanding_balance')
            ->limit($limit)
            ->get(['customers.id', 'customers.name', 'customers.afm', 'outstanding_balance']);

        return [
            'currency' => 'EUR',
            'count' => $debtors->count(),
            'debtors' => $debtors->map(fn (Customer $c): array => [
                'name' => $c->name,
                'afm' => $c->afm,
                'balance' => round((float) $c->outstanding_balance, 2),
                'kartela_url' => CustomerResource::getUrl('ledger', ['record' => $c->id, 'tenant' => $tenant]),
            ])->all(),
        ];
    }
}
