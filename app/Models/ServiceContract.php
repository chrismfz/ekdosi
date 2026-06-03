<?php

namespace App\Models;

use App\Enums\BillingCycle;
use App\Enums\ServiceContractStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Per-customer subscription instance (the WHMCS «Service»). NOT money — the
 * scheduler that stages draft invoices when next_due_date arrives (operator-
 * gated, never auto-AADE). amount/billing_cycle/vat_percent are a snapshot from
 * the product's billing-price matrix; provisioning_module/module_meta/server_id
 * carry the future native (WHMCS-independent) automation hooks.
 *
 * next_due_date advancement is owned by the renewal action (StageServiceRenewal),
 * not by this model — same discipline as InvoiceNumberer owning the ΑΑ.
 */
class ServiceContract extends Model
{
    use BelongsToCompany;
    use HasFactory, SoftDeletes, TracksActivity;

    /**
     * Audited business columns — lifecycle + the snapshot money figure + the
     * dunning override, so a manual OR automated suspend/terminate is logged
     * («ποιος/πότε»). NOT the cursor-bookkeeping columns (last_invoiced_at /
     * last_renewal_invoice_id) which the renewal observer rewrites on every
     * issue and would spam the trail. See TracksActivity.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'status', 'next_due_date', 'amount', 'suspended_at', 'terminated_at',
            'cancel_reason', 'dunning_enabled',
        ];
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'customer_id',
        'product_id',
        'invoice_type_id',
        'payment_method_id',
        'server_id',
        'description',
        'billing_cycle',
        'quantity',
        'amount',
        'setup_fee',
        'vat_percent',
        'status',
        'start_date',
        'next_due_date',
        'end_date',
        'last_invoiced_at',
        'last_renewal_invoice_id',
        'suspended_at',
        'terminated_at',
        'cancel_reason',
        'suspend_after_days',
        'terminate_after_days',
        // PR-D: per-contract override of the product's dunning flag (null = inherit).
        'dunning_enabled',
        'domain',
        'provisioning_module',
        'module_meta',
        'whmcs_service_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'status' => ServiceContractStatus::class,
            'quantity' => 'decimal:3',
            'amount' => 'decimal:2',
            'setup_fee' => 'decimal:2',
            'vat_percent' => 'decimal:2',
            'start_date' => 'date',
            'next_due_date' => 'date',
            'end_date' => 'date',
            'last_invoiced_at' => 'datetime',
            'suspended_at' => 'datetime',
            'terminated_at' => 'datetime',
            'module_meta' => 'array',
            'dunning_enabled' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function invoiceType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Invoices staged from this contract (renewals). */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * Due for renewal as of $asOf: ACTIVE with a next_due_date on/before it.
     * Only Active contracts bill — pending/suspended/cancelled/terminated don't.
     */
    public function scopeDue(Builder $query, ?Carbon $asOf = null): Builder
    {
        $asOf ??= Carbon::today();

        return $query
            ->where('status', ServiceContractStatus::Active->value)
            ->whereNotNull('next_due_date')
            ->whereDate('next_due_date', '<=', $asOf);
    }
}
