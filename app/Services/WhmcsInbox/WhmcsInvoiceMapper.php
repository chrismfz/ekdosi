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
    ): array {
        if ($customer->company_id !== $tenant->id) {
            throw new InvalidArgumentException('Customer belongs to a different tenant.');
        }
        if ($invoiceType->company_id !== $tenant->id) {
            throw new InvalidArgumentException('Invoice type belongs to a different tenant.');
        }

        $payload = $pending->payload ?? [];
        $defaultVat = $this->resolveDefaultVatCategory($tenant);

        $lines = $this->buildLines($payload, $defaultVat);
        $totals = $this->computeTotals($lines);

        return [
            'header' => [
                'company_id'        => $tenant->id,
                'customer_id'       => $customer->id,
                'invoice_type_id'   => $invoiceType->id,
                'issued_at'         => now()->format('Y-m-d H:i:s'),
                'payment_method_id' => $invoiceType->payment_method_id,
                // Snapshot: frozen at issue time per Greek legal-invoice
                // requirements (the invoice must record the customer's
                // identity AS IT WAS when filed, not as it might be later).
                'company_name' => (string) $customer->name,
                'vat_no'       => (string) ($customer->afm ?? ''),
                'address1'     => (string) ($customer->address1 ?? ''),
                'city'         => (string) ($customer->city ?? ''),
                'postcode'     => (string) ($customer->postcode ?? ''),
                'country'      => (string) ($customer->country ?? 'GR'),
                'occupation'   => (string) ($customer->occupation ?? ''),
                'email'        => (string) ($customer->email ?? ''),
                'notes'        => 'Από WHMCS #'.($payload['invoiceid'] ?? $payload['id'] ?? '?'),
            ],
            'lines'  => $lines,
            'totals' => $totals,
            'source' => [
                'whmcs_invoice_id' => (int) ($payload['invoiceid'] ?? $payload['id'] ?? 0),
                'whmcs_userid'     => (int) ($payload['userid'] ?? 0),
                'whmcs_date'       => (string) ($payload['date'] ?? ''),
                'whmcs_total'      => (float) ($payload['total'] ?? 0.0),
            ],
        ];
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
     * Convert WHMCS items to ekdosi InvoiceLine[]-ready arrays.
     * WHMCS shape: items[] each with {description, amount, taxed, ...}
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildLines(array $payload, VatCategory $defaultVat): array
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

            if (! $taxed) {
                // Untaxed line: gross == net, no VAT. Use a 0% VAT
                // category if one exists; otherwise default. (For
                // myDATA a 0% line needs a vat_exemption_category;
                // tracked deferral in CLAUDE.md.)
                $lineNet = $grossAmount;
                $lineGross = $grossAmount;
                $linePercent = 0.0;
            } else {
                $lineNet = round($grossAmount / (1 + ($vatPercent / 100)), 2);
                $lineGross = $grossAmount;
                $linePercent = $vatPercent;
            }

            // Field names mirror the invoice_lines schema:
            //   qty × price_per_item × (1 - discount/100) = net_price
            //   net_price × (1 + vat_percent/100) = gross_price
            // The InvoiceLine::saving hook re-computes net_price/gross_price
            // from the inputs on every save (overwrites any value passed),
            // so the values we set here are advisory — present for the
            // preview modal which doesn't trigger the hook.
            $out[] = [
                'product_id'      => null,
                'description'     => $description,
                'qty'             => 1.0,
                'price_per_item'  => $lineNet,           // net per unit (qty=1, so net == unit)
                'discount'        => 0.0,
                'vat_category_id' => $defaultVat->id,
                'vat_percent'     => $linePercent,
                'net_price'       => $lineNet,
                'gross_price'     => $lineGross,
            ];
        }
        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
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
                'rate'  => $r['rate'],
                'net'   => round($r['net'], 2),
                'vat'   => round($r['vat'], 2),
                'gross' => round($r['gross'], 2),
            ],
            $byRate,
        ));

        return [
            'net_total'     => round($net, 2),
            'vat_total'     => round($gross - $net, 2),
            'gross_total'   => round($gross, 2),
            'vat_breakdown' => $breakdown,
        ];
    }
}
