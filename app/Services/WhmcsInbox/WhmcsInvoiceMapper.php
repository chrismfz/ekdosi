<?php

namespace App\Services\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use InvalidArgumentException;

/**
 * Maps a WHMCS GetInvoice payload (as stashed on a pending_whmcs_invoices
 * row) into the data shape ekdosi needs to create an Invoice + InvoiceLines.
 *
 * Pure logic - no DB writes, no AADE calls. Stage B-2's filer uses this
 * to build the preview AND the actual persisted invoice from the same
 * source of truth (so what the operator sees in the modal is exactly
 * what gets filed).
 *
 * VAT inference: WHMCS doesn't carry per-line VAT rates the way ekdosi
 * does. We default every line to the tenant's default VatCategory (the
 * one with is_default=true) UNLESS the invoice type has an explicit
 * default VAT rate. Operator can override per-line in the modal (future
 * iteration); v1 of this mapper picks the default and surfaces it.
 *
 * Amount semantics: WHMCS line `amount` is either NET or GROSS depending
 * on the WHMCS instance's "Tax Inclusive" setting per the legacy
 * config. For the typical Greek myDATA setup the amount is the GROSS
 * (includes VAT) - we back-compute the net using the line's VAT rate.
 * If the tenant runs WHMCS in tax-exclusive mode, the configured
 * `whmcs_amount_includes_tax` flag on companies should be flipped (NOT
 * implemented yet - tracked as a deferral until a tenant actually
 * configures the alternative).
 */
class WhmcsInvoiceMapper
{
    /**
     * Build the data shape for the would-be ekdosi invoice WITHOUT
     * persisting anything. Used by the preview modal AND the filer.
     *
     * Returned shape:
     * [
     *   'header' => [
     *     'company_id'     => int,
     *     'customer_id'    => int,
     *     'invoice_type_id'=> int,
     *     'issued_at'      => string Y-m-d H:i:s,
     *     'payment_method_id' => ?int,
     *     // Customer snapshot fields - frozen at issue time:
     *     'company_name'   => string,
     *     'vat_no'         => string,
     *     'address1'       => string,
     *     'city'           => string,
     *     'postcode'       => string,
     *     'country'        => string,
     *     'occupation'     => string,
     *     'email'          => string,
     *     'notes'          => string,
     *   ],
     *   'lines' => [
     *     [
     *       'product_id'      => null,
     *       'description'     => string,
     *       'qty'             => float,
     *       'unit_price'      => float (net per unit),
     *       'vat_category_id' => int,
     *       'vat_percent'     => float,
     *       'line_net'        => float,
     *       'line_gross'      => float,
     *     ],
     *     ...
     *   ],
     *   'totals' => [
     *     'net_total'   => float,
     *     'vat_total'   => float,
     *     'gross_total' => float,
     *     'vat_breakdown' => [['rate' => float, 'net' => float, 'vat' => float, 'gross' => float], ...],
     *   ],
     *   'source' => [
     *     'whmcs_invoice_id' => int,
     *     'whmcs_userid'     => int,
     *     'whmcs_date'       => string,
     *     'whmcs_total'      => float,
     *   ],
     * ]
     */
    public function map(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        Customer $customer,
        InvoiceType $invoiceType,
        ?array $onlyWhmcsItemIds = null,
    ): array {
        if ($customer->company_id !== $tenant->id) {
            throw new InvalidArgumentException('Customer belongs to a different tenant.');
        }
        if ($invoiceType->company_id !== $tenant->id) {
            throw new InvalidArgumentException('Invoice type belongs to a different tenant.');
        }

        $payload = $pending->payload ?? [];
        // T-1c: when splitting a multi-party WHMCS invoice, map only the line
        // items belonging to one billing party. Null = the whole invoice
        // (the standard single-invoice path).
        $linePayload = $onlyWhmcsItemIds === null
            ? $payload
            : $this->filterPayloadItems($payload, $onlyWhmcsItemIds);
        $defaultVat = $this->resolveDefaultVatCategory($tenant);

        // G3 / payload-authoritative: whether WHMCS line `amount` is gross
        // (VAT-inclusive, the Greek norm) or net (tax-exclusive). Prefer to
        // DETECT it from the invoice's own tax breakdown — the WHMCS payload
        // carries subtotal/tax/taxrate/total + per-line `taxed`, so we don't
        // have to guess. Only fall back to the per-tenant toggle when the
        // payload has no usable breakdown. detectAmountIncludesTax returns
        // null when it can't tell.
        $detected = $this->detectAmountIncludesTax($linePayload);
        $amountIncludesTax = $detected
            ?? (bool) ($tenant->whmcs_amount_includes_tax ?? true);

        $lines = $this->buildLines($linePayload, $defaultVat, $amountIncludesTax);
        $totals = $this->computeTotals($lines);

        return [
            'header' => [
                'company_id' => $tenant->id,
                'customer_id' => $customer->id,
                'invoice_type_id' => $invoiceType->id,
                'issued_at' => now()->format('Y-m-d H:i:s'),
                'payment_method_id' => $invoiceType->payment_method_id,
                // Snapshot: frozen at issue time per Greek legal-invoice
                // requirements (the invoice must record the customer's
                // identity AS IT WAS when filed, not as it might be later).
                // Customer-snapshot fields — frozen at issue time per
                // Greek legal-invoice requirements. Field set must
                // match InvoiceForm.php's afterStateUpdated('customer_id')
                // EXACTLY, otherwise WHMCS-bridged invoices systematically
                // miss fields that manually-issued invoices for the same
                // customer capture (address2 + vies_vat — relevant for
                // VIES intra-community filings).
                'company_name' => (string) $customer->name,
                'vat_no' => (string) ($customer->afm ?? ''),
                // Source column on Customer is vat_vies; snapshot
                // column on Invoice is vies_vat (legacy naming
                // mismatch carried through from the Firebird ETL,
                // documented in CLAUDE.md schema notes).
                'vies_vat' => (string) ($customer->vat_vies ?? ''),
                'address1' => (string) ($customer->address1 ?? ''),
                'address2' => (string) ($customer->address2 ?? ''),
                'city' => (string) ($customer->city ?? ''),
                'postcode' => (string) ($customer->postcode ?? ''),
                'country' => (string) ($customer->country ?? 'GR'),
                'occupation' => (string) ($customer->occupation ?? ''),
                'email' => (string) ($customer->email ?? ''),
                'notes' => 'Από WHMCS #'.($payload['invoiceid'] ?? $payload['id'] ?? '?'),
            ],
            'lines' => $lines,
            'totals' => $totals,
            'source' => [
                'whmcs_invoice_id' => (int) ($payload['invoiceid'] ?? $payload['id'] ?? 0),
                'whmcs_userid' => (int) ($payload['userid'] ?? 0),
                'whmcs_date' => (string) ($payload['date'] ?? ''),
                'whmcs_total' => (float) ($payload['total'] ?? 0.0),
            ],
        ];
    }

