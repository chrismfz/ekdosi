<?php

namespace App\Services\EInvoice\Transports;

use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Support\MyData\DeliveryCodes;
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
        if (! @$dom->loadXML($aadeXml, LIBXML_NONET)) {
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
        $invoiceNode->appendChild(self::buildApiInvoiceDetails(
            $dom,
            self::issuerFields($invoice->company),
            self::invoiceCounterpartFields($invoice),
            [
                'DocumentLabel' => (string) ($invoice->invoiceType?->name ?? ''),
                'DocumentComments' => (string) ($invoice->notes ?? ''),
                'DocumentPaymentMethodLabel' => (string) ($invoice->paymentMethod?->description ?? ''),
            ],
        ));

        return self::normaliseClassificationPrefixes($dom->saveXML() ?: $aadeXml);
    }

    /**
     * Delivery-note twin of augment(): InvoSign treats the invoice-level
     * <API_InvoiceDetails> (issuer + counterpart) as MANDATORY even for 9.x
     * delivery notes and rejects its absence with "[88-006] Λείπει το
     * υποχρεωτικό node: API_InvoiceDetails". So we append THAT block — built from
     * the DeliveryNote (recipient as counterpart) — but NOT the per-line api_*
     * printout twins, which delivery notes don't carry. (Whether InvoSign also
     * wants per-line api_* for goods 9.x is to be confirmed on the sandbox.)
     */
    public static function augmentDelivery(string $aadeXml, DeliveryNote $note): string
    {
        $note->loadMissing(['company', 'customer', 'deliveryType']);

        $dom = new DOMDocument('1.0', 'utf-8');
        $dom->preserveWhiteSpace = false;
        if (! @$dom->loadXML($aadeXml, LIBXML_NONET)) {
            throw new RuntimeException('InvoSign: could not parse the AADE delivery-note XML to augment.');
        }

        $invoiceNode = $dom->getElementsByTagNameNS(self::AADE_NS, 'invoice')->item(0);
        if (! $invoiceNode instanceof DOMElement) {
            throw new RuntimeException('InvoSign: <invoice> element not found in the delivery-note XML.');
        }

        $invoiceNode->appendChild(self::buildApiInvoiceDetails(
            $dom,
            self::issuerFields($note->company),
            self::deliveryCounterpartFields($note),
            // Field order + names mirror InvoSign's own delivery-note example
            // (incl. the `DocumentMovePursposeLabel` typo, which IS their schema
            // field name, and DispatchFrom/To = the loading/delivery addresses).
            [
                'DocumentLabel' => (string) ($note->deliveryType?->name ?? ''),
                'DocumentMovePursposeLabel' => (string) (DeliveryCodes::movePurposeLabel($note->move_purpose) ?? ''),
                'DocumentDispatchFrom' => self::addressLine($note->loading_street, $note->loading_number, $note->loading_city, $note->loading_postcode),
                'DocumentDispatchTo' => self::addressLine($note->delivery_street, $note->delivery_number, $note->delivery_city, $note->delivery_postcode),
                'DocumentComments' => (string) ($note->notes ?? ''),
                'DocumentPaymentMethodLabel' => '',
            ],
        ));

        return self::normaliseClassificationPrefixes($dom->saveXML() ?: $aadeXml);
    }

    /** "street number, city, postcode" — InvoSign's DocumentDispatchFrom/To shape, empties dropped. */
    private static function addressLine(?string $street, ?string $number, ?string $city, ?string $postcode): string
    {
        $streetPart = trim((string) $street.' '.(string) $number);

        return implode(', ', array_filter([$streetPart, (string) $city, (string) $postcode], static fn ($p) => trim((string) $p) !== ''));
    }

    /**
     * InvoSign's parser is namespace-PREFIX-strict: it requires the income/expense
     * classification namespaces to use the prefixes n1/n2 (as in its API sample),
     * whereas firebed's InvoicesDocWriter emits icls/ecls. AADE itself matches by
     * URI (so the DIRECT myDATA path via firebed is unaffected), but InvoSign
     * rejects with "[88-004] Missing or wrong xmlns:n1". Rename the prefixes
     * (the namespace URIs are unchanged) for the InvoSign payload only. icls/ecls
     * are distinctive tokens that appear ONLY as the xmlns declaration + element
     * prefixes (never in values), so a string rename is safe.
     */
    private static function normaliseClassificationPrefixes(string $xml): string
    {
        return strtr($xml, [
            'xmlns:icls=' => 'xmlns:n1=',
            'xmlns:ecls=' => 'xmlns:n2=',
            '<icls:' => '<n1:',
            '</icls:' => '</n1:',
            '<ecls:' => '<n2:',
            '</ecls:' => '</n2:',
        ]);
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
            // 4 decimals to match InvoSign's documented sample (<api_quantity>1.0000).
            'api_quantity' => number_format($qty, 4, '.', ''),
            'api_mm' => (string) ($line->metric_unit ?: 'Τμχ'),
        ];

        foreach ($fields as $name => $value) {
            $detail->appendChild(self::el($dom, $name, $value));
        }
    }

    /**
     * Build the shared <API_InvoiceDetails> wrapper (API_Issuer / API_Counterpart
     * / API_Additionals) from already-prepared field maps — so the invoice and
     * delivery-note paths emit an IDENTICAL block shape and only differ in how the
     * field maps are sourced.
     *
     * @param  array<string, string>  $issuer
     * @param  array<string, string>  $counterpart
     * @param  array<string, string>  $additionals
     */
    private static function buildApiInvoiceDetails(DOMDocument $dom, array $issuer, array $counterpart, array $additionals): DOMElement
    {
        $wrap = $dom->createElementNS(self::AADE_NS, 'API_InvoiceDetails');

        $issuerEl = $dom->createElementNS(self::AADE_NS, 'API_Issuer');
        self::appendAll($dom, $issuerEl, $issuer);
        $wrap->appendChild($issuerEl);

        $cpEl = $dom->createElementNS(self::AADE_NS, 'API_Counterpart');
        self::appendAll($dom, $cpEl, $counterpart);
        $wrap->appendChild($cpEl);

        $addEl = $dom->createElementNS(self::AADE_NS, 'API_Additionals');
        self::appendAll($dom, $addEl, $additionals);
        $wrap->appendChild($addEl);

        return $wrap;
    }

    /** @return array<string, string> */
    private static function issuerFields(?Company $company): array
    {
        return [
            'IssuerName' => (string) ($company?->name ?? ''),
            'IssuerProfession' => (string) ($company?->kad_primary ?? ''),
            'IssuerTaxOffice' => (string) ($company?->tax_office ?? ''),
            'IssuerAddressStreet' => (string) ($company?->address ?? ''),
            'IssuerAddressPostalCode' => (string) ($company?->postcode ?? ''),
            'IssuerAddressCity' => (string) ($company?->city ?? ''),
            'IssuerPhone' => (string) ($company?->phone ?? ''),
            'IssuerEmail' => (string) ($company?->email ?? ''),
        ];
    }

    /** @return array<string, string> */
    private static function invoiceCounterpartFields(Invoice $invoice): array
    {
        $customer = $invoice->customer;

        return [
            'CounterpartName' => (string) ($invoice->company_name ?: $customer?->name ?? ''),
            'CounterpartVat' => (string) ($invoice->vat_no ?: $customer?->afm ?? ''),
            'CounterpartProfession' => (string) ($invoice->occupation ?: $customer?->occupation ?? ''),
            'CounterpartTaxOffice' => (string) ($customer?->tax_office ?? ''),
            'CounterpartAddressStreet' => (string) ($invoice->address1 ?: $customer?->address1 ?? ''),
            'CounterpartAddressPostalCode' => (string) ($invoice->postcode ?: $customer?->postcode ?? ''),
            'CounterpartAddressCity' => (string) ($invoice->city ?: $customer?->city ?? ''),
            'CounterpartPhone' => (string) ($customer?->phone ?? ''),
            'CounterpartEmail' => (string) ($customer?->email ?? ''),
        ];
    }

    /** @return array<string, string> */
    private static function deliveryCounterpartFields(DeliveryNote $note): array
    {
        $customer = $note->customer;

        return [
            'CounterpartName' => (string) ($note->recipient_name ?: $customer?->name ?? ''),
            'CounterpartVat' => (string) ($note->recipient_afm ?: $customer?->afm ?? ''),
            'CounterpartProfession' => (string) ($customer?->occupation ?? ''),
            'CounterpartTaxOffice' => (string) ($customer?->tax_office ?? ''),
            'CounterpartAddressStreet' => (string) ($note->delivery_street ?: $customer?->address1 ?? ''),
            'CounterpartAddressPostalCode' => (string) ($note->delivery_postcode ?: $customer?->postcode ?? ''),
            'CounterpartAddressCity' => (string) ($note->delivery_city ?: $customer?->city ?? ''),
            'CounterpartPhone' => (string) ($customer?->phone ?? ''),
            'CounterpartEmail' => (string) ($customer?->email ?? ''),
        ];
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
