<?php

namespace App\Support\MyData;

use App\Models\Invoice;
use App\Models\InvoiceLine;
use Carbon\Carbon;
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

        // MYD-5: the (E3 class, category) EACH line files — resolved through the
        // same IncomeClassResolver the filing path uses, so the «Έλεγχος ΜΑΡΚ»
        // detail shows exactly what was sent. Base pair once (a credit note's is a
        // query), then per line; local lines keep their product description AND
        // gain the classification as a discreet sub-line (not instead of it).
        $resolver = app(IncomeClassResolver::class);
        [$baseClass, $baseCat] = $resolver->baseFor($invoice);
        $businessType = $invoice->company?->business_activity_type;

        $lines = $invoice->lines->values()->map(function (InvoiceLine $line, int $i) use ($resolver, $baseClass, $baseCat, $businessType): array {
            $net = (float) $line->net_price;
            $gross = (float) $line->gross_price;

            [$class, $cat] = $resolver->forLine($line, $baseClass, $baseCat, $businessType);
            // Mirror the filing gate (AadeInvoiceDocument: `if ($lineClass && $lineCat)`)
            // exactly — an empty-string class/cat files NOTHING, so it must show
            // nothing too, or "what you see" would diverge from "what is filed".
            $classifications = (filled($class) && filled($cat))
                ? [[
                    'type' => $class,
                    'typeLabel' => Codes::e3TypeLabel($class),
                    'category' => $cat,
                    'categoryLabel' => Codes::e3CategoryLabel($cat),
                    'amount' => round($net, 2),
                ]]
                : [];

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
                'classifications' => $classifications,
            ];
        })->all();

        $net = (float) $invoice->net_total;
        $gross = (float) $invoice->gross_total;

        return [
            'mark' => (string) $invoice->mydata_mark,
            'uid' => null,
            'invoiceType' => $type,
            'invoiceTypeLabel' => $type !== null ? (Codes::INVOICE_TYPES[$type] ?? null) : null,
            // MYD-018: as FILED, not as the lookup reads today — same rule as the
            // counterpart snapshot columns below.
            'series' => $invoice->filedSeries(),
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
            // Only the AADE-sourced view carries one; kept here so both builders
            // return the same array shape.
            'cancelledByMark' => null,
            'localStatus' => $invoice->local_status,
            // We always issue our own invoices → outbound (we are the issuer).
            'direction' => 'outbound',
            // The stored QR URL (null for imports that never had one → the
            // «Άντληση από ΑΑΔΕ» action backfills it).
            'qrCodeUrl' => $invoice->mydata_url,
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
     * <cancelledInvoicesDoc> list. `$cancelledByMark` is the MARK of that
     * cancellation ACT when AADE named one — the evidence a state sync persists
     * (MYD-023); it can be null even when `$cancelled` is true.
     *
     * @return array<string, mixed>
     */
    public static function fromAadeDoc(AadeInvoice $doc, bool $cancelled, ?string $ourVat = null, ?string $cancelledByMark = null): array
    {
        $header = $doc->getInvoiceHeader();
        $summary = $doc->getInvoiceSummary();
        $counterpart = $doc->getCounterpart();
        $issuer = $doc->getIssuer();

        $type = $header?->getInvoiceType()?->value;
        $series = $header?->getSeries();
        $aa = $header?->getAa();
        $invcode = trim(((string) ($series ?? '')).' '.((string) ($aa ?? '')));

        $issuerVat = $issuer instanceof Issuer ? $issuer->getVatNumber() : null;
        $counterVat = $counterpart?->getVatNumber();

        // Which side are WE on? RequestTransmittedDocs returns both docs we
        // issued (outbound) and docs others issued to us (inbound expenses).
        // Compare against our own ΑΦΜ so the view can label issuer/counterpart
        // correctly instead of always assuming "Εκδότης (εμείς)".
        $direction = 'unknown';
        if ($ourVat !== null && $ourVat !== '') {
            if ($issuerVat !== null && $issuerVat === $ourVat) {
                $direction = 'outbound';
            } elseif ($counterVat !== null && $counterVat === $ourVat) {
                $direction = 'inbound';
            }
        }
        // No <issuer> at all + we're the counterpart = an inbound retail (ΑΛΠ
        // 13.1) receipt: myDATA doesn't carry who sold to us.
        if ($direction === 'unknown' && $issuerVat === null && $counterVat !== null) {
            $direction = 'inbound';
        }

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
            'counterpartVat' => $counterVat,
            // AADE sends a foreign VAT without its country prefix — the country
            // completes the identity (orphan import/link match the customer by it).
            'counterpartCountry' => $counterpart?->getCountry(),
            'issuerName' => $issuer instanceof Issuer ? $issuer->getName() : null,
            'issuerVat' => $issuerVat,
            'netTotal' => self::toFloat($summary?->getTotalNetValue()),
            'vatTotal' => self::toFloat($summary?->getTotalVatAmount()),
            'grossTotal' => self::toFloat($summary?->getTotalGrossValue()),
            'state' => $cancelled ? 'CANCELLED' : 'VALID',
            // AADE's MARK for the cancellation act, when it named one.
            'cancelledByMark' => $cancelled ? $cancelledByMark : null,
            'localStatus' => null,
            'direction' => $direction,
            // The AADE QR URL — present on RequestTransmittedDocs/RequestDocs
            // responses (spec §qrCodeUrl). Lets the page show/print the QR and
            // EnrichInvoiceFromAade stamp it onto a QR-less imported invoice.
            'qrCodeUrl' => $doc->getQrCodeUrl(),
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
                // myDATA never carries a free-text line description for these
                // docs, but it DOES carry the E3 classification — that's the
                // "what is this" signal. Surface it so an orphan reads as e.g.
                // "E3_585_010 — Λήψη υπηρεσιών" instead of a blank "—".
                'classifications' => self::lineClassifications($line),
            ];
        }

        return $lines;
    }

    /**
     * Flatten a line's income OR expense classifications into display rows.
     * AADE returns one or the other depending on whether the doc is a sale or
     * an expense; we read both and label via Codes.
     *
     * @return list<array{type: string, typeLabel: ?string, category: ?string, categoryLabel: ?string, amount: ?float}>
     */
    private static function lineClassifications(object $line): array
    {
        $out = [];

        $income = method_exists($line, 'getIncomeClassification') ? $line->getIncomeClassification() : null;
        $expense = method_exists($line, 'getExpensesClassification') ? $line->getExpensesClassification() : null;

        foreach ([$income, $expense] as $set) {
            if (! is_array($set)) {
                continue;
            }
            foreach ($set as $c) {
                $type = $c->getClassificationType()?->value;
                if ($type === null) {
                    continue;
                }
                $category = $c->getClassificationCategory()?->value;
                $out[] = [
                    'type' => $type,
                    'typeLabel' => Codes::e3TypeLabel($type),
                    'category' => $category,
                    'categoryLabel' => $category !== null ? Codes::e3CategoryLabel($category) : null,
                    'amount' => self::toFloat($c->getAmount()),
                ];
            }
        }

        return $out;
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
            return Carbon::parse($iso)->format('d/m/Y');
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