    /**
     * T-1c: return a copy of the payload whose items are restricted to the
     * given WHMCS line-item ids (tblinvoiceitems.id, == resolve.php item_id).
     * Normalises the single-item shape first so filtering is uniform.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $onlyWhmcsItemIds
     * @return array<string, mixed>
     */
    private function filterPayloadItems(array $payload, array $onlyWhmcsItemIds): array
    {
        $items = $payload['items']['item'] ?? [];
        if (! empty($items) && ! array_is_list($items)) {
            $items = [$items];
        }

        $allow = array_flip(array_map('intval', $onlyWhmcsItemIds));
        $filtered = array_values(array_filter(
            $items,
            static fn ($item) => isset($allow[(int) ($item['id'] ?? 0)]),
        ));

        $payload['items']['item'] = $filtered;

        return $payload;
    }

    private function resolveDefaultVatCategory(Company $tenant): VatCategory
    {
        $default = VatCategory::query()
            ->where('company_id', $tenant->id)
            ->where('is_default', true)
            ->first();

        if (! $default) {
            throw new InvalidArgumentException(
                'Tenant has no default VAT category set. Configure one in Setup → VAT Categories before filing WHMCS invoices.'
            );
        }

        return $default;
    }

    /**
     * Detect from the WHMCS payload whether line `amount`s are tax-EXCLUSIVE
     * (net) or tax-INCLUSIVE (gross), instead of guessing per tenant. The
     * GetInvoice payload carries the authoritative breakdown:
     *   subtotal (sum of line amounts), tax, taxrate, total.
     *
     * Decision (only when tax > 0 and there is a positive taxrate — i.e. the
     * invoice actually charges VAT):
     *   - subtotal ≈ Σ(taxed line amounts)  AND  subtotal + tax ≈ total
     *       → the line amounts are NET, WHMCS added tax on top → return FALSE.
     *   - subtotal ≈ total (tax already inside)
     *       → line amounts are GROSS → return TRUE.
     * Returns null when the payload has no usable breakdown (no tax / missing
     * fields / inconsistent) — caller falls back to the tenant toggle.
     *
     * @param  array<string, mixed>  $payload
     */
    private function detectAmountIncludesTax(array $payload): ?bool
    {
        $taxRate = (float) ($payload['taxrate'] ?? 0);
        $tax = (float) ($payload['tax'] ?? 0);
        $subtotal = isset($payload['subtotal']) ? (float) $payload['subtotal'] : null;
        $total = isset($payload['total']) ? (float) $payload['total'] : null;

        // No VAT on this invoice, or breakdown missing → can't tell.
        if ($taxRate <= 0.0 || $tax <= 0.005 || $subtotal === null || $total === null) {
            return null;
        }

        $eps = 0.02;   // rounding tolerance between the two systems

        // Net amounts: WHMCS adds tax on top, so subtotal + tax == total and
        // subtotal is the pre-tax base.
        if (abs(($subtotal + $tax) - $total) <= $eps) {
            return false;
        }

        // Gross amounts: the tax is already inside subtotal, so subtotal ==
        // total (tax is informational).
        if (abs($subtotal - $total) <= $eps) {
            return true;
        }

        // Inconsistent / unrecognised shape → defer to the tenant toggle.
        return null;
    }

