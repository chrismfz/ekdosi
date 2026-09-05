<?php

namespace App\Services\Portal;

use App\Models\CustomerUser;
use App\Models\Invoice;
use App\Models\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single source of «which live documents belong to a (company, customer)»
 * and «may this portal login see this document». The portal reads it as its
 * leak-proof boundary (a login sees a customer's documents ONLY through an
 * ACTIVE grant); the WHMCS «Εκδοθέντα» side can be folded onto the same
 * `documentsFor()` later so the two never drift.
 *
 * Only LIVE documents are ever returned — issued (`local_status=active`) and not
 * AADE-cancelled — the same allow-list as Invoice::isPubliclyViewable(), so the
 * list can never show a document the PDF route would refuse.
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
        $grants = $login->activeAccessGrants()
            ->with(['company:id,name', 'customer:id,name,afm'])
            ->get();

        $groups = [];
        foreach ($grants as $grant) {
            // A grant whose company/customer was hard-deleted is skipped (the FK
            // is cascade, so normally impossible; defensive).
            if ($grant->company === null || $grant->customer === null) {
                continue;
            }
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
     * Live issued invoices for one (company, customer). Newest first, capped.
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
                'mydata_state' => $inv->mydata_state,
                'mydata_mark' => $inv->mydata_mark,
                'verify_url' => ($inv->mydata_url !== null && $inv->mydata_url !== '') ? $inv->mydata_url : null,
            ])
            ->all();
    }

    /**
     * The one live-document predicate for a (company, customer): issued
     * (`local_status=active`) and not AADE-cancelled — the same allow-list as
     * Invoice::isPubliclyViewable(), in SQL. CompanyScope is dropped (grants are
     * cross-company). Shared by documentsFor() and the truncation check so the
     * filter is defined exactly once.
     */
    private function liveQuery(int $companyId, int $customerId): Builder
    {
        return Invoice::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->where('local_status', 'active')
            ->where(fn ($q) => $q->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'CANCELLED'));
    }

    /**
     * May this login see (and download) THIS invoice? The PDF route's
     * fail-closed authorization: the document must be live AND reachable through
     * an active grant matching its (company, customer).
     */
    public function loginCanAccess(CustomerUser $login, Invoice $invoice): bool
    {
        if (! $invoice->isPubliclyViewable()) {
            return false;
        }
        if ($invoice->customer_id === null) {
            return false;
        }

        return $login->activeAccessGrants()
            ->where('company_id', $invoice->company_id)
            ->where('customer_id', $invoice->customer_id)
            ->exists();
    }
}
