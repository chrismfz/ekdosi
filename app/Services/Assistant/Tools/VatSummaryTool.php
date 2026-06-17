<?php

namespace App\Services\Assistant\Tools;

use App\Models\Company;
use App\Models\Invoice;
use App\Support\InvoiceScope;
use Illuminate\Support\Carbon;

/**
 * Sales (εκροών) net / VAT / gross + invoice count for a period — the «τζίρος και
 * ΦΠΑ του μήνα/τριμήνου» tool. Computed from live invoices (no AADE call); VAT =
 * gross − net. The expense (εισροών) side lives on the dashboard «Εικόνα από
 * myDATA» and the Βιβλίο Εσόδων-Εξόδων (a follow-up tool can surface it).
 */
class VatSummaryTool implements AssistantTool
{
    public function name(): string
    {
        return 'vat_summary';
    }

    public function description(): string
    {
        return 'Σύνοψη πωλήσεων για ένα διάστημα: καθαρή αξία, ΦΠΑ εκροών, αξία με ΦΠΑ και '
            .'πλήθος παραστατικών. Για «ΦΠΑ μήνα/τριμήνου», «τζίρος και ΦΠΑ της περιόδου». '
            .'Ημερομηνίες προαιρετικές (YYYY-MM-DD, προεπιλογή τρέχων μήνας). Αφορά ΕΚΡΟΕΣ '
            .'(πωλήσεις)· για εισροές/έξοδα δες την Εικόνα ΦΠΑ στον Πίνακα ελέγχου.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => 'string', 'description' => 'Αρχή (YYYY-MM-DD). Προεπιλογή: αρχή τρέχοντος μήνα.'],
                'to' => ['type' => 'string', 'description' => 'Τέλος (YYYY-MM-DD). Προεπιλογή: σήμερα.'],
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
        InvoiceScope::live($q);

        $net = (float) (clone $q)->sum('net_total');
        $gross = (float) (clone $q)->sum('gross_total');
        $count = (clone $q)->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'currency' => 'EUR',
            'invoice_count' => $count,
            'net' => round($net, 2),
            'vat_output' => round($gross - $net, 2),
            'gross' => round($gross, 2),
        ];
    }
}
