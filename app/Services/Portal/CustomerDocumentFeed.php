<?php

namespace App\Services\Portal;

use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use App\Support\InvoiceScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of «which live documents belong to a (company, customer)»
 * and «may this portal login see this document». The portal reads it as its
 * leak-proof boundary (a login sees a customer's documents ONLY through an
 * ACTIVE grant); the WHMCS «Εκδοθέντα» side can be folded onto the same
 * `documentsFor()` later so the two never drift.
 *
 * Returns what the CUSTOMER may see: issued, non-AADE-cancelled documents PLUS
 * offered προτιμολόγια (drafts the operator has finalised and put in front of the
 * customer to settle). Mirrors Invoice::isCustomerVisible(), so the list can never
 * show a document the portal PDF route would refuse — and deliberately does NOT
 * widen Invoice::isPubliclyViewable(), which guards the legal-document channels
 * (signed public PDF, WHMCS proxy, invoice e-mail).
 */
class CustomerDocumentFeed
{
    /** Per-(company,customer) cap — a customer's document count feeds one table. */
    private const MAX_ROWS = 500;

    /**
     * Every group of documents a login may see, one per ACTIVE grant
     * (company × customer). Grants are cross-company by design, so CompanyScope
     * is dropped on the reads.
     *
     * @return list<array{company:string, customer:string, afm:?string, role:string, documents:list<array<string,mixed>>, truncated:bool}>
     */
    public function forLogin(CustomerUser $login): array
    {
        $groups = [];
        foreach ($this->grantedTargets($login) as $grant) {
            $documents = $this->documentsFor($grant->company_id, $grant->customer_id);
            $groups[] = [
                'company' => (string) $grant->company->name,
                'customer' => (string) $grant->customer->name,
                'afm' => $grant->customer->afm,
                'role' => (string) $grant->role,
                'documents' => $documents,
                // The MAX_ROWS cap is surfaced so the view can say so — a customer
                // with more live documents than the cap sees the newest, not silence.
                // Only a FULL page can hide older rows, and only then do we pay the
                // extra existence check (is there a MAX_ROWS+1'th live doc?).
                'truncated' => count($documents) === self::MAX_ROWS
                    && $this->liveQuery($grant->company_id, $grant->customer_id)
                        ->offset(self::MAX_ROWS)->exists(),
            ];
        }

        return $groups;
    }

    /**
     * THE grant boundary, resolved once: the active grants a login may act
     * through, each with its `company` and `customer` eager-loaded and the
     * impossible null-FK rows skipped. Every portal surface (documents, ledger,
     * …) reads its scope from here so they can never diverge — a login only ever
     * sees a (company, customer) it holds an active grant to. The customer carries
     * `company_id` so it's usable directly as a tenant-scoped model off-panel.
     *
     * @return list<CustomerUserAccess> each with ->company and ->customer loaded
     */
    public function grantedTargets(CustomerUser $login): array
    {
        return $login->activeAccessGrants()
            ->with(['company:id,name', 'customer:id,company_id,name,afm'])
            ->get()
            ->filter(fn (CustomerUserAccess $g): bool => $g->company !== null
                && $g->customer !== null
                // Fail-closed on a mismatched grant: documents scope by the grant's
                // company_id, the ledger by the customer's own company_id. They are
                // the same value unless a grant row points company A at a customer of
                // company B (a data slip — no DB constraint ties them). Skipping such
                // a grant keeps every surface on one consistent (company, customer).
                && (int) $g->customer->company_id === (int) $g->company_id)
            ->values()
            ->all();
    }

    /**
     * Customer-visible documents for one (company, customer) — issued invoices and
     * offered προτιμολόγια. Newest first, capped.
     *
     * @return list<array<string, mixed>>
     */
    public function documentsFor(int $companyId, int $customerId): array
    {
        return $this->liveQuery($companyId, $customerId)
            ->with('invoiceType:id,name')
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ROWS)
            ->get()
            ->map(fn (Invoice $inv): array => [
                'id' => $inv->id,
                'issued_at' => $inv->issued_at?->format('Y-m-d'),
                'invcode' => $inv->invcode,
                'type' => $inv->invoiceType?->name,
                // Load-bearing for the view: a proforma rendered identically to an
                // issued document would read as a tax document, which it is not.
                'is_proforma' => $inv->isOffered(),
                'mydata_state' => $inv->mydata_state,
                'mydata_mark' => $inv->mydata_mark,
                'verify_url' => ($inv->mydata_url !== null && $inv->mydata_url !== '') ? $inv->mydata_url : null,
            ])
            ->all();
    }

    /**
     * The set of invoice ids a (company, customer) may open online — the SAME
     * predicate as documentsFor()/loginCanAccess(), as a fast [id => true] lookup.
     * «Η καρτέλα μου» links a ledger row to its online view ONLY when its invoice
     * is in this set, so a broader ledger row (a legacy or credit-note DRAFT that
     * never became customer-visible) never renders a dead, 404-ing link.
     *
     * @return array<int, true>
     */
    public function visibleDocumentIdSet(int $companyId, int $customerId): array
    {
        return array_fill_keys(
            $this->liveQuery($companyId, $customerId)
                ->limit(self::MAX_ROWS)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
            true,
        );
    }

    /**
     * The one customer-visible predicate for a (company, customer), in SQL:
     * issued OR offered-proforma, and not AADE-cancelled — the SQL twin of
     * Invoice::isCustomerVisible(). CompanyScope is dropped (grants are
     * cross-company). Shared by documentsFor() and the truncation check so the
     * filter is defined exactly once.
     */
    private function liveQuery(int $companyId, int $customerId): Builder
    {
        $q = Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where(fn ($q) => $q->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'CANCELLED'));

        // Issued documents PLUS offered προτιμολόγια — the one shared definition
        // (App\Support\InvoiceScope::customerSettleable).
        return InvoiceScope::customerSettleable($q);
    }

    /**
     * May this login see (and download) THIS invoice? The PDF route's
     * fail-closed authorization: the document must be live AND reachable through
     * an active grant matching its (company, customer).
     */
    public function loginCanAccess(CustomerUser $login, Invoice $invoice): bool
    {
        // The PORTAL predicate, not the legal-document one: isPubliclyViewable()
        // stays the allow-list for the signed public PDF, the WHMCS proxy and the
        // invoice e-mail, none of which may serve a proforma.
        if (! $invoice->isCustomerVisible()) {
            return false;
        }
        if ($invoice->customer_id === null) {
            return false;
        }

        return $login->activeAccessGrants()
            ->where('company_id', $invoice->company_id)
            ->where('customer_id', $invoice->customer_id)
            // The customer must still exist (not soft-deleted) — whereHas uses the
            // grant's customer() relation, which drops CompanyScope but keeps the
            // SoftDeletes scope, so this gate agrees with forLogin() (which skips a
            // group whose customer resolved to null). Without it a deep-linked PDF
            // for a soft-deleted customer's still-active invoice would stay
            // downloadable after the list stopped showing it.
            ->whereHas('customer')
            ->exists();
    }
}
