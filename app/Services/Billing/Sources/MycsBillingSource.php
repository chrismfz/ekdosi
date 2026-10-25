<?php

namespace App\Services\Billing\Sources;

use App\Contracts\BillingSource;
use App\Support\Billing\SourceCapabilities;

/**
 * «MyCS Bridge» (docs/bridges-connectors.md §12): MyCS, the billing system that follows WHMCS. It serves the WHMCS
 * plugin's feed shape, so the inbox pipeline (App\Services\Whmcs\*, App\Services\WhmcsInbox\*) runs as for WHMCS;
 * this is its label and capabilities for the UI.
 */
class MycsBillingSource implements BillingSource
{
    public function key(): string
    {
        return 'mycs';
    }

    public function label(): string
    {
        return 'MyCS';
    }

    public function capabilities(): SourceCapabilities
    {
        return new SourceCapabilities(
            docNoun: 'τιμολόγιο',
            externalIdLabel: 'MyCS #',
            supportsWriteBack: true,    // MARK → MyCS ekdosi_documents (op=mark)
            supportsThirdParty: true,   // each line's recipient from MyCS's contacts
        );
    }
}
