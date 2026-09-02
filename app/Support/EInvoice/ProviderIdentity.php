<?php

namespace App\Support\EInvoice;

/**
 * The public, legal identity of a ΥΠΑΗΕΣ e-invoicing provider — commercial and
 * legal name, website, AADE provider code and current licence number — read
 * from `config/ekdosi.php` → `einvoice.provider_identity` keyed by the provider
 * key stored on the mark (`mydata_marks.provider_key`).
 *
 * A.1112/2025 requires the PRINTED representation of a provider-issued document
 * to carry this evidence (provider name/site/licence + the document's MARK/UID/
 * authentication code/QR). Keeping it in config — not hard-coded in the Blade —
 * means a second provider (SBZ, …) renders its own correct evidence with one
 * config row, and the mark's `provider_key` selects the right identity per
 * document.
 *
 * Caveat (tracked as the PROV-003 "immutable snapshot" follow-up in BACKLOG):
 * this returns the CURRENT config identity. The licence number is versioned
 * (`…_V1_…`); when a real licence rotation happens, the licence in force AT
 * ISSUE must be snapshotted per document so a reprint of an old invoice keeps
 * its original licence. For today's single stable licence that snapshot is
 * unnecessary; the config source is correct and the follow-up is filed.
 *
 * Immutable.
 */
final class ProviderIdentity
{
    public function __construct(
        public readonly string $key,
        public readonly string $commercialName,
        public readonly string $legalName,
        public readonly string $site,
        public readonly string $aadeCode,
        public readonly string $licenceNo,
    ) {}

    /**
     * Resolve a provider key → its identity, or null when the key is empty or
     * has no config row (e.g. a direct-myDATA mark carries no provider_key).
     */
    public static function forKey(?string $key): ?self
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }

        $all = config('ekdosi.einvoice.provider_identity', []);
        $row = is_array($all) ? ($all[$key] ?? null) : null;
        if (! is_array($row)) {
            return null;
        }

        return new self(
            key: $key,
            commercialName: (string) ($row['commercial_name'] ?? $key),
            legalName: (string) ($row['legal_name'] ?? ''),
            site: (string) ($row['site'] ?? ''),
            aadeCode: (string) ($row['aade_code'] ?? ''),
            licenceNo: (string) ($row['licence_no'] ?? ''),
        );
    }
}
