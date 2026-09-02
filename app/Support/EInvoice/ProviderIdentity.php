<?php

namespace App\Support\EInvoice;

use App\Models\MyDataMark;

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
 * The licence number is versioned (`…_V1_…`). To keep an already-issued
 * document's evidence stable across a real licence rotation, the identity in
 * force AT ISSUE is snapshotted per document (`mydata_marks.provider_identity`,
 * PROV-003): the submitter stores `toArray()` on the mark, and both the PDF
 * renderer and the invoice page resolve via `forMark()` — the snapshot when
 * present, else the current config identity (legacy/pre-migration rows).
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

    /**
     * The identity to show for a mark: the FROZEN snapshot taken at issue if the
     * mark carries one (PROV-003), else the current config identity for its
     * provider_key (direct-myDATA marks and rows filed before the snapshot column
     * existed). This is the ONE resolver both the PDF renderer and the invoice
     * page call, so print and screen never diverge.
     */
    public static function forMark(MyDataMark $mark): ?self
    {
        $snapshot = $mark->provider_identity;

        return is_array($snapshot) && $snapshot !== []
            ? self::fromArray($snapshot)
            : self::forKey($mark->provider_key);
    }

    /**
     * Rebuild from a stored snapshot. `key` may be absent on a hand-written row;
     * default it to '' rather than throwing — the identity fields are what matter.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            key: (string) ($row['key'] ?? ''),
            commercialName: (string) ($row['commercial_name'] ?? ''),
            legalName: (string) ($row['legal_name'] ?? ''),
            site: (string) ($row['site'] ?? ''),
            aadeCode: (string) ($row['aade_code'] ?? ''),
            licenceNo: (string) ($row['licence_no'] ?? ''),
        );
    }

    /** The snapshot shape persisted on the mark. Round-trips through fromArray(). */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'commercial_name' => $this->commercialName,
            'legal_name' => $this->legalName,
            'site' => $this->site,
            'aade_code' => $this->aadeCode,
            'licence_no' => $this->licenceNo,
        ];
    }
}