    /**
     * Convert WHMCS items to ekdosi InvoiceLine[]-ready arrays.
     * WHMCS shape: items[] each with {description, amount, taxed, ...}
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(array $payload, VatCategory $defaultVat, bool $amountIncludesTax = true): array
    {
        $items = $payload['items']['item'] ?? [];
        // Normalise single-item shape (WHMCS returns object not array
        // when count == 1).
        if (! empty($items) && ! array_is_list($items)) {
            $items = [$items];
        }

        if (empty($items)) {
            return [];
        }

        $vatPercent = (float) $defaultVat->rate;
        // 0%-VAT lines (WHMCS taxed=0) MUST reference an actual
        // 0%-rate VatCategory. The earlier fallback-to-default path
        // silently misclassified tax-exempt lines under the tenant's
        // default 24% category — every downstream consumer that
        // groups by vat_category_id (Καρτέλα per-category reports,
        // future PEPPOL submitter, accountant CSV exports, the
        // companion vat_breakdown the operator sees in the modal)
        // then double-counted the amount as standard-rate. The
        // mapper now refuses to map untaxed lines without a
        // configured 0%-rate row; operator-actionable resolution
        // is "configure a 0%-rate category in Setup → VAT
        // Categories before re-filing". This throws even on
        // Off-mode tenants because the misclassification damage is
        // identical regardless of whether AADE saw the data.
        $zeroVat = $this->hasZeroVatLine($payload)
            ? $this->resolveZeroVatCategory($defaultVat)
            : null;
        $out = [];
        foreach ($items as $item) {
            $description = trim((string) ($item['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            // WHMCS amount: assumed GROSS (Greek myDATA convention; per
            // the docblock if a tenant ever runs tax-exclusive we'd
            // need a per-tenant flag).
            $grossAmount = (float) ($item['amount'] ?? 0.0);
            $taxed = (bool) ((int) ($item['taxed'] ?? 1));   // assume taxable unless explicit 0

            // Mirror InvoiceLine::saving rounding order so the
            // preview gross MATCHES what gets persisted. Hook order:
            //   net   = round(qty × price × (1 - disc/100), 2)
            //   gross = round(net × (1 + vat/100), 2)
            // Our qty=1, disc=0 ⇒ net = price (rounded), then gross =
            // round(net × (1+vat/100), 2). The WHMCS amount is GROSS,
            // so we back-compute net = round(amount / (1+vat/100), 2)
            // and THEN re-derive gross from net via the same formula
            // the saving hook uses. For amounts like €10.00 @ 24% the
            // previous code stored gross=10.00 but the hook overwrote
            // to round(8.06×1.24,2) = 9.99 — the preview lied to the
            // operator by €0.01 per line.
            if (! $taxed) {
                // Untaxed line: gross == net, no VAT. (For myDATA a 0% line
                // needs a vat_exemption_category; the filer's
                // refuseProblematicZeroVatLines now ALLOWS it when the tenant's
                // 0%-rate VatCategory has a single exemption reason configured,
                // and only blocks the unconfigured/ambiguous case — G4.)
                // $zeroVat is guaranteed non-null here because
                // hasZeroVatLine() returned true and
                // resolveZeroVatCategory() would have thrown if
                // none was configured.
                $lineNet = round($grossAmount, 2);
                $lineGross = $lineNet;
                $linePercent = 0.0;
                $lineVatCategoryId = $zeroVat->id;
            } else {
                // G3: gross-inclusive → back out the net; tax-exclusive → the
                // amount IS the net. Either way re-derive gross from net via
                // the same formula InvoiceLine::saving uses (preview == saved).
                $lineNet = $amountIncludesTax
                    ? round($grossAmount / (1 + ($vatPercent / 100)), 2)
                    : round($grossAmount, 2);
                $lineGross = round($lineNet * (1 + ($vatPercent / 100)), 2);
                $linePercent = $vatPercent;
                $lineVatCategoryId = $defaultVat->id;
            }

            // Field names mirror the invoice_lines schema:
            //   qty × price_per_item × (1 - discount/100) = net_price
            //   net_price × (1 + vat_percent/100) = gross_price
            // The InvoiceLine::saving hook re-computes net_price/gross_price
            // from the inputs on every save (overwrites any value passed),
            // so the values we set here are advisory — present for the
            // preview modal which doesn't trigger the hook.
            $out[] = [
                'product_id' => null,
                'description' => $description,
                'qty' => 1.0,
                'price_per_item' => $lineNet,           // net per unit (qty=1, so net == unit)
                'discount' => 0.0,
                'vat_category_id' => $lineVatCategoryId,
                'vat_percent' => $linePercent,
                'net_price' => $lineNet,
                'gross_price' => $lineGross,
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    /**
     * Resolve the tenant's 0%-rate VatCategory. Throws if none is
     * configured — see the call-site comment in buildLines() for why
     * we removed the silent default-fallback shape.
     */
    private function resolveZeroVatCategory(VatCategory $defaultVat): VatCategory
    {
        $zero = VatCategory::query()
            ->where('company_id', $defaultVat->company_id)
            ->where('rate', 0.0)
            ->first();
        if ($zero === null) {
            throw new InvalidArgumentException(
                'Tenant has no 0%-rate VatCategory configured but the WHMCS invoice has '
                .'untaxed (taxed=0) line(s). Configure a 0%-rate category in Setup → VAT '
                .'Categories before filing this invoice, otherwise the line would be '
                .'misclassified under the default rate in downstream reports.'
            );
        }

        return $zero;
    }

