<?php

namespace App\Services\EInvoice\Transports;

use App\Models\Invoice;
use DOMDocument;
use DOMElement;
use RuntimeException;

/**
 * Builds InvoSign's `xml_arxeio` payload. InvoSign is NOT a bespoke schema: it
 * accepts the FULL, unmodified AADE `InvoicesDoc` XML and merely APPENDS its own
 * extension (docs/paroxos/research/invosign-api-reference.md §0/§2). So we take the
 * canonical AADE XML (AadeInvoiceDocument::toXml — already validated against AADE)
 * and DOM-inject, per line, the `api_*` printout twins + an invoice-level
 * `<API_InvoiceDetails>` block (issuer / counterpart / additionals). The AADE core
 * is never touched — only added to.
 *
 * ⚠ The precise per-line price/discount semantics (NetPriceBeforeDiscount vs
 * UnitPrice vs DiscountValue) are best-effort from the documented sample and MUST
 * be confirmed against the InvoSign sandbox before go-live (P5 validation).
 */
class InvoSignDocument
{
    private const AADE_NS = 'http://www.aade.gr/myDATA/invoice/v1.0';

    public static function augment(string $aadeXml, Invoice $invoice): string
    {
        $invoice->loadMissing(['lines.product', 'invoiceType', 'customer', 'company', 'paymentMethod']);

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        if (! @$dom->loadXML($aadeXml)) {
            throw new RuntimeException('InvoSign: could not parse the AADE InvoicesDoc XML to augment.');
        }

        $invoiceNode = $dom->getElementsByTagNameNS(self::AADE_NS, 'invoice')->item(0);
        if (! $invoiceNode instanceof DOMElement) {
            throw new RuntimeException('InvoSign: <invoice> element not found in the AADE XML.');
        }

        // 1) Per-line api_* twins — matched to <invoiceDetails> in document order.
        $details = $invoiceNode->getElementsByTagNameNS(self::AADE_NS, 'invoiceDetails');
        $lines = $invoice->lines->values();
        for ($i = 0; $i < $details->length; $i++) {
            $node = $details->item($i);
            $line = $lines[$i] ?? null;
            if ($node instanceof DOMElement && $line !== null) {
                self::appendLineFields($dom, $node, $line);
            }
        }

        // 2) Invoice-level <API_InvoiceDetails> block, after <invoiceSummary>.
        $invoiceNode->appendChild(self::buildApiInvoiceDetails($dom, $invoice));

        return $dom->saveXML() ?: $aadeXml;
    }

    private static function appendLineFields(DOMDocument $dom, DOMElement $detail, $line): void
    {
        $qty = (float) $line->qty;
        $unit = (float) $line->price_per_item;
        $discountPct = (float) ($line->discount ?? 0);
        $unitAfter = round($unit * (1 - $discountPct / 100), 2);
        $gross = $unit * $qty;
        $net = (float) $line->net_price;
        $discountValue = round($gross - $net, 2);

        $fields = [
            'api_serial' => (string) ($line->product?->code ?? ''),
            'api_lineDescription' => (string) ($line->product_descr ?? ''),
            'api_NetPriceBeforeDiscount' => self::money($unit),
            'api_UnitPrice' => self::money($unitAfter),
            'api_DiscountValue' => self::money($discountValue),
            'api_vatCategoryPercent' => self::money((float) $line->vat_percent),
            'api_quantity' => rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.') ?: '0',
            'api_mm' => (string) ($line->metric_unit ?: 'Τμχ'),
        ];

        foreach ($fields as $name => $value) {
            $detail->appendChild(self::el($dom, $name, $value));
        }
    }

    private static function buildApiInvoiceDetails(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $company = $invoice->company;
        $customer = $invoice->customer;

        $wrap = $dom->createElementNS(self::AADE_NS, 'API_InvoiceDetails');

        $issuer = $dom->createElementNS(self::AADE_NS, 'API_Issuer');
        self::appendAll($dom, $issuer, [
            'IssuerName' => (string) ($company?->name ?? ''),
            'IssuerProfession' => (string) ($company?->kad_primary ?? ''),
            'IssuerTaxOffice' => (string) ($company?->tax_office ?? ''),
            'IssuerAddressStreet' => (string) ($company?->address ?? ''),
            'IssuerAddressPostalCode' => (string) ($company?->postcode ?? ''),
            'IssuerAddressCity' => (string) ($company?->city ?? ''),
            'IssuerPhone' => (string) ($company?->phone ?? ''),
            'IssuerEmail' => (string) ($company?->email ?? ''),
        ]);
        $wrap->appendChild($issuer);

        $cp = $dom->createElementNS(self::AADE_NS, 'API_Counterpart');
        self::appendAll($dom, $cp, [
            'CounterpartName' => (string) ($invoice->company_name ?: $customer?->name ?? ''),
            'CounterpartVat' => (string) ($invoice->vat_no ?: $customer?->afm ?? ''),
            'CounterpartProfession' => (string) ($invoice->occupation ?: $customer?->occupation ?? ''),
            'CounterpartTaxOffice' => (string) ($customer?->tax_office ?? ''),
            'CounterpartAddressStreet' => (string) ($invoice->address1 ?: $customer?->address1 ?? ''),
            'CounterpartAddressPostalCode' => (string) ($invoice->postcode ?: $customer?->postcode ?? ''),
            'CounterpartAddressCity' => (string) ($invoice->city ?: $customer?->city ?? ''),
            'CounterpartPhone' => (string) ($customer?->phone ?? ''),
            'CounterpartEmail' => (string) ($customer?->email ?? ''),
        ]);
        $wrap->appendChild($cp);

        $add = $dom->createElementNS(self::AADE_NS, 'API_Additionals');
        self::appendAll($dom, $add, [
            'DocumentLabel' => (string) ($invoice->invoiceType?->name ?? ''),
            'DocumentComments' => (string) ($invoice->notes ?? ''),
            'DocumentPaymentMethodLabel' => (string) ($invoice->paymentMethod?->description ?? ''),
        ]);
        $wrap->appendChild($add);

        return $wrap;
    }

    /** @param  array<string, string>  $fields */
    private static function appendAll(DOMDocument $dom, DOMElement $parent, array $fields): void
    {
        foreach ($fields as $name => $value) {
            $parent->appendChild(self::el($dom, $name, $value));
        }
    }

    private static function el(DOMDocument $dom, string $name, string $value): DOMElement
    {
        $el = $dom->createElementNS(self::AADE_NS, $name);
        $el->textContent = $value; // textContent escapes &, <, > correctly

        return $el;
    }

    private static function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
