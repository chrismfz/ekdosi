<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;

/**
 * Match a WHMCS client (or pending-invoice row carrying client fields)
 * against ekdosi's customers table for a specific tenant. Used by:
 *
 *   - The dry-run pull command, to show "WHMCS client 4321 would
 *     issue against ekdosi customer 17 (matched by AFM)".
 *   - The Filament "Link to WHMCS" picker, to suggest candidates
 *     when the operator hasn't picked one manually.
 *
 * Matching strategy, in order of confidence:
 *
 *   1. Direct customers.whmcs_client_id link — operator already
 *      decided. Trust it; no further matching needed.
 *   2. AFM exact match (after digit-only normalisation; legacy
 *      data has WHMCS storing "EL123456789" while ekdosi stores
 *      "123456789"). Strong signal; tax ID uniqueness is
 *      legally enforced.
 *   3. Email exact match (case-insensitive). Reasonable for B2C;
 *      B2B accounts sometimes share an email across roles.
 *   4. Company name exact match (case-insensitive). Last resort,
 *      operator should confirm.
 *
 * Returns a MatchResult value object so callers can render the
 * confidence + the match reason in the UI. Crucially: the matcher
 * NEVER writes to customers.whmcs_client_id automatically. Linking
 * is an operator decision — auto-linking via name match would create
 * silent cross-customer mistakes when two businesses share a common
 * name ("Acme Ltd" in Greece vs "Acme Ltd" in Cyprus).
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
        $afm = $this->normaliseAfm(
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

        // 3. Email exact match (case-insensitive). MariaDB's default
        // utf8mb4_unicode_ci collation case-folds ASCII reliably, so
        // plain `where('email', ...)` IS case-insensitive in
        // production. SQLite (the test env) is case-sensitive by
        // default, so we layer a PHP-side fallback that uses
        // mb_strtolower (mbstring-aware, unlike SQL LOWER()).
        //
        // Previously this used whereRaw('LOWER(email) = ?', ...) —
        // which works for ASCII on both engines but diverges for
        // Greek (verified: LOWER('ΑΚΜΕ') is 'ΑΚΜΕ' under SQLite's
        // built-in, 'ακμε' under MariaDB utf8mb4_unicode_ci). Tests
        // were passing for "acme@example.com" but a real Greek
        // operator email would miss in test env yet match in prod —
        // exactly the divergence test isolation is supposed to
        // prevent.
        $email = trim((string) ($whmcsClient['email'] ?? ''));
        if ($email !== '') {
            $byEmail = $this->findCustomerByCaseInsensitiveString(
                $tenant, 'email', $email,
            );
            if ($byEmail !== null) {
                return new MatchResult($byEmail, 'email', $whmcsClientId);
            }
        }

        // 4. Company name fallback. Per CLAUDE.md the legacy form
        // looked at the WHMCS "companyname" field (corporate) and
        // fell back to firstname+lastname (individual). Both are
        // realistic depending on tenant — B2B mostly companyname,
        // B2C mostly the name pair.
        $candidateName = trim((string) ($whmcsClient['companyname'] ?? ''));
        if ($candidateName === '') {
            $first = trim((string) ($whmcsClient['firstname'] ?? ''));
            $last = trim((string) ($whmcsClient['lastname'] ?? ''));
            $candidateName = trim($first.' '.$last);
        }

        if ($candidateName !== '') {
            // Same MariaDB-collation-aware comparison as email above.
            // Critical here for Greek customer names ("Ακμε ΑΕ" vs
            // "ΑΚΜΕ ΑΕ") — SQL LOWER() doesn't fold non-ASCII bytes
            // in SQLite, mb_strtolower does in PHP.
            $byName = $this->findCustomerByCaseInsensitiveString(
                $tenant, 'name', $candidateName,
            );
            if ($byName !== null) {
                return new MatchResult($byName, 'name', $whmcsClientId);
            }
        }

        return new MatchResult(null, 'unmatched', $whmcsClientId);
    }

    /**
     * Find a customer for the given tenant where `$column` matches
     * `$needle` case-insensitively, working correctly across
     * MariaDB (default utf8mb4_unicode_ci collation does case-folding
     * including Greek) AND SQLite (default case-sensitive — we
     * lowercase both sides via mbstring as a fallback).
     *
     * Order:
     *   1. Native `where()` — case-insensitive under MariaDB; fast
     *      ASCII path under SQLite.
     *   2. SQLite-only fallback: load and PHP-side compare with
     *      mb_strtolower. Only paid when (a) we're on SQLite AND
     *      (b) the native lookup missed. Cost is one customer scan
     *      per tenant per call; bounded by tenant size; acceptable
     *      for test-env compatibility.
     */
    private function findCustomerByCaseInsensitiveString(
        Company $tenant,
        string $column,
        string $needle,
    ): ?Customer {
        $direct = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where($column, $needle)
            ->first();
        if ($direct !== null) {
            return $direct;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return null;  // MariaDB's CI collation already handled it
        }

        $lower = mb_strtolower($needle);
        return Customer::query()
            ->where('company_id', $tenant->getKey())
            ->get()
            ->first(fn (Customer $c) => mb_strtolower((string) $c->{$column}) === $lower);
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

    /**
     * Strip Greek-VAT prefixes + non-digit junk so "EL123456789"
     * and "123456789" both match against `customers.afm = '123456789'`.
     * Doesn't validate; only normalises.
     */
    private function normaliseAfm(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $raw);
        return $digits === '' ? null : $digits;
    }
}
