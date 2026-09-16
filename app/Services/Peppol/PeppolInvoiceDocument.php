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
        // EN 16931 BR-DEC-*: monetary amounts are 2dp. The library defaults to 8dp
        // (and its validate() does NOT check this), which would serialise rejectable
        // values — so pin every monetary field to 2dp, EXCEPT the unit price (BT-146),
        // kept at 4dp so the folded header discount (net/qty) doesn't lose a cent.
        $ubl->setRoundingMatrix(['' => 2, 'line/price' => 4]);
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
     * Validate against the library's rule set — EN 16931 structural BRs + a
     * SUBSET of the PEPPOL rules (R002/R003/R061/BG-17). This is NOT a full
     * Schematron check: it does NOT verify BT-34/49 endpoint presence, BR-CO-*
     * total consistency, or the per-category VAT reason rules. A null result
     * means "passed the subset", not "the Access Point will accept it" — the
     * authoritative validation is the AP's (Phase 2).
     *
     * @return ?string  null when it passes, else "[RULE] message" of the first failure
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
        // Fallback country only when the tenant's country_code is somehow blank
        // (a misconfig — every real tenant has one). Default to the deployment's
        // primary country (GR) rather than a foreign default on a legal document.
        $party = (new Party)
            ->setName($company->name)
            ->setCountry($this->iso($company->country_code) ?? 'GR');

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
        // Fall back to the seller's country, then to the deployment's primary
        // country (GR) — never a foreign default — when the customer has no
        // country_code on file (a misconfig; every real customer has one).
        $country = $this->iso($customer->country) ?? $this->iso($sellerCountry) ?? 'GR';

        $party = (new Party)
            ->setName($customer->name)
            ->setCountry($country);

        $vatId = trim((string) ($customer->vat_vies ?: $customer->afm));
        if ($vatId !== '') {
            $party->setVatNumber($this->vatNumber($vatId, $customer->country ?: $sellerCountry));
            $party->setCompanyId(new Identifier($vatId));
        }
        $addressLines = array_values(array_filter([$customer->address1, $customer->address2]));
        if ($addressLines !== []) {
            $party->setAddress($addressLines);
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
            $this->iso($sellerCountry) ?? 'GR',
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

    /** Prefix a bare tax id with its VAT prefix (EL123…, EE123… ) if not already prefixed. */
    private function vatNumber(string $id, ?string $country): string
    {
        $id = strtoupper(trim($id));
        $prefix = $this->vatPrefix($country) ?? '';
        if ($prefix !== '' && ! preg_match('/^[A-Z]{2}/', $id)) {
            return $prefix.$id;
        }

        return $id;
    }

    /**
     * ISO 3166-1 alpha-2 country code (BT-40/BT-55, the <Country> fields):
     * Greece is 'GR'. Note this is NOT the VAT prefix — see vatPrefix().
     */
    private function iso(?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '') {
            return null;
        }

        return $c === 'EL' ? 'GR' : $c;
    }

    /**
     * VAT-identifier prefix (BT-31 seller / BT-48 buyer): equals the ISO 3166-1
     * code for every EU country EXCEPT Greece, which uses 'EL' not 'GR'
     * (EN 16931 BR-CO-9). So the <Country> field says GR while the VAT number
     * says EL800561849 — deliberately the mirror image of iso().
     */
    private function vatPrefix(?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        if ($c === '') {
            return null;
        }

        return ($c === 'GR' || $c === 'EL') ? 'EL' : $c;
    }
}
