<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use Illuminate\Support\Carbon;

/**
 * The most recent live παραστατικά (number, customer, value, myDATA + payment
 * status) + a link to each. Backs «τα τελευταία τιμολόγια», «τι έκοψα πρόσφατα»,
 * «δείξε μου το τιμολόγιο του Χ» (filter by customer name).
 */
class RecentInvoicesTool implements AssistantTool
{
    public function name(): string
    {
        return 'recent_invoices';
    }

    public function description(): string
    {
        return 'Τα πιο πρόσφατα παραστατικά (αριθμός, πελάτης, αξία με ΦΠΑ, κατάσταση myDATA & '
            .'πληρωμής) με link στο καθένα. Προαιρετικά: όριο (προεπιλογή 10) και φίλτρο '
            .'ονόματος πελάτη. Για «τελευταία τιμολόγια», «τι έκοψα», «το παραστατικό του Χ».';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Πόσα (1–30, προεπιλογή 10).'],
                'customer' => ['type' => 'string', 'description' => 'Προαιρετικό: μέρος ονόματος πελάτη.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $limit = max(1, min(30, (int) ($input['limit'] ?? 10)));
        $customer = trim((string) ($input['customer'] ?? ''));

        $q = Invoice::query()
            ->where('invoices.company_id', $tenant->getKey())
            ->with('customer:id,name');
        InvoiceScope::live($q, 'invoices.');

        if ($customer !== '') {
            $q->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$customer}%"));
        }

        $invoices = $q->orderByDesc('invoices.issued_at')
            ->limit($limit)
            ->get(['invoices.id', 'invoices.invcode', 'invoices.customer_id', 'invoices.issued_at', 'invoices.gross_total', 'invoices.mydata_state', 'invoices.payment_status']);

        return [
            'currency' => 'EUR',
            'count' => $invoices->count(),
            'invoices' => $invoices->map(fn (Invoice $inv): array => [
                'code' => $inv->invcode,
                'customer' => $inv->customer?->name,
                'date' => $inv->issued_at instanceof Carbon ? $inv->issued_at->format('Y-m-d') : (string) $inv->issued_at,
                'gross' => round((float) $inv->gross_total, 2),
                'mydata_state' => $inv->mydata_state ?? 'μη υποβληθέν',
                'payment_status' => $inv->payment_status,
                'url' => InvoiceResource::getUrl('view', ['record' => $inv->id, 'tenant' => $tenant]),
            ])->all(),
        ];
    }
}
