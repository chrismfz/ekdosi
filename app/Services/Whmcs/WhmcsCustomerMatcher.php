<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Support\Afm;

/**
 * Match a WHMCS client (or pending-invoice row carrying client fields)
 * against ekdosi's customers table for a specific tenant. Used by:
 *
 *   - The dry-run pull command, to show "WHMCS client 4321 would
 *     issue against ekdosi customer 17 (matched by AFM)".
 *   - The Filament "Link to WHMCS" picker, to suggest candidates
 *     when the operator hasn't picked one manually.
 *
 * Matching strategy — ΑΦΜ-ONLY (deterministic):
 *
 *   1. Direct customers.whmcs_client_id link — operator already
 *      decided. Trust it; no further matching needed.
 *   2. AFM exact match (after digit-only normalisation; legacy
 *      data has WHMCS storing "EL123456789" while ekdosi stores
 *      "123456789"). The ONLY legal-grade identity key; tax-ID
 *      uniqueness is enforced.
 *   …otherwise UNMATCHED. Email and name matching were REMOVED —
 *   they produced false matches (shared/role emails, resellers, common
 *   names) that pre-filled the WRONG ekdosi customer in the create-draft
 *   modal (a real risk of invoicing the wrong entity). An unmatched B2B
 *   row is handled by the operator («Δημ. πελάτη (ΑΑΔΕ)» / manual link);
 *   a B2C row (no ΑΦΜ) is a receipt and needs no customer link.
 *
 * Returns a MatchResult value object so callers can render the
 * confidence + the match reason in the UI. Crucially: the matcher
 * NEVER writes to customers.whmcs_client_id automatically. Linking
 * is an operator decision.
 *
 * NOT HANDLED HERE (Stage B / PR #29 scope): the legacy
 * `mod_timologia` plugin (legacy/whmcs/timologia/) lets a WHMCS
 * client say "issue service X to a THIRD party, not me". It carries
 * its own customer-identity rows in `mod_timologia_contacts`
 * (company_name, gr_vatno, tax_office, description, address fields,
 * vies_vatno, country) wired to a service via `mod_timologia
 * (userid, contactid, serviceid)`. When Stage B pulls an invoice,
 * the lookup is: for each line's serviceid, check mod_timologia →
 * if found, the customer-snapshot fields come from the linked
 * mod_timologia_contacts row, NOT from the WHMCS client's standard
 * custom fields. This matcher only handles the standard path
 * (WHMCS client = billed party); the third-party-billing layer goes
 * on top, and we'd extend match() with a $serviceContext parameter.
 */
class WhmcsCustomerMatcher
{
    /**
     * Find the best ekdosi customer for the WHMCS client data. Pass
     * the raw GetClientsDetails or GetInvoices row's client-fields
     * subset — we extract afm-equivalent, email, company name
     * ourselves.
     *
     * @param  array<string, mixed>  $whmcsClient  Raw client payload
     *                                             from WHMCS API.
     */
    public function match(Company $tenant, array $whmcsClient): MatchResult
    {
        // 1. Direct link (operator already mapped). Trust it.
        // Prefer `userid` over `id`: for a raw GetInvoices row, `id`
        // is the INVOICE id and `userid` is the client id. For a
        // GetClientsDetails row, only `id` is the client id. Reversing
        // the priority makes the matcher safe with raw rows from
        // either endpoint — without requiring callers to pre-massage
        // the payload (the prior shape silently picked invoice-id
        // as client-id for raw GetInvoices rows → every match
        // returned `unmatched`).
        $whmcsClientId = (int) ($whmcsClient['userid'] ?? $whmcsClient['id'] ?? 0);
        if ($whmcsClientId > 0) {
            $direct = Customer::query()
                ->where('company_id', $tenant->getKey())
                ->where('whmcs_client_id', $whmcsClientId)
                ->first();
            if ($direct !== null) {
                return new MatchResult($direct, 'linked', $whmcsClientId);
            }
        }

        // 2. AFM exact match. The custom field carrying VAT number
        // lives at a per-tenant configurable position; we look it
        // up via the tenant's whmcs_custom_field_map.
        $afm = Afm::normalise(
            $this->extractCustomField($whmcsClient, $tenant, 'vatno')
        );
        if ($afm !== null && $afm !== '') {
            $byAfm = Customer::query()
                ->where('company_id', $tenant->getKey())
                ->where('afm', $afm)
                ->first();
            if ($byAfm !== null) {
                return new MatchResult($byAfm, 'afm', $whmcsClientId);
            }
        }

        // ΑΦΜ-only matching (deliberate). Email and name matching were REMOVED:
        // they produced FALSE matches (shared/role emails, resellers, common
        // names) that pre-filled the WRONG ekdosi customer in the create-draft
        // modal — a real risk of issuing an invoice to the wrong entity. A B2B
        // invoice with no ΑΦΜ-match is left UNMATCHED on purpose: the operator
        // links it or clicks «Δημ. πελάτη (ΑΑΔΕ)»; a B2C invoice (no ΑΦΜ) is a
        // receipt and needs no customer link. ΑΦΜ is the only legal-grade key.
        return new MatchResult(null, 'unmatched', $whmcsClientId);
    }

    /**
     * Extract the value of a WHMCS custom field by canonical role
     * name. Looks up the role → fieldid mapping on the tenant, then
     * searches the customfields array on the WHMCS payload.
     *
     * WHMCS custom fields show up in API responses as:
     *   { customfields: [
     *       { id: "13", name: "ΑΦΜ", value: "123456789" },
     *       ...
     *   ]}
     * (or sometimes the shape varies — single field-as-object when
     * count == 1, same quirk as the invoice list).
     */
    private function extractCustomField(array $whmcsClient, Company $tenant, string $role): ?string
    {
        $fieldId = $tenant->whmcsCustomFieldId($role);
        if ($fieldId === null) {
            return null;
        }

        $customFields = $whmcsClient['customfields'] ?? [];
        if (empty($customFields)) {
            return null;
        }

        // Two response shapes: array of {id, name, value} OR a single
        // such object when count == 1.
        if (! array_is_list($customFields)) {
            $customFields = [$customFields];
        }

        foreach ($customFields as $field) {
            if ((int) ($field['id'] ?? 0) === $fieldId) {
                return trim((string) ($field['value'] ?? ''));
            }
        }
        return null;
    }

}
