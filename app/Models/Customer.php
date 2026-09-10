<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use App\Support\Afm;
use App\Support\InvoiceScope;
use App\Support\IsoCountry;
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
     * scopeWithOutstandingBalance() (owed − correlated-credit-notes − standalone-
     * credit-notes − paid). Defined once so the SELECT alias, scopeOnlyDebtors(),
     * and the Filament CustomersTable filter can't drift apart (MON-9 added the
     * cust_credit term; MON-13 split out cust_credited_total so a credit note
     * against a cash-term original still nets, matching the Καρτέλα).
     * Not usable on a select alias in WHERE, so the sites repeat this expression.
     */
    public const OUTSTANDING_BALANCE_SQL = '(COALESCE(cust_owed.owed, 0) - COALESCE(cust_credited_total.credited_total, 0) - COALESCE(cust_credit.credited, 0) - COALESCE(cust_paid.paid, 0))';

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
            'is_active', 'needs_immediate_invoice', 'needs_invoice_before_payment', 'auto_email_invoices',
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
        // MYD-011: normalised ISO-3166-1 alpha-2 cache of `country` (the picker binds
        // here). Fillable so the form can set it; re-normalised on save regardless.
        'country_code',
        'vat_vies',
        'withhold_tax',
        'sort_order',
        'alt_customer_legacy_id',
        'payment_method_id',
        'whmcs_client_id',
        // PR-only additions:
        'needs_immediate_invoice',
        'needs_invoice_before_payment',
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
            'needs_invoice_before_payment' => 'boolean',
            'auto_email_invoices' => 'boolean',
            'is_active' => 'boolean',
            'afm_key_parked' => 'boolean',
            'is_favorite' => 'boolean',
            'show_balance_on_pdf' => 'boolean',
            'whmcs_reseller_routes' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // `afm_key` is the ΑΦΜ IDENTITY (App\Support\Afm::uniqueKey) behind the
        // UNIQUE(company_id, afm_key) constraint — derived on every save, never
        // typed. Query-builder writers (ETL, importer) set it themselves.
        static::saving(function (self $customer): void {
            $customer->afm_key = self::deriveAfmKey($customer);

            IsoCountry::syncCountryCode($customer);
        });
    }

    /**
     * The identity this row holds on save — `Afm::uniqueKey($afm)` for everyone
     * except a PARKED row (`afm_key_parked`, set only by the Firebird ETL for the
     * legacy υποκατάστημα twin that shares an ΑΦΜ with the row the operator chose
     * to keep it — see the 2026_09_17 migration).
     *
     * A parked row stays keyless only while the ΑΦΜ actually still collides: once
     * the holder is merged away or this row's ΑΦΜ is corrected, it reclaims its
     * identity and un-parks itself. So parking can never silently outlive the
     * conflict that caused it, and a normal edit of a parked row (fixing a phone)
     * no longer dead-ends on the unique index.
     */
    private static function deriveAfmKey(self $customer): ?string
    {
        $key = Afm::uniqueKey($customer->afm);

        if ($key === null) {
            // No identity left to suppress (ΑΦΜ blanked or corrected to a
            // placeholder) — drop the flag too, or it lingers on a row nothing
            // can surface any more. Touched only when actually set, so a
            // partially-selected model is never given a phantom attribute.
            if ($customer->afm_key_parked) {
                $customer->afm_key_parked = false;
            }

            return null;
        }

        if (! $customer->afm_key_parked) {
            return $key;
        }

        $stillHeld = static::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $customer->company_id)
            ->where('afm_key', $key)
            ->when($customer->exists, fn (Builder $q) => $q->whereKeyNot($customer->getKey()))
            ->exists();

        if ($stillHeld) {
            return null;
        }

        $customer->afm_key_parked = false;

        return $key;
    }

    /**
     * The customer's country as a normalised ISO-3166-1 alpha-2, or null when it
     * cannot be resolved. Prefers the stored `country_code` cache and falls back to
     * normalising the free-text `country` live — so a row whose cache is not yet
     * backfilled resolves identically to the pre-MYD-011 behaviour (zero regression).
     */
    public function isoCountryCode(): ?string
    {
        return $this->country_code ?? IsoCountry::tryNormalise($this->country);
    }

    /**
     * THE owner lookup: the customer (soft-deleted included — the UNIQUE index
     * covers them, and a trashed owner must be restored, never duplicated)
     * holding this ΑΦΜ identity in a tenant. Every «does this ΑΦΜ already have
     * a customer?» site goes through here so the rule can't drift.
     *
     * @return Builder<static>
     */
    public static function afmOwnerQuery(int $companyId, ?string $afm): Builder
    {
        return static::withTrashed()->where('company_id', $companyId)->whereAfmKeyOf($afm);
    }

    /**
     * Customers sharing this ΑΦΜ identity (any formatting, EL/GR prefix or not).
     * A value with no identity (placeholder like 000000000, or blank) matches
     * NOBODY — callers that meet a placeholder must treat it as «no ΑΦΜ»
     * (retail), never as a customer to look up or create.
     */
    public function scopeWhereAfmKeyOf(Builder $query, ?string $afm): Builder
    {
        $key = Afm::uniqueKey($afm);

        return $key === null ? $query->whereRaw('1 = 0') : $query->where('afm_key', $key);
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

    /** Support tickets opened by / for this customer (Πυλώνας E). */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
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

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
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
            // backfilled. Mirrors DashboardMetrics::outstandingReceivables().
            ->selectRaw('COALESCE(SUM(COALESCE(invoices.payable_total, invoices.gross_total)), 0) as owed');
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

        // MON-13: correlated credit notes reduce via their ORIGINAL's credited_total,
        // summed over ALL live issued originals (ANY term) — NOT just those in the
        // owed base above. Else a credit note against a CASH-TERM original (its
        // original isn't a receivable) silently loses its reduction, and this
        // per-customer balance diverges from the Καρτέλα (CustomerLedgerBuilder). A
        // credit note is account credit, not a refund — the reduction must land.
        $creditedTotal = DB::table('invoices')
            ->where('invoices.company_id', $companyId)
            ->whereNull('invoices.deleted_at')
            ->whereNotNull('invoices.customer_id')
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            ->selectRaw('COALESCE(SUM(invoices.credited_total), 0) as credited_total');
        InvoiceScope::excludeCreditNotes($creditedTotal);
        InvoiceScope::excludeUnissuedDrafts($creditedTotal);
        $creditedTotal = InvoiceScope::live($creditedTotal, 'invoices.');

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
            ->leftJoinSub($creditedTotal, 'cust_credited_total', 'cust_credited_total.customer_id', '=', 'customers.id')
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
