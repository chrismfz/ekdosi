<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\Support\McpTenantResolver;
use App\Models\Company;
use App\Models\User;
use Laravel\Mcp\Request;

/**
 * Base for the myDATA/provider FORENSIC MCP tools (`invoice_filing`,
 * `mydata_failures`, `mydata_discrepancies`, `stuck_documents`, `preflight`).
 *
 * They answer the cutover-day question the ops tools cannot — «γιατί απορρίφθηκε
 * ΑΥΤΟ το παραστατικό;» — by reading what the filing path already persists: the
 * byte-exact request/response XML on `mydata_marks` (successes AND the forensic
 * REJECTED / *_FAILED rows), the mirror state on `invoices`, `mydata_pending_since`
 * and the reconciliation buckets. Nothing here files, cancels or mutates.
 *
 * Same guard + blast-radius model as {@see SuperAdminMcpTool}: cross-tenant infra
 * for debugging the deployment, so system super_admin only, read-only. The
 * response can carry counterpart ΑΦΜ/name and raw XML — exactly the data an
 * operator could already read on the box — which is why it is not offered to a
 * tenant member. See known-issues.md §OBS-001.
 */
abstract class ForensicMcpTool extends SuperAdminMcpTool
{
    /**
     * The tenant set a call operates on: the named `company` (validated), or —
     * when omitted / "all" — every company the caller may reach (all tenants for
     * a super_admin). Cross-tenant by default because a filing problem is found
     * by fleet, not by knowing the tenant up front.
     *
     * @return array{companies: list<Company>, error: ?string}
     */
    protected function scope(Request $request, ?string $company): array
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return ['companies' => [], 'error' => 'Δεν υπάρχει ταυτοποίηση.'];
        }

        $company = $company !== null ? trim($company) : '';

        // Named, specific company → validate it exactly like the business tools.
        if ($company !== '' && strcasecmp($company, 'all') !== 0) {
            $r = app(McpTenantResolver::class)->resolveTargets($user, $company);

            return ['companies' => $r['companies'], 'error' => $r['error']];
        }

        // Omitted or "all" → the whole accessible set (all tenants for super_admin).
        $all = app(McpTenantResolver::class)->accessibleCompanies($user);
        if ($all->isEmpty()) {
            return ['companies' => [], 'error' => 'Δεν ανήκετε σε καμία εταιρεία.'];
        }

        return ['companies' => $all->values()->all(), 'error' => null];
    }

    /** @param  list<Company>  $companies  @return list<int> */
    protected static function ids(array $companies): array
    {
        return array_map(static fn (Company $c): int => (int) $c->getKey(), $companies);
    }

    /** @param  list<Company>  $companies  @return array<int, string> id → slug */
    protected static function slugMap(array $companies): array
    {
        $map = [];
        foreach ($companies as $c) {
            $map[(int) $c->getKey()] = (string) $c->slug;
        }

        return $map;
    }

    /**
     * The AADE / provider business-error codes named in a response body, so a
     * failure reads as «[229] …» without the caller opening the XML. Catches AADE
     * `<code>229</code>` and InvoSign-style `[88-004]` / `[229]` bracket codes.
     *
     * @return list<string>
     */
    protected static function errorCodes(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }

        // Prefer the STRUCTURED error code — both AADE (`<code>229</code>`) and
        // InvoSign (`<errors><error><code>88-004</code>`) responses carry it. Only
        // when none is present do we fall back to bracket-scanning the body, so an
        // incidental `[204]` (a payment-method index, a doc reference) in a
        // NON-error response isn't reported as a fabricated rejection code.
        $codes = [];
        if (preg_match_all('/<code>\s*([^<]+?)\s*<\/code>/i', $xml, $m) !== false) {
            $codes = $m[1] ?? [];
        }
        if ($codes === [] && preg_match_all('/\[([0-9]{2,3}(?:-[0-9]{3})?)\]/', $xml, $m) !== false) {
            $codes = $m[1] ?? [];
        }

        $codes = array_values(array_unique(array_map('trim', $codes)));

        // A whole XML document can name dozens of schema codes; the first few are
        // the real rejection. Cap so the row stays scannable.
        return array_slice($codes, 0, 8);
    }

    /** Bounded head of a possibly-multi-KB XML blob (never egress the whole trace by default). */
    protected static function head(?string $text, int $max = 800): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        return mb_substr($text, 0, $max);
    }
}
