<?php

namespace App\Contracts;

use App\Support\Billing\SourceCapabilities;

/**
 * Phase 0 (Bridges/Connectors — docs/bridges-connectors.md): a billing source
 * is an external system that emits billing documents (WHMCS invoices,
 * WooCommerce orders, …) which become draft ekdosi invoices.
 *
 * Phase 0 deliberately scopes this to IDENTITY + CAPABILITIES only. The
 * data-movement methods (fetchPending / fetchDocument / writeBackMark) and the
 * normalised ExternalDocument DTO are Phase 1 — designing them from the single
 * WHMCS example would bake WHMCS-isms into the "generic" contract. You need two
 * real implementations to get the abstraction right (see §5 of the doc).
 */
interface BillingSource
{
    /** Stable machine key: 'whmcs' | 'woocommerce' | 'blesta' … */
    public function key(): string;

    /** Operator-facing name for the source, e.g. 'WHMCS'. */
    public function label(): string;

    public function capabilities(): SourceCapabilities;
}
