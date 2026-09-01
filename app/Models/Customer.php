<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use App\Support\InvoiceScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory, HasInternalNotes, HasTags, SoftDeletes, TracksActivity;

    /**
     * The AR outstanding-balance expression over the join aliases created by
     * scopeWithOutstandingBalance() (owed − standalone-credit-notes − paid).
     * Defined once so the SELECT alias, scopeOnlyDebtors(), and the Filament
     * CustomersTable filter can't drift apart (MON-9 added the cust_credit term).
     * Not usable on a select alias in WHERE, so the sites repeat this expression.
     */
    public const OUTSTANDING_BALANCE_SQL = '(COALESCE(cust_owed.owed, 0) - COALESCE(cust_credit.credited, 0) - COALESCE(cust_paid.paid, 0))';

    /**
     * Audited identity/contact/terms columns. See TracksActivity.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'type', 'afm', 'name', 'address1', 'address2', 'city', 'postcode',
            'phone1', 'phone2', 'occupation', 'tax_office', 'email', 'secondary_email',
            'discount', 'country', 'vat_vies', 'withhold_tax', 'payment_method_id',
            'is_active', 'needs_immediate_invoice', 'auto_email_invoices',
        ];
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'type',
        'afm',
        'name',
        'address1',
        'address2',
        'city',
        'postcode',
        'phone1',
        'phone2',
        'fax',
        'occupation',
        'tax_office',
        'kad_primary',
        'discount',
        'email',
        'secondary_email',
        // G6: per-customer auto-email opt-out (default true).
        'auto_email_invoices',
        'country',
        'vat_vies',
        'withhold_tax',
        'sort_order',
        'alt_customer_legacy_id',
        'payment_method_id',
        'whmcs_client_id',
        // PR-only additions:
        'needs_immediate_invoice',
        'is_active',
        // Operator-feedback polish: pin frequent customers to the top of
        // the invoice-form picker (favourites-first + auto-top).
        'is_favorite',
        // «Υπόλοιπο πελάτη» on the invoice PDF — per-customer override
        // (null = inherit the tenant default, true/false = force).
        'show_balance_on_pdf',
        'peppol_endpoint',
        'referred_by_customer_id',
        // T-1b: count of WHMCS third-party routing rows this customer owns
        // (0 = not a reseller). Maintained by whmcs:sync-resellers.
        'whmcs_reseller_routes',
    ];

    protected function casts(): array
    {
        return [
            'discount' => 'decimal:2',
            'needs_immediate_invoice' => 'boolean',
            'auto_email_invoices' => 'boolean',
            'is_active' => 'boolean',
            'is_favorite' => 'boolean',
            'show_balance_on_pdf' => 'boolean',
            'whmcs_reseller_routes' => 'integer',
        ];
    }

    /**
     * Tenant relation — required by Filament's BelongsToTenant trait so the
     * resource can scope rows to the current Company.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Self-reference: who referred this customer (if anyone).
     */
    public function referredBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referred_by_customer_id');
    }

    /**
     * Inverse: customers this one has referred.
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_customer_id');
    }

    /**
     * The lead this customer came from (null for customers created directly
     * or imported). Read side of `leads.converted_customer_id` — the «από πού
     * ήρθε» link; filled by ConvertLeadToCustomer.
     */
    public function originLead(): HasOne
    {
        // withTrashed: the link is a fact of history — a soft-deleted lead must
        // still show in «Προέλευση» / «Από lead» and still block a second link
        // (ConvertLeadToCustomer checks without scopes).
        return $this->hasOne(Lead::class, 'converted_customer_id')->withTrashed();
    }

    /**
     * All invoices issued to this customer. Used by the Καρτέλα page
     * for the chronological ledger and yearly breakdown.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Every invoice-email attempt for this customer, through their invoices
     * (invoice_mail_log has no direct customer_id). Feeds the per-customer
     * «Ιστορικό email» tab — read-only send history.
     */
    public function invoiceMailLog(): HasManyThrough
    {
        return $this->hasManyThrough(
            InvoiceMailLog::class,
            Invoice::class,
            'customer_id',   // invoices.customer_id
            'invoice_id',    // invoice_mail_log.invoice_id
            'id',            // customers.id
            'id',            // invoices.id
        );
    }

    /**
     * All payments received from this customer. Used by the Καρτέλα
     * page for the chronological ledger + balance calc.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Recurring service contracts (Υπηρεσίες / WHMCS «Services») for this
     * customer. Each stages a draft renewal invoice when it comes due. Surfaced
     * as a read-mostly tab on the customer view.
     */
    public function serviceContracts(): HasMany
    {
        return $this->hasMany(ServiceContract::class);
    }

    /**
     * Named people behind this customer (λογιστήριο, τεχνικός, υπεύθυνος…).
     * Primary first, then by operator sort order, then name.
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    /**
     * Attach an `outstanding_balance` column (and the `cust_owed` /
     * `cust_paid` join aliases it derives from) to a Customer query.
     *
     * The balance is computed the SAME way as
     * DashboardMetrics::outstandingReceivables() — so the sum of every
     * customer's positive balance reconciles with the dashboard's
     * "Ανεξόφλητα (πιστωτικά)" headline:
     *
     *   balance = Σ(credit-term, live, non-credit-note invoice
     *               gross_total − credited_total)
     *           − Σ(customer payments)
     *
     * Only `payment_methods.due_days > 0` (credit-term) invoices create a
     * receivable; cash-term are settled at issue. Credit notes
     * (credited_invoice_id set) are excluded from the base and netted via
     * the original's credited_total cache. Cancelled invoices (local OR
     * AADE) drop out via InvoiceScope::live(). All aggregation is in SQL
     * (two grouped sub-selects, left-joined) — no per-row PHP, so it is
     * safe on a list with thousands of customers.
     *
     * @param  int  $companyId  Tenant scope for the DB::table() subselects
     *                          inside (the BelongsToCompany global scope only
     *                          covers Eloquent, not these raw subqueries), and
     *                          works even with no ambient context.
     */
    public function scopeWithOutstandingBalance(Builder $query, int $companyId): Builder
    {
        $owed = DB::table('invoices')
            ->leftJoin('payment_methods', 'invoices.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.company_id', $companyId)
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.customer_id')
            // Credit-term OR a cash-term invoice with a recorded payment (the
            // money-trail exception — nets to zero against its payment). Mirrors
            // DashboardMetrics::outstandingReceivables + InvoiceBalance.
            ->where(function ($q) {
                $q->where('payment_methods.due_days', '>', 0)
                    ->orWhereExists(function ($s) {
                        $s->from('payments')
                            ->whereColumn('payments.invoice_id', 'invoices.id')
                            ->whereNull('payments.deleted_at');
                    });
            })
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            // Receivable base = payable_total (collectible: net+VAT + fees −
            // withholding) per row, falling back to gross_total for rows not yet
            // backfilled. COALESCE each SUM separately (credited_total is NULL on
            // never-credited invoices). Mirrors DashboardMetrics::outstandingReceivables().
            ->selectRaw('COALESCE(SUM(COALESCE(invoices.payable_total, invoices.gross_total)), 0) - COALESCE(SUM(invoices.credited_total), 0) as owed');
        // MON-9: exclude credit notes from the receivable base — correlated
        // (credited_invoice_id) AND standalone legacy (invoice_types.is_credit).
        // Must match DashboardMetrics::outstandingReceivables() exactly, or the
        // per-customer debtor table Σ diverges from the headline (the
        // reconciliation invariant OutstandingCustomersTable/MoneyStatusConsistencyTest guard).
        InvoiceScope::excludeCreditNotes($owed);
        // MON-5: unissued sale drafts aren't receivables — mirror
        // DashboardMetrics::outstandingReceivables() so the two stay reconciled.
        InvoiceScope::excludeUnissuedDrafts($owed);
        $owed = InvoiceScope::live($owed, 'invoices.');

        // MON-9: standalone legacy credit notes (is_credit type, no
        // credited_invoice_id) have no original carrying a credited_total, so the
        // base above can't net them. Subtract their payable per customer — matching
        // DashboardMetrics::outstandingReceivables() and CustomerLedgerBuilder,
        // which reduces the balance by EVERY credit note regardless of term.
        $standaloneCredits = DB::table('invoices')
            ->where('invoices.company_id', $companyId)
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.customer_id')
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            ->selectRaw('COALESCE(SUM(COALESCE(invoices.payable_total, invoices.gross_total)), 0) as credited');
        InvoiceScope::onlyStandaloneCreditNotes($standaloneCredits);
        $standaloneCredits = InvoiceScope::live($standaloneCredits, 'invoices.');

        $paid = DB::table('payments')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->select('customer_id')
            // Refunds (kind = 'refund') count NEGATIVE (Payment::NET_AMOUNT_SQL).
            ->selectRaw('SUM('.Payment::NET_AMOUNT_SQL.') as paid');

        return $query
            ->leftJoinSub($owed, 'cust_owed', 'cust_owed.customer_id', '=', 'customers.id')
            ->leftJoinSub($standaloneCredits, 'cust_credit', 'cust_credit.customer_id', '=', 'customers.id')
            ->leftJoinSub($paid, 'cust_paid', 'cust_paid.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw(self::OUTSTANDING_BALANCE_SQL.' as outstanding_balance');
    }

    /**
     * Narrow a `withOutstandingBalance()` query to customers who actually
     * owe money (positive balance, above a cent of rounding noise). Kept
     * separate so a caller can show ALL customers' balances (incl. zero /
     * credit) when wanted. Must be chained AFTER withOutstandingBalance().
     */
    public function scopeOnlyDebtors(Builder $query): Builder
    {
        return $query->whereRaw(self::OUTSTANDING_BALANCE_SQL.' > 0.005');
    }
}
