<?php

namespace App\Services\Billing\Sources;

use App\Contracts\BillingSource;
use App\Support\Billing\SourceCapabilities;

/**
 * Phase 0 (Bridges/Connectors): the WHMCS billing source — identity +
 * capabilities. The live ingest/file/write-back still runs through the existing
 * App\Services\Whmcs\* + App\Services\WhmcsInbox\* services unchanged; this
 * class is the seam that future sources (WooCommerce, Blesta …) mirror, and the
 * single place the UI reads WHMCS's label/capabilities from.
 *
 * Phase 1 adds the data-movement methods here (fetchPending / fetchDocument /
 * writeBackMark) once the ExternalDocument contract is finalised against a
 * second source.
 */
class WhmcsBillingSource implements BillingSource
{
    public function key(): string
    {
        return 'whmcs';
    }

    public function label(): string
    {
        return 'WHMCS';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            docNoun: 'τιμολόγιο',
            externalIdLabel: 'WHMCS #',
            supportsWriteBack: true,    // MARK → mod_ekdosi_invoice_marks
            supportsThirdParty: true,   // timologia / Παραστατικά σε τρίτους
        );
    }
}
