<?php

namespace App\Services\Whmcs;

/**
 * T-1 (timologia v2): the parsed result of the bridge's resolve.php endpoint
 * for one WHMCS invoice — which lines route to a third-party billing identity
 * (the legacy "Παραστατικά σε τρίτους") and which bill the WHMCS client.
 *
 * Read-only value object. Owns the ONE definition of "how many distinct
 * billing parties does this invoice touch", so the diagnostic command (T-1a)
 * and the eventual ingestor/inbox wire-up (T-1b) agree:
 *
 *   - Each routed line's party is its contact (keyed by contact id).
 *   - Each unrouted line's party is the WHMCS client (the reseller).
 *   - multi-party == more than one distinct party across all lines → the
 *     invoice can't be billed to a single counterpart and is staged as a
 *     "needs split" item for the operator (the legacy did this by hand with
 *     relid_remover + transfer_invoice).
 *
 * Building an ekdosi Customer from a contact (HTML-entity decode, AFM
 * normalisation, match/create) is deliberately NOT here — that's the billing
 * wire-up (T-1b). This object only describes the routing.
 */
class ThirdPartyResolution
{
    /**
     * @param  array<int, array<string, mixed>>  $lines  Per-line routing as
     *                                                   returned by resolve.php (each: item_id, relid, type,
     *                                                   service_type, description, routed, is_receipt, contact|null).
     */
    public function __construct(
        public readonly int $whmcsInvoiceId,
        public readonly int $whmcsUserId,
        public readonly bool $timologiaPresent,
        public readonly array $lines,
    ) {}

    /**
     * Build from the decoded resolve.php JSON body. Tolerant of the
     * "timologia not installed" shape (timologia_present=false, lines may be
     * present-but-unrouted).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromBridgeResponse(array $data): self
    {
        $lines = $data['lines'] ?? [];
        if (! is_array($lines) || ! array_is_list($lines)) {
            $lines = [];
        }

        return new self(
            whmcsInvoiceId: (int) ($data['whmcs_invoice_id'] ?? 0),
            whmcsUserId: (int) ($data['userid'] ?? 0),
            timologiaPresent: (bool) ($data['timologia_present'] ?? false),
            lines: array_values($lines),
        );
    }

    /**
     * Lines that route to a third-party contact.
     *
     * @return array<int, array<string, mixed>>
     */
    public function routedLines(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn ($line) => ! empty($line['routed']) && ! empty($line['contact']),
        ));
    }

    public function hasAnyRouting(): bool
    {
        return $this->routedLines() !== [];
    }

    /**
     * Distinct billing parties across all lines. Routed lines key on their
     * contact id; unrouted lines all collapse to the single "reseller" party.
     */
    public function distinctParties(): int
    {
        $keys = [];
        foreach ($this->lines as $line) {
            if (! empty($line['routed']) && ! empty($line['contact'])) {
                $keys['contact:'.((int) ($line['contact']['id'] ?? 0))] = true;
            } else {
                $keys['reseller:'.$this->whmcsUserId] = true;
            }
        }

        return count($keys);
    }

    /**
     * More than one billing party → can't map to a single ekdosi invoice
     * counterpart; the operator must split it (T-1b stages it flagged).
     */
    public function isMultiParty(): bool
    {
        return $this->distinctParties() > 1;
    }

    /**
     * When the WHOLE invoice routes to exactly one third-party contact (every
     * line → the same contact, no reseller-billed lines), return that contact
     * array. Null otherwise (no routing, or mixed/multi-party). This is the
     * clean single-party fast path T-1b bills directly to the contact.
     *
     * @return array<string, mixed>|null
     */
    public function singleContact(): ?array
    {
        $routed = $this->routedLines();
        if ($routed === [] || count($routed) !== count($this->lines)) {
            return null; // some lines bill the reseller, or no lines at all
        }
        if ($this->distinctParties() !== 1) {
            return null; // routed to more than one contact
        }

        return $routed[0]['contact'];
    }
}
