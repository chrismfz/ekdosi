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
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Customer extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory, HasInternalNotes, HasTags, SoftDeletes, TracksActivity;

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
     * All invoices issued to this customer. Used by the Καρτέλα page
     * for the chronological ledger and yearly breakdown.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
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
            ->join('payment_methods', 'invoices.payment_method_id', '=', 'payment_methods.id')
            ->where('invoices.company_id', $companyId)
            ->whereNull('invoices.deleted_at')
            ->whereNull('invoices.credited_invoice_id')
            ->whereNotNull('invoices.customer_id')
            ->where('payment_methods.due_days', '>', 0)
            ->groupBy('invoices.customer_id')
            ->select('invoices.customer_id')
            // COALESCE each SUM separately (NOT SUM(gross - credited)) —
            // credited_total is NULL on never-credited invoices and
            // per-row NULL arithmetic would null the whole sum. Mirrors
            // DashboardMetrics::outstandingReceivables() exactly.
            ->selectRaw('COALESCE(SUM(invoices.gross_total), 0) - COALESCE(SUM(invoices.credited_total), 0) as owed');
        $owed = InvoiceScope::live($owed, 'invoices.');

        $paid = DB::table('payments')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->select('customer_id')
            ->selectRaw('SUM(amount) as paid');

        return $query
            ->leftJoinSub($owed, 'cust_owed', 'cust_owed.customer_id', '=', 'customers.id')
            ->leftJoinSub($paid, 'cust_paid', 'cust_paid.customer_id', '=', 'customers.id')
            ->select('customers.*')
            ->selectRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) as outstanding_balance');
    }

    /**
     * Narrow a `withOutstandingBalance()` query to customers who actually
     * owe money (positive balance, above a cent of rounding noise). Kept
     * separate so a caller can show ALL customers' balances (incl. zero /
     * credit) when wanted. Must be chained AFTER withOutstandingBalance().
     */
    public function scopeOnlyDebtors(Builder $query): Builder
    {
        return $query->whereRaw('(COALESCE(cust_owed.owed, 0) - COALESCE(cust_paid.paid, 0)) > 0.005');
    }
}
