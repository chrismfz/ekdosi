<?php

namespace App\Services\Peppol;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Support\Peppol\PeppolEndpoint;
use App\Support\Peppol\PeppolVatCategory;
use Einvoicing\Identifier;
use Einvoicing\Invoice as UblInvoice;
use Einvoicing\InvoiceLine as UblLine;
use Einvoicing\Party;
use Einvoicing\Presets\Peppol;
use Einvoicing\Writers\UblWriter;

/**
 * Maps a local Invoice → a PEPPOL BIS Billing 3.0 (EN 16931) UBL document via
 * josemmo/einvoicing — the PEPPOL-side twin of MyDataSubmitter::buildAadeInvoice.
 * We own ONLY the mapping; the library owns the UBL syntax + EN 16931 rules.
 *
 * Provider-independent: the same UBL is accepted by every Estonian Access Point
 * (Telema/Finbite/Unifiedpost…) and the free RIK tool, because Estonia applies
 * no national CIUS beyond EN 16931. The Access-Point transport (the actual send)
 * is Phase 2; this class builds + validates the document.
 *
 * Header discount is folded into the per-line net unit price (the amounts stay
 * correct and EN-16931-valid; it just isn't shown as a separate BG-20 allowance —
 * a follow-up). Quantities default to UN/ECE C62 (one/piece) until a unit map lands.
 */
class PeppolInvoiceDocument
{
    /** Build the library Invoice (not yet serialised). */
    public function build(Invoice $invoice): UblInvoice
    {
        $invoice->loadMissing(['company', 'customer', 'lines']);
        $company = $invoice->company;
        $customer = $invoice->customer;

        if (! $company instanceof Company) {
            throw new \RuntimeException("Invoice {$invoice->invcode}: missing company (seller).");
        }
        if (! $customer instanceof Customer) {
            throw new \RuntimeException("Invoice {$invoice->invcode}: missing customer (buyer).");
        }

        $ubl = new UblInvoice(Peppol::class);
        $ubl->setNumber((string) ($invoice->invcode ?: $invoice->code ?: $invoice->id))
            ->setType($invoice->credited_invoice_id !== null ? 381 : 380) // 381 credit note / 380 invoice
            ->setCurrency('EUR')
            ->setIssueDate($invoice->issued_at?->toDateTime() ?? now()->toDateTime())
            // BT-10/BT-13: PEPPOL R003 demands a buyer or order reference; the
            // document code is the natural, always-present value.
            ->setBuyerReference((string) ($invoice->invcode ?: $invoice->id));

        $ubl->setSeller($this->seller($company));
        $ubl->setBuyer($this->buyer($customer, $company->country_code));

        $factor = $this->headerDiscountFactor($invoice);
        foreach ($invoice->lines as $line) {
            $ubl->addLine($this->line($line, $company->country_code, $customer, $factor));
        }

        return $ubl;
    }

    /** Serialise to PEPPOL BIS 3.0 UBL XML. */
    public function xml(Invoice $invoice): string
    {
        return (new UblWriter)->export($this->build($invoice));
    }

    /**
     * Validate against EN 16931 + the library's PEPPOL rules.
     *
     * @return ?string  null when valid, else "[RULE] message" of the first failure
     */
    public function validate(Invoice $invoice): ?string
    {
        try {
            $this->build($invoice)->validate();

            return null;
        } catch (\Einvoicing\Exceptions\ValidationException $e) {
            return '['.$e->getKey().'] '.$e->getMessage();
        }
    }

    private function seller(Company $company): Party
    {
        $party = (new Party)
            ->setName($company->name)
            ->setCountry($this->iso($company->country_code) ?? 'EE');

        if (filled($company->afm)) {
            $vat = $this->vatNumber($company->afm, $company->country_code);
            $party->setVatNumber($vat);
            $party->setCompanyId(new Identifier((string) $company->afm));
        }
        if (filled($company->address)) {
            $party->setAddress([(string) $company->address]);
        }
        $party->setCity($company->city ?: null);
        $party->setPostalCode($company->postcode ?: null);

        if (($endpoint = PeppolEndpoint::forSeller($company)) !== null) {
            $party->setElectronicAddress($endpoint);
        }

        return $party;
    }

    private function buyer(Customer $customer, ?string $sellerCountry): Party
    {
        $country = $this->iso($customer->country) ?? $this->iso($sellerCountry) ?? 'EE';

        $party = (new Party)
            ->setName($customer->name)
            ->setCountry($country);

        $vatId = trim((string) ($customer->vat_vies ?: $customer->afm));
        if ($vatId !== '') {
            $party->setVatNumber($this->vatNumber($vatId, $customer->country ?: $sellerCountry));
            $party->setCompanyId(new Identifier($vatId));
        }
        $party->setCity($customer->city ?: null);
        $party->setPostalCode($customer->postcode ?: null);

        if (($endpoint = PeppolEndpoint::forBuyer($customer)) !== null) {
            $party->setElectronicAddress($endpoint);
        }

        return $party;
    }

    private function line(InvoiceLine $line, ?string $sellerCountry, Customer $customer, float $headerFactor): UblLine
    {
        $qty = (float) $line->qty ?: 1.0;
        $net = (float) $line->net_price * $headerFactor;
        $unitNet = $qty != 0.0 ? $net / $qty : 0.0;

        $buyerHasVat = trim((string) ($customer->vat_vies ?: $customer->afm)) !== '';
        $vat = PeppolVatCategory::resolve(
            (float) $line->vat_percent,
            $this->iso($sellerCountry) ?? 'EE',
            $customer->country,
            $buyerHasVat,
        );

        $ublLine = (new UblLine)
            ->setName($line->product_descr ?: 'Item')
            ->setQuantity($qty)
            ->setUnit('C62')                 // UN/ECE Rec 20 — one/piece (default)
            ->setPrice(round($unitNet, 4))
            ->setVatCategory($vat['category'])
            ->setVatRate($vat['rate']);

        if ($vat['exemptionReasonCode'] !== null) {
            $ublLine->setVatExemptionReasonCode($vat['exemptionReasonCode']);
            $ublLine->setVatExemptionReason($vat['exemptionReason']);
        }

        return $ublLine;
    }

    /** Header discount as a multiplicative factor folded into line prices. */
    private function headerDiscountFactor(Invoice $invoice): float
    {
        $hd = (float) $invoice->header_discount_percent;

        return $hd > 0 && $hd < 100 ? 1 - ($hd / 100) : 1.0;
    }

    /** Prefix a bare tax id with its ISO country code (EE123… ) if not already prefixed. */
    private function vatNumber(string $id, ?string $country): string
    {
        $id = strtoupper(trim($id));
        $iso = $this->iso($country) ?? '';
        if ($iso !== '' && ! preg_match('/^[A-Z]{2}/', $id)) {
            return $iso.$id;
        }

        return $id;
    }

    private function iso(?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '') {
            return null;
        }

        return $c === 'EL' ? 'GR' : $c;
    }
}
