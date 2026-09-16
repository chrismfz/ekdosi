<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Peppol\PeppolInvoiceDocument;

/**
 * ONE παραστατικό as a UBL / PEPPOL BIS Billing 3.0 (EN 16931) document: the
 * generated XML plus the result of the library's validation (EN 16931 structural
 * BRs + a SUBSET of the PEPPOL rules — NOT the Access Point's authoritative
 * Schematron). Read-only, tenant-scoped. Backs «δες/έλεγξε το UBL/e-invoice του
 * ΤΠΥ…» so the structured document can be inspected outside the panel (the same
 * bytes the invoice's «Προβολή/Λήψη UBL» buttons produce).
 *
 * Provider-independent by design — every tenant (Greek mainland included) gets a
 * standards document today; the Access-Point transport (the actual send) is Phase 2.
 */
class PeppolUblTool implements AssistantTool
{
    public function name(): string
    {
        return 'invoice_ubl';
    }

    public function description(): string
    {
        return 'Το παραστατικό ως UBL / PEPPOL BIS Billing 3.0 (EN 16931): το XML + αποτέλεσμα ελέγχου '
            .'εγκυρότητας (υποσύνολο EN 16931 + PEPPOL — ΟΧΙ ο οριστικός έλεγχος του Access Point). Read-only. '
            .'Αναζήτηση με `invoice` (κωδικός ΤΠΥ ή ekdosi id). Για «δες/έλεγξε το UBL/e-invoice του ΤΠΥ6981», '
            .'«βγάλε το PEPPOL XML του παραστατικού».';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice' => ['type' => 'string', 'description' => 'Κωδικός παραστατικού (invcode, π.χ. ΤΠΥ6981) ή ekdosi id.'],
            ],
            'required' => ['invoice'],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $ref = trim((string) ($input['invoice'] ?? ''));
        if ($ref === '') {
            return ['error' => 'Δώσε `invoice` (κωδικός ΤΠΥ ή id).'];
        }

        // Prefer the printed invcode; fall back to the surrogate id ONLY when no
        // invcode matches (identical rule to invoice_get — a numeric invcode must
        // never be shadowed by an unrelated row whose id equals the typed value).
        $base = Invoice::query()->where('invoices.company_id', $tenant->getKey());
        $inv = (clone $base)->where('invcode', $ref)->orderByDesc('invoices.id')->first();
        if ($inv === null && ctype_digit($ref)) {
            $inv = $base->where('id', (int) $ref)->first();
        }
        if ($inv === null) {
            return ['found' => false];
        }

        $doc = app(PeppolInvoiceDocument::class);

        try {
            $xml = $doc->xml($inv);
            $error = $doc->validate($inv);
        } catch (\Throwable $e) {
            // A mapping failure (missing seller/customer, bad data) — surface it
            // instead of letting ToolRegistry flatten it to a generic error.
            return [
                'found' => true,
                'id' => $inv->id,
                'code' => $inv->invcode,
                'built' => false,
                'error' => $e->getMessage(),
            ];
        }

        return [
            'found' => true,
            'id' => $inv->id,
            'code' => $inv->invcode,
            'built' => true,
            'standard' => 'PEPPOL BIS Billing 3.0 (EN 16931)',
            'valid' => $error === null,
            // The check is a SUBSET — a null error is not an Access-Point pass (Phase 2).
            'validation' => $error ?? 'passed (EN 16931 + PEPPOL subset; not the Access Point\'s authoritative check)',
            'xml_bytes' => strlen($xml),
            'xml' => $xml,
            'url' => InvoiceResource::getUrl('view', ['record' => $inv->id, 'tenant' => $tenant]),
        ];
    }
}
