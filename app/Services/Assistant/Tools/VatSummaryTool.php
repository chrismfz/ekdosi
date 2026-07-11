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

        // MON-6: output VAT genuinely NETS credit notes — a €124 sale + its full
        // credit is €0 output VAT, not €24. Credit notes carry POSITIVE net/gross,
        // so we SUBTRACT them (not merely exclude), mirroring
        // DashboardMetrics::outputForVat(). The invoice count includes both (both
        // are issued output documents in the window).
        $base = fn () => InvoiceScope::live(
            Invoice::query()
                ->where('company_id', $tenant->getKey())
                ->whereBetween('issued_at', [$from, $to])
        );

        // MON-5: the sales side excludes unissued drafts (matching income /
        // DashboardMetrics::outputForVat); credit notes are kept as-is (draft
        // credit notes reduce locally, deliberate).
        $sales = InvoiceScope::excludeUnissuedDrafts(InvoiceScope::excludeCreditNotes($base()));
        $credits = InvoiceScope::onlyCreditNotes($base());

        $net = (float) (clone $sales)->sum('net_total') - (float) (clone $credits)->sum('net_total');
        $gross = (float) (clone $sales)->sum('gross_total') - (float) (clone $credits)->sum('gross_total');
        $count = (clone $sales)->count() + (clone $credits)->count();

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
