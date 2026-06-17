<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Customer;

/**
 * Find a customer by name or ΑΦΜ → their balance + contact + a link to the
 * Καρτέλα and to «Νέο Παραστατικό» pre-filled with them. Backs «βρες τον πελάτη
 * Χ», «άνοιξε την καρτέλα του Χ», «κόψε παραστατικό στον Χ» (the link prepares it).
 */
class FindCustomerTool implements AssistantTool
{
    public function name(): string
    {
        return 'find_customer';
    }

    public function description(): string
    {
        return 'Βρες πελάτη με μέρος του ονόματος ή το ΑΦΜ. Επιστρέφει στοιχεία + ανοιχτό '
            .'υπόλοιπο + link στην Καρτέλα του και στο «Νέο Παραστατικό». Για «βρες/άνοιξε '
            .'τον πελάτη Χ», «η καρτέλα του Χ», «τι μου χρωστάει ο Χ».';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Όνομα (μέρος) ή ΑΦΜ.'],
            ],
            'required' => ['query'],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Customer';
    }

    public function run(Company $tenant, array $input): array
    {
        $q = trim((string) ($input['query'] ?? ''));
        if ($q === '') {
            return ['matches' => [], 'note' => 'Δώσε όνομα ή ΑΦΜ.'];
        }

        $customers = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->withOutstandingBalance($tenant->getKey())
            ->where(fn ($w) => $w
                ->where('customers.name', 'like', "%{$q}%")
                ->orWhere('customers.afm', 'like', "%{$q}%"))
            ->orderBy('customers.name')
            ->limit(8)
            ->get(['customers.id', 'customers.name', 'customers.afm', 'customers.city', 'customers.email', 'outstanding_balance']);

        return [
            'currency' => 'EUR',
            'count' => $customers->count(),
            'matches' => $customers->map(fn (Customer $c): array => [
                'name' => $c->name,
                'afm' => $c->afm,
                'city' => $c->city,
                'email' => $c->email,
                'balance' => round((float) $c->outstanding_balance, 2),
                'kartela_url' => CustomerResource::getUrl('ledger', ['record' => $c->id, 'tenant' => $tenant]),
                'new_invoice_url' => InvoiceResource::getUrl('create', ['customer_id' => $c->id, 'tenant' => $tenant]),
            ])->all(),
        ];
    }
}
