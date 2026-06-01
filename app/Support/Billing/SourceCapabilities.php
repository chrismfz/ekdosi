<?php

namespace App\Support\Billing;

/**
 * Phase 0 (Bridges/Connectors): what a billing source can do + how it presents,
 * so the inbox/UI adapt WITHOUT `if ($source === 'whmcs')` chains scattered
 * around. A read-only value object returned by BillingSource::capabilities().
 *
 * @see docs/bridges-connectors.md
 */
final class SourceCapabilities
{
    public function __construct(
        /** Operator-facing noun for one document, e.g. «τιμολόγιο» / «παραγγελία». */
        public readonly string $docNoun,
        /** Label for the external id column/badge, e.g. «WHMCS #». */
        public readonly string $externalIdLabel,
        /** Can ekdosi push the issued MARK back to the source? */
        public readonly bool $supportsWriteBack = false,
        /** Does the source have third-party / split invoicing (WHMCS timologia)? */
        public readonly bool $supportsThirdParty = false,
    ) {}
}
