<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use Illuminate\Support\Carbon;

/**
 * Counts LIVE sales invoices (not cancelled / not AADE-cancelled) and their
 * total gross over a date window — the «πόσες πωλήσεις στο διάστημα X» tool.
 */
class CountSalesTool implements AssistantTool
{
    public function name(): string
    {
        return 'count_sales';
    }

    public function description(): string
    {
        return 'Μέτρα τις πωλήσεις (τιμολόγια) και το συνολικό τζίρο (με ΦΠΑ) της '
            .'τρέχουσας εταιρείας σε ένα διάστημα. Χρησιμοποίησέ το για ερωτήσεις τύπου '
            .'«πόσες πωλήσεις/τιμολόγια έκοψα», «τζίρος Χ μήνα/περιόδου». Οι ημερομηνίες '
            .'είναι προαιρετικές (προεπιλογή: τρέχων μήνας), μορφή YYYY-MM-DD.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Αρχή διαστήματος (YYYY-MM-DD). Προεπιλογή: αρχή τρέχοντος μήνα.'],
                'to' => ['type' => 'string', 'description' => 'Τέλος διαστήματος (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $from = ! empty($input['from']) ? Carbon::parse($input['from'])->startOfDay() : now()->startOfMonth();
        $to = ! empty($input['to']) ? Carbon::parse($input['to'])->endOfDay() : now()->endOfDay();

        $q = Invoice::query()
            ->where('company_id', $tenant->getKey())
            ->whereBetween('issued_at', [$from, $to]);
        // MON-6: credit notes carry POSITIVE gross_total, so counting them as
        // sales inflated both the invoice count and the turnover. Exclude them —
        // correlated (credited_invoice_id) AND standalone/legacy (is_credit type).
        InvoiceScope::excludeCreditNotes($q);
        InvoiceScope::live($q);

        $count = (clone $q)->count();
        $gross = (float) (clone $q)->sum('gross_total');

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'invoice_count' => $count,
            'gross_total' => round($gross, 2),
            'currency' => 'EUR',
        ];
    }
}
