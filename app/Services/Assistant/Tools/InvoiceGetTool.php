<?php

namespace App\Services\Assistant\Tools;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Note;
use Illuminate\Support\Carbon;

/**
 * ONE παραστατικό with EVERYTHING inside it: header, per-line detail
 * (περιγραφή/ποσότητα/τιμή/ΦΠΑ/καθαρό-μεικτό), the operator's internal notes
 * (where a WHMCS id is often referenced) and the WHMCS/myDATA links. Lookup by
 * ΤΠΥ code, ekdosi id, OR whmcs_invoice_id. Read-only, tenant-scoped.
 *
 * Complements `invoice_filing` (which is filing-state + mark history focused) —
 * this is the line/notes view. Backs «τι έχει μέσα το ΤΠΥ6981», «ποιο whmcs id
 * έχει», «δείξε τις γραμμές/σημειώσεις του παραστατικού».
 */
class InvoiceGetTool implements AssistantTool
{
    public function name(): string
    {
        return 'invoice_get';
    }

    public function description(): string
    {
        return 'Ένα παραστατικό με ΟΛΑ τα στοιχεία του: κεφαλίδα, γραμμές (περιγραφή/ποσότητα/τιμή/ΦΠΑ/'
            .'καθαρό-μεικτό), εσωτερικές σημειώσεις (εκεί αναφέρουμε συχνά το WHMCS id), κατάσταση myDATA & '
            .'πληρωμής, whmcs_invoice_id, link. Αναζήτηση με `invoice` (κωδικός ΤΠΥ ή id) Ή `whmcs_invoice_id`. '
            .'Για «τι έχει μέσα το ΤΠΥ6981», «οι γραμμές/σημειώσεις του παραστατικού», «ποιο whmcs id έχει».';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'invoice' => ['type' => 'string', 'description' => 'Κωδικός παραστατικού (invcode, π.χ. ΤΠΥ6981) ή ekdosi id.'],
                'whmcs_invoice_id' => ['type' => 'integer', 'description' => 'Εναλλακτικά: το WHMCS invoice id που είναι δεμένο στο παραστατικό.'],
            ],
        ];
    }

    public function permission(): ?string
    {
        return 'View:Invoice';
    }

    public function run(Company $tenant, array $input): array
    {
        $ref = trim((string) ($input['invoice'] ?? ''));
        $whmcsId = (int) ($input['whmcs_invoice_id'] ?? 0);

        $q = Invoice::query()
            ->where('invoices.company_id', $tenant->getKey())
            ->with([
                'customer:id,name,afm',
                'invoiceType:id,code,name',
                'lines',
                'internalNotes.author:id,name',
            ]);

        if ($whmcsId > 0) {
            $q->where('whmcs_invoice_id', $whmcsId);
        } elseif ($ref !== '') {
            // Digits could be either the printed invcode or the surrogate id —
            // try both; a plain string is an invcode only.
            if (ctype_digit($ref)) {
                $q->where(fn ($w) => $w->where('invcode', $ref)->orWhere('id', (int) $ref));
            } else {
                $q->where('invcode', $ref);
            }
        } else {
            return ['error' => 'Δώσε `invoice` (κωδικός ΤΠΥ ή id) ή `whmcs_invoice_id`.'];
        }

        // Newest first so an ambiguous digit ref (rare invcode==id collision)
        // returns the most recent, deterministically.
        $inv = $q->orderByDesc('invoices.id')->first();
        if ($inv === null) {
            return ['found' => false];
        }

        $net = round((float) $inv->net_total, 2);
        $gross = round((float) $inv->gross_total, 2);

        return [
            'found' => true,
            'id' => $inv->id,
            'code' => $inv->invcode,
            'type' => $inv->invoiceType?->name,
            'customer' => [
                'name' => $inv->customer?->name,
                'afm' => $inv->customer?->afm,
            ],
            'issued_at' => $inv->issued_at instanceof Carbon ? $inv->issued_at->format('Y-m-d H:i') : (string) $inv->issued_at,
            'local_status' => $inv->local_status,
            'mydata_state' => $inv->mydata_state ?? 'μη υποβληθέν',
            'mydata_mark' => $inv->mydata_mark,
            'whmcs_invoice_id' => $inv->whmcs_invoice_id,
            'whmcs_pending_id' => $inv->whmcs_pending_id,
            'currency' => 'EUR',
            'totals' => [
                'net' => $net,
                'vat' => round($gross - $net, 2),
                'gross' => $gross,
                'payable' => $inv->payable_total !== null ? round((float) $inv->payable_total, 2) : null,
            ],
            'payment' => [
                'status' => $inv->payment_status,
                'paid' => round((float) $inv->paid_total, 2),
                'credited' => round((float) $inv->credited_total, 2),
            ],
            'lines' => $inv->lines->map(fn (InvoiceLine $l): array => [
                'description' => $l->product_descr,
                'qty' => (float) $l->qty,
                'unit' => $l->metric_unit,
                'price_per_item' => round((float) $l->price_per_item, 2),
                'discount_pct' => (float) $l->discount,
                'vat_pct' => (float) $l->vat_percent,
                'net' => round((float) $l->net_price, 2),
                'gross' => round((float) $l->gross_price, 2),
                'note' => $l->notes ?: null,
            ])->all(),
            'internal_notes' => $inv->internalNotes->map(fn (Note $n): array => [
                'body' => $n->body,
                'author' => $n->author?->name ?? 'Σύστημα',
                'pinned' => (bool) $n->is_pinned,
                'at' => $n->created_at instanceof Carbon ? $n->created_at->format('Y-m-d H:i') : (string) $n->created_at,
            ])->all(),
            'url' => InvoiceResource::getUrl('view', ['record' => $inv->id, 'tenant' => $tenant]),
        ];
    }
}