    /**
     * Cheap pre-check: does the WHMCS payload contain ANY taxed=0
     * line? Used to skip the 0%-rate VatCategory lookup (and its
     * throw) entirely when every line is taxable — the tenant
     * doesn't need a 0%-rate category for fully-taxed invoices.
     */
    private function hasZeroVatLine(array $payload): bool
    {
        $items = $payload['items']['item'] ?? [];
        if (! empty($items) && ! array_is_list($items)) {
            $items = [$items];
        }
        foreach ($items as $item) {
            if ((int) ($item['taxed'] ?? 1) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Descriptions of lines mapped to vat_percent=0.0. These crash
     * MyDataSubmitter::vatCategoryFor (MyDataSubmitter.php:589) for
     * sandbox/production tenants — the filer must surface them to
     * the operator BEFORE persisting the local Invoice, so the ΑΑ
     * counter isn't consumed on a doomed submission.
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, string>
     */
    private function zeroVatLineDescriptions(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ((float) $line['vat_percent'] === 0.0) {
                $out[] = (string) $line['description'];
            }
        }

        return $out;
    }

    private function computeTotals(array $lines): array
    {
        $net = 0.0;
        $gross = 0.0;
        $byRate = [];

        foreach ($lines as $line) {
            $net += (float) $line['net_price'];
            $gross += (float) $line['gross_price'];
            $rate = (string) $line['vat_percent'];
            $byRate[$rate] ??= ['rate' => (float) $rate, 'net' => 0.0, 'vat' => 0.0, 'gross' => 0.0];
            $byRate[$rate]['net'] += (float) $line['net_price'];
            $byRate[$rate]['gross'] += (float) $line['gross_price'];
            $byRate[$rate]['vat'] += (float) $line['gross_price'] - (float) $line['net_price'];
        }

        // Sort by rate ascending for stable display.
        ksort($byRate);
        $breakdown = array_values(array_map(
            fn ($r) => [
                'rate' => $r['rate'],
                'net' => round($r['net'], 2),
                'vat' => round($r['vat'], 2),
                'gross' => round($r['gross'], 2),
            ],
            $byRate,
        ));

        return [
            'net_total' => round($net, 2),
            'vat_total' => round($gross - $net, 2),
            'gross_total' => round($gross, 2),
            'vat_breakdown' => $breakdown,
            // Fix #2 — does this invoice have any 0%-VAT lines that
            // MyDataSubmitter::vatCategoryFor would refuse? The filer
            // reads this BEFORE the transactional persist so an
            // untaxed WHMCS line item doesn't produce a ghost invoice
            // (local Invoice + ΑΑ consumed + AADE crashes mid-submit).
            // The actual list of 0%-VAT line descriptions is also
            // surfaced so the operator-facing error message can name
            // the problem lines specifically.
            'zero_vat_lines' => $this->zeroVatLineDescriptions($lines),
        ];
    }
}
