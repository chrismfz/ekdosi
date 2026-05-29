<?php

namespace App\Support\MyData;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Firebed\AadeMyData\Models\Invoice as AadeInvoice;
use Firebed\AadeMyData\Models\Issuer;

/**
 * The single, Livewire-safe array shape behind the "MARK detail" page
 * (App\Filament\Pages\MyDataMarkDetail). One MARK can be backed by two
 * very different sources:
 *
 *   - fromInvoice(): a LOCAL invoice we filed — header + the lines WE
 *     billed, read from our own tables. Party display uses the issue-time
 *     SNAPSHOT columns (company_name / vat_no), never the live customer
 *     relation, so the view shows the document as it was filed.
 *   - fromAadeDoc(): an ORPHAN — a document AADE holds for our AFM with no
 *     local record (e-τιμολόγιο / other software). Flattened from the
 *     firebed RequestTransmittedDocs response (header + counterpart + the
 *     per-line <invoiceDetails> + summary).
 *
 * Both return the SAME array keys so one blade renders either. Plain
 * scalars/arrays only — these become Livewire public properties.
 *
 * fromAadeDoc() is also exactly the parse a future SalesOrphanImporter
 * (the income-side mirror of App\Services\MyData\ExpenseImporter) would
 * consume to create a local invoice from an orphan — kept here so the two
 * never drift.
 */
final class MarkDetail
{
    /**
     * Build the detail array from a LOCAL invoice. Expects the invoice
     * loaded with: customer (withTrashed), lines.product, invoiceType,
     * company.
     *
     * @return array<string, mixed>
     */
    public static function fromInvoice(Invoice $invoice): array
    {
        $type = $invoice->mydata_type;

        $lines = $invoice->lines->values()->map(function (InvoiceLine $line, int $i): array {
            $net = (float) $line->net_price;
            $gross = (float) $line->gross_price;

            return [
                'lineNumber' => $i + 1,
                'itemCode' => null,
                'itemDescr' => $line->product_descr,
                'quantity' => $line->qty !== null ? (float) $line->qty : null,
                'unit' => $line->metric_unit,
                'netValue' => $net,
                // Local lines store the rate, not the §8.2 category code.
                'vatPercent' => $line->vat_percent !== null ? (float) $line->vat_percent : null,
                'vatCategory' => null,
                'vatExemptionCategory' => null,
                'vatAmount' => round($gross - $net, 2),
            ];
        })->all();

        $net = (float) $invoice->net_total;
        $gross = (float) $invoice->gross_total;

        return [
            'mark' => (string) $invoice->mydata_mark,
            'uid' => null,
            'invoiceType' => $type,
            'invoiceTypeLabel' => $type !== null ? (Codes::INVOICE_TYPES[$type] ?? null) : null,
            'series' => $invoice->invoiceType?->code,
            'aa' => $invoice->code,
            'invcode' => $invoice->invcode,
            // ISO for machine use + a human form for the header.
            'issueDate' => $invoice->issued_at?->format('Y-m-d'),
            'issuedAtHuman' => $invoice->issued_at?->format('d/m/Y H:i'),
            'currency' => 'EUR',
            // Snapshot columns ONLY — the values as FILED (CLAUDE.md: never
            // join through the live customer for an audit view, or the name
            // could drift out of sync with the snapshot vat_no). Retail rows
            // legitimately have no company_name → '—' in the view.
            'counterpartName' => $invoice->company_name ?: null,
            'counterpartVat' => $invoice->vat_no,
            'issuerName' => $invoice->company?->name,
            'issuerVat' => $invoice->company?->afm,
            'netTotal' => $net,
            'vatTotal' => round($gross - $net, 2),
            'grossTotal' => $gross,
            'state' => $invoice->mydata_state ?? 'VALID',
            'localStatus' => $invoice->local_status,
            'lines' => $lines,
            // Audit XML is injected by the page from the mydata_marks row.
            'requestXml' => null,
            'responseXml' => null,
            'source' => 'local',
        ];
    }

    /**
     * Build the detail array from a firebed RequestTransmittedDocs
     * document (an orphan). `$cancelled` is the folded state the reader
     * derives from the inline <cancelledByMark> OR the standalone
     * <cancelledInvoicesDoc> list.
     *
     * @return array<string, mixed>
     */
    public static function fromAadeDoc(AadeInvoice $doc, bool $cancelled): array
    {
        $header = $doc->getInvoiceHeader();
        $summary = $doc->getInvoiceSummary();
        $counterpart = $doc->getCounterpart();
        $issuer = $doc->getIssuer();

        $type = $header?->getInvoiceType()?->value;
        $series = $header?->getSeries();
        $aa = $header?->getAa();
        $invcode = trim(((string) ($series ?? '')).' '.((string) ($aa ?? '')));

        return [
            'mark' => (string) $doc->getMark(),
            'uid' => $doc->getUid(),
            'invoiceType' => $type,
            'invoiceTypeLabel' => $type !== null ? (Codes::INVOICE_TYPES[$type] ?? null) : null,
            'series' => $series,
            'aa' => $aa,
            'invcode' => $invcode !== '' ? $invcode : null,
            'issueDate' => $header?->getIssueDate(),
            'issuedAtHuman' => self::humanDate($header?->getIssueDate()),
            'currency' => $header?->getCurrency() ?: 'EUR',
            'counterpartName' => $counterpart?->getName(),
            'counterpartVat' => $counterpart?->getVatNumber(),
            'issuerName' => $issuer instanceof Issuer ? $issuer->getName() : null,
            'issuerVat' => $issuer instanceof Issuer ? $issuer->getVatNumber() : null,
            'netTotal' => self::toFloat($summary?->getTotalNetValue()),
            'vatTotal' => self::toFloat($summary?->getTotalVatAmount()),
            'grossTotal' => self::toFloat($summary?->getTotalGrossValue()),
            'state' => $cancelled ? 'CANCELLED' : 'VALID',
            'localStatus' => null,
            'lines' => self::aadeLines($doc),
            // No request XML on the inbound side — only AADE's response doc.
            'requestXml' => null,
            'responseXml' => self::safeXml($doc),
            'source' => 'aade',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function aadeLines(AadeInvoice $doc): array
    {
        $details = $doc->getInvoiceDetails();
        if (! is_array($details)) {
            return [];
        }

        $lines = [];
        foreach ($details as $line) {
            $lines[] = [
                'lineNumber' => $line->getLineNumber(),
                'itemCode' => $line->getItemCode(),
                'itemDescr' => $line->getItemDescr(),
                'quantity' => $line->getQuantity() !== null ? (float) $line->getQuantity() : null,
                'unit' => $line->getMeasurementUnit()?->value,
                'netValue' => self::toFloat($line->getNetValue()),
                'vatPercent' => null,
                // §8.2 category code (firebed backed enum → int value).
                'vatCategory' => $line->getVatCategory()?->value,
                'vatExemptionCategory' => $line->getVatExemptionCategory()?->value,
                'vatAmount' => self::toFloat($line->getVatAmount()),
            ];
        }

        return $lines;
    }

    private static function safeXml(AadeInvoice $doc): ?string
    {
        try {
            return $doc->toXml();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function humanDate(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($iso)->format('d/m/Y');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private static function toFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
