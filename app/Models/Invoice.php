<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use App\Observers\InvoiceObserver;
use App\Services\InvoiceBalance;
use App\Services\InvoiceBalanceData;
use App\Support\InvoiceScope;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Illuminate\Support\Facades\URL;

/**
 * Issued invoice (παραστατικό). Mirrors legacy INVOICE.
 *
 * The party-snapshot columns (address1, vat_no, occupation, company_name,
 * etc.) are intentionally denormalised at issue-time: a customer's
 * address or AFM may change after the invoice is filed, but the FILED
 * legal document must keep the values as they were on that date.
 * NEVER join through `customer` to display these on a printed invoice
 * or audit view — use the snapshot columns.
 *
 * The mydata_* columns are a denormalised cache that mirrors the latest
 * mydata_marks row. Source of truth for myDATA submissions is the
 * mydata_marks HasMany. Replacement for the legacy MARK_AI0 trigger
 * lands in PR #7 (MyDataSubmitter service); for now the cache is
 * populated by the ETL.
 *
 * `code` is the per-invoice-type sequence number (ΑΑ).
 * `invcode` is the human-readable identifier = invoice_type.code . code,
 * e.g. "APY423". Allocated by App\Services\InvoiceNumberer under a row
 * lock so concurrent issues for the same type can't collide.
 */
#[ObservedBy(InvoiceObserver::class)]
class Invoice extends Model
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory, HasInternalNotes, HasTags, SoftDeletes, TracksActivity;

    /**
     * A permanent, unforgeable (HMAC-signed with APP_KEY) public URL to this
     * invoice's official PDF — handed to the WHMCS bridge so a παραστατικό can be
     * linked without copying the file. The PDF stays here (source of truth). No
     * expiry: the customer/operator may open it any time; the signature alone
     * gates access and the controller refuses drafts.
     */
    public function publicPdfUrl(): string
    {
        return URL::signedRoute('public.invoice.pdf', ['invoice' => $this->getKey()]);
    }

    /**
     * May this invoice's PDF be served on the PUBLIC (signed, customer-facing)
     * route? FAIL-CLOSED allow-list: only an issued, non-AADE-cancelled document
     * is a valid «official παραστατικό». Drafts (not issued) and CANCELLED docs
     * (legally void) are hidden; any future/unknown local_status is hidden until
     * explicitly opted in here — never leaked by omission.
     */
    public function isPubliclyViewable(): bool
    {
        return $this->local_status === 'active'
            && $this->mydata_state !== 'CANCELLED';
    }

    /**
     * Audited columns — lifecycle + money figures + the myDATA state mirror, but
     * NOT the money cache (paid_total / credited_total / payment_status), which
     * InvoiceBalance rewrites on every payment recompute. See TracksActivity.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'code', 'customer_id', 'invoice_type_id', 'issued_at', 'local_status',
            'cancel_reason', 'header_discount_percent', 'net_total', 'gross_total',
            'withhold_amount', 'withhold_category', 'payment_method_id',
            'mydata_state', 'mydata_mark',
        ];
    }

    /**
     * Mass-assignable columns. The myDATA cache columns
     * (mydata_sent / mydata_state / mydata_mark / mydata_url) are
     * INTENTIONALLY OMITTED — they're written ONLY by the future
     * MyDataSubmitter service (PR #7) inside the same DB transaction
     * as the matching mydata_marks row. Leaving them fillable would
     * let any Filament edit form (PR #8) accept `mydata_state = 'VALID'`
     * with an arbitrary MARK from operator input, with no audit row
     * to back it. The submitter uses `forceFill()` to bypass this guard.
     *
     * Same shape as the legacy schema, where MARK_AI0 (an AFTER INSERT
     * trigger on MARK) was the only writer of those columns.
     *
     * ETL note: MigrateFromFirebird::copyInvoices populates these
     * directly via the DB query builder (not via Model::create), so
     * the fillable restriction doesn't affect the ETL.
     */
    protected $fillable = [
        'company_id',
        'legacy_id',
        'invcode',
        'code',
        'invoice_type_id',
        'customer_id',
        'issued_at',
        'distribution_aim_id',
        'delivery_method_id',
        'payment_method_id',
        'bank_account_id',
        'conv_invoice_id',
        'credited_invoice_id',
        'whmcs_pending_id',
        'service_contract_id',
        'local_status',
        'cancel_reason',
        'delivery_date',
        'header_discount_percent',
        'net_total',
        'gross_total',
        'payable_total',
        'withhold_amount',
        'withhold_category',
        'withhold_rate',
        'fees_amount',
        'fees_category',
        'fees_rate',
        'other_taxes_amount',
        'other_taxes_category',
        'other_taxes_rate',
        'stamp_duty_amount',
        'stamp_duty_category',
        'stamp_duty_rate',
        'deductions_amount',
        'deductions_category',
        'deductions_rate',
        // Party snapshot at issue time
        'address1',
        'address2',
        'city',
        'postcode',
        'country',
        'company_name',
        'vat_no',
        'vies_vat',
        'occupation',
        'notes',
        'language',
        // myDATA invoice type snapshot — captured at submit time, NOT
        // edit-time. Source: $invoice->invoiceType->mydata_type at
        // the moment MyDataSubmitter ran. Reading via the relation
        // would shift historical invoices' classification on every
        // admin edit of invoice_types.mydata_type, breaking the
        // "filed document is frozen" guarantee. Mass-assignable so
        // the ETL can backfill from legacy data.
        'mydata_type',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'delivery_date' => 'date',
            'header_discount_percent' => 'decimal:2',
            'net_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'payable_total' => 'decimal:2',
            // Customer running-balance snapshot at issue time — written ONLY by
            // App\Observers\InvoiceObserver (forceFill, not $fillable).
            'customer_balance_snapshot' => 'decimal:2',
            // Money-status cache — written ONLY by App\Services\InvoiceBalance
            // (not $fillable, mirroring the mydata_* cache columns).
            'paid_total' => 'decimal:2',
            'credited_total' => 'decimal:2',
            'withhold_amount' => 'decimal:2',
            'withhold_category' => 'integer',
            'withhold_rate' => 'decimal:4',
            'fees_amount' => 'decimal:2',
            'fees_category' => 'integer',
            'fees_rate' => 'decimal:4',
            'other_taxes_amount' => 'decimal:2',
            'other_taxes_category' => 'integer',
            'other_taxes_rate' => 'decimal:4',
            'stamp_duty_amount' => 'decimal:2',
            'stamp_duty_category' => 'integer',
            'stamp_duty_rate' => 'decimal:4',
            'deductions_amount' => 'decimal:2',
            'deductions_category' => 'integer',
            'deductions_rate' => 'decimal:4',
            'mydata_sent' => 'boolean',
            'whmcs_invoice_id' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function invoiceType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** The service contract this invoice was staged from (renewal), if any. */
    public function serviceContract(): BelongsTo
    {
        return $this->belongsTo(ServiceContract::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    /**
     * The invoice this one was converted from (legacy
     * delivery-note → invoice path; CONV_INVOICE_ID).
     */
    public function convertedFromInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'conv_invoice_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /**
     * The Προσφορά this invoice was produced from via «Μετατροπή σε
     * Παραστατικό», if any. Read-only reverse of Quote::convertedInvoice()
     * (keyed on quotes.converted_invoice_id) — gives the invoice ↔ quote
     * history both ways without a column on this legal table.
     */
    public function convertedFromQuote(): HasOne
    {
        return $this->hasOne(Quote::class, 'converted_invoice_id');
    }

    /**
     * Payments allocated directly to this invoice (not on-account ones,
     * which carry invoice_id = null and belong to the customer).
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** Credit notes issued against this invoice (credited_invoice_id → this). */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(self::class, 'credited_invoice_id');
    }

    /** If this invoice IS a credit note, the original it credits. */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /** Delivery notes (δελτία αποστολής) that dispatch this sale (delivery_notes.invoice_id → this). */
    public function deliveryNotes(): HasMany
    {
        return $this->hasMany(DeliveryNote::class, 'invoice_id');
    }

    /**
     * Fully reversed by credit note(s): an original (not itself a credit) whose
     * credited_total has reached its gross. This is the «ακυρώθηκε με πιστωτικό»
     * state — the credit note is the legal reversal, so we DON'T flip
     * local_status to cancelled (that would double-remove it from the ledger);
     * instead the UI reads this derived flag to badge it and hide the now-moot
     * credit/cancel actions (leaving «Επανέκδοση»). Reads the money caches.
     */
    public function isFullyCredited(): bool
    {
        // Compared against payableTotal() (the collectible), since credited_total
        // is now summed in payable units (mirrors InvoiceBalance) — so a withholding
        // invoice fully reversed by a credit note reads as fully credited, not −€X.
        $payable = $this->payableTotal();

        return $this->credited_invoice_id === null
            && $payable > 0.005
            && (float) $this->credited_total >= $payable - 0.005;
    }

    /**
     * The myDATA additional-tax adjustment to the gross — the SINGLE source of the
     * AADE [208] rule, reused by both the submitter (AadeInvoiceDocument) and the
     * local `payable_total`, so the two can never diverge:
     *   + fees + stampDuty + otherTaxes − deductions − withholding
     * Withholding reduces it UNLESS its §8.4 category is informational (8/9/10),
     * which AADE reports but does not deduct from the gross.
     */
    public function additionalTaxAdjustment(): float
    {
        $withheld = round((float) ($this->withhold_amount ?? 0), 2);

        return round(
            round((float) ($this->fees_amount ?? 0), 2)
            + round((float) ($this->stamp_duty_amount ?? 0), 2)
            + round((float) ($this->other_taxes_amount ?? 0), 2)
            - round((float) ($this->deductions_amount ?? 0), 2)
            - ($this->withholdingReducesGross() ? $withheld : 0.0),
            2,
        );
    }

    /**
     * Does this invoice's withholding reduce the gross/payable? Yes for normal
     * §8.4 categories; NO for the informational prepaid-tax categories 8/9/10
     * (architects/engineers/lawyers), which AADE reports but doesn't deduct.
     */
    public function withholdingReducesGross(): bool
    {
        return (float) ($this->withhold_amount ?? 0) > 0
            && $this->withhold_category !== null
            && WithheldPercentCategory::tryFrom((int) $this->withhold_category)?->affectsTotalGrossValue() === true;
    }

    /**
     * The real COLLECTIBLE amount (what the customer pays / we receive): the
     * persisted `payable_total`, else a live fallback `gross_total + adjustment`
     * for rows not yet recomputed. This — NOT gross_total — is the basis for
     * owed/balance/receivables. (gross_total stays net+VAT = revenue/turnover.)
     */
    public function payableTotal(): float
    {
        return $this->payable_total !== null
            ? round((float) $this->payable_total, 2)
            : round((float) ($this->gross_total ?? 0) + $this->additionalTaxAdjustment(), 2);
    }

    /**
     * Is this invoice a credit note (reduces the customer's running balance)?
     * Either correlated to an original or a standalone credit-type document.
     * Mirrors CustomerLedgerBuilder::isCreditNote.
     */
    public function isCreditNote(): bool
    {
        return $this->credited_invoice_id !== null
            || (bool) ($this->invoiceType?->is_credit);
    }

    /**
     * Does this invoice move the customer's running balance (Καρτέλα «υπόλοιπο»)?
     * Credit notes always do; normal sales only on credit terms (cash-term =
     * settled at issue, never a receivable). Mirrors the ledger's balance math
     * (CustomerLedgerBuilder::isTracked + isCreditNote) so the «Νέο υπόλοιπο»
     * block on the PDF reconciles with the Καρτέλα.
     */
    public function affectsCustomerBalance(): bool
    {
        return $this->isCreditNote()
            || (int) ($this->paymentMethod?->due_days ?? 0) > 0;
    }

    /**
     * This document's signed contribution to the customer's running balance:
     * +payable for a credit-term sale, −payable for a credit note, 0 for a
     * cash-term sale. So Προηγούμενο υπόλοιπο = snapshot − contribution.
     *
     * NOTE: reconciles with the snapshot (built from CustomerLedgerBuilder, whose
     * `payable()` is `payable_total ?? gross_total`) only while `payable_total` is
     * populated — which it always is for app-issued invoices (RecomputeInvoiceTotals
     * writes it before issue, and only app-issued rows get a snapshot). On a NULL
     * fallback the two would diverge by the [208] adjustment; that path is unreachable
     * here by construction.
     */
    public function customerBalanceContribution(): float
    {
        if (! $this->affectsCustomerBalance()) {
            return 0.0;
        }

        return $this->isCreditNote() ? -$this->payableTotal() : $this->payableTotal();
    }

    private ?InvoiceBalanceData $balanceDataCache = null;

    /**
     * Live money snapshot (owed/paid/credited/balance/status) from
     * App\Services\InvoiceBalance — the authoritative figure for views.
     * Lists/tables read the cached payment_status column instead.
     *
     * Memoised per instance: an infolist renders ~5 entries off this and
     * a modal reads it twice; without the memo each call would re-run two
     * SQL aggregates. Fresh enough for a single read-only render; any
     * write path recomputes the persisted cache separately.
     */
    public function balanceData(): InvoiceBalanceData
    {
        return $this->balanceDataCache ??= app(InvoiceBalance::class)->for($this);
    }

    public function mydataMarks(): HasMany
    {
        return $this->hasMany(MyDataMark::class);
    }

    /**
     * Outbound mail send log — one row per attempt (queued / sending /
     * sent / failed). Surfaces on the ViewInvoice page as a relation
     * manager so operators can see delivery history.
     */
    public function mailLog(): HasMany
    {
        return $this->hasMany(InvoiceMailLog::class)->orderByDesc('created_at');
    }

    /**
     * The most recent send attempt (by id) — drives the "email status" column +
     * filter on the invoices list without an N+1. Latest id == latest attempt
     * since mail-log rows are insert-ordered.
     */
    public function latestMailLog(): HasOne
    {
        return $this->hasOne(InvoiceMailLog::class)->latestOfMany();
    }

    /**
     * Invoices whose LATEST mail-log row (max id) has one of the given statuses.
     * A correlated subquery — NOT whereHas on latestMailLog (Laravel can't build
     * an existence query for a latestOfMany relation). Backs the list's email
     * filter; kept here so it's testable without the Filament harness.
     *
     * @param  list<string>  $statuses
     */
    public function scopeWhereLatestMailStatus(Builder $query, array $statuses): Builder
    {
        return $query->whereIn('id', InvoiceMailLog::query()
            ->whereIn('status', $statuses)
            ->whereRaw('invoice_mail_log.id = (select max(m2.id) from invoice_mail_log m2 where m2.invoice_id = invoice_mail_log.invoice_id)')
            ->select('invoice_id'));
    }

    /**
     * Due date of a credit-term invoice = issued_at + payment_method.due_days.
     * NULL for cash-term (due_days = 0 / no method) — settled at issue, never
     * "due" later. A pure date, NOT money: it doesn't touch InvoiceBalance.
     */
    public function dueDate(): ?Carbon
    {
        $dueDays = (int) ($this->paymentMethod?->due_days ?? 0);
        if ($dueDays <= 0 || $this->issued_at === null) {
            return null;
        }

        return $this->issued_at->copy()->startOfDay()->addDays($dueDays);
    }

    /**
     * Ληξιπρόθεσμο: a live, issued (non-draft), non-credit-note, credit-term
     * invoice whose own balance is still outstanding and whose due date has
     * passed. Mirrors {@see scopeOverdue} for a single hydrated record.
     */
    public function isOverdue(?Carbon $asOf = null): bool
    {
        $due = $this->dueDate();
        if ($due === null) {
            return false;
        }
        if ($this->local_status !== 'active' || $this->credited_invoice_id !== null) {
            return false;
        }
        if ($this->mydata_state === 'CANCELLED') {
            return false;
        }
        if (! in_array((string) $this->payment_status, [
            PaymentStatus::Unpaid->value,
            PaymentStatus::Partial->value,
        ], true)) {
            return false;
        }

        return $due->lt($asOf ?? Carbon::today());
    }

    /**
     * Overdue receivables: credit-term (due_days > 0), live, issued, non-credit-
     * note invoices with an outstanding cached payment_status and a due date in
     * the past. Driver-aware date math (MariaDB in prod, sqlite in tests) via a
     * correlated EXISTS on payment_methods — no join, so it composes with the
     * Filament list query and hydrates cleanly. Read-only; no money mutation.
     */
    public function scopeOverdue(Builder $query, ?Carbon $asOf = null): Builder
    {
        $cutoff = ($asOf ?? Carbon::today())->toDateTimeString();
        $driver = $query->getConnection()->getDriverName();
        $dueExpr = match ($driver) {
            'sqlite' => "datetime(invoices.issued_at, '+' || payment_methods.due_days || ' days')",
            'pgsql' => "invoices.issued_at + (payment_methods.due_days || ' days')::interval",
            default => 'DATE_ADD(invoices.issued_at, INTERVAL payment_methods.due_days DAY)', // mysql / mariadb
        };

        $query
            ->where('invoices.local_status', 'active')
            ->whereNull('invoices.credited_invoice_id')
            ->whereIn('invoices.payment_status', [
                PaymentStatus::Unpaid->value,
                PaymentStatus::Partial->value,
            ])
            ->whereExists(function ($sub) use ($dueExpr, $cutoff) {
                $sub->selectRaw('1')
                    ->from('payment_methods')
                    ->whereColumn('payment_methods.id', 'invoices.payment_method_id')
                    ->where('payment_methods.due_days', '>', 0)
                    ->whereRaw("$dueExpr < ?", [$cutoff]);
            });

        return InvoiceScope::live($query, 'invoices.');
    }

    /**
     * The myDATA «Outbox»: live παραστατικά that SHOULD be filed to AADE but
     * carry no MARK yet — i.e. a draft awaiting οριστικοποίηση+υποβολή, or a
     * finalized invoice whose submission never landed (failed/skipped). The
     * predicate is: the type is one we file (`invoice_types.mydata_type` set),
     * the invoice is NOT cancelled, and there's no `mydata_mark`. Imported legacy
     * invoices already carry their MARK, so they never appear here.
     */
    public function scopeAwaitingMyData(Builder $query): Builder
    {
        return $query
            ->whereHas('invoiceType', fn (Builder $t) => $t->whereNotNull('mydata_type'))
            ->where('local_status', '!=', 'cancelled')
            ->whereNull('mydata_mark');
    }

    /**
     * Latest myDATA submission for this invoice — for the read-only
     * view page. Ordered by the legal action time (mark_date +
     * mark_time), NOT by autoincrement id. Live submissions get id
     * order = action order, but ETL-imported MARK rows from legacy
     * may be inserted in arbitrary order (Firebird SELECT order),
     * so id-based ordering would return the wrong row when the
     * legacy operator cancelled and re-submitted out of insertion
     * sequence. id is included as a final tiebreaker for the
     * pathological case of two MARKs at exactly the same second.
     */
    public function latestMydataMark(): HasOne
    {
        return $this->hasOne(MyDataMark::class)->latestOfMany(['mark_date', 'mark_time', 'id']);
    }

    /**
     * G6: per-customer auto-email opt-out. Gates ONLY the automatic mail
     * paths (myDATA-VALID + non-myDATA finalize); the manual "Resend
     * email" action ignores it (clicking is explicit operator intent).
     * Defaults to true (no customer / flag unset → send) so existing
     * behaviour is preserved.
     */
    public function customerAcceptsAutoEmail(): bool
    {
        return (bool) ($this->customer?->auto_email_invoices ?? true);
    }

    /**
     * G6: should finalizing this DRAFT auto-email the customer? This is
     * the NON-myDATA issue path. True only when:
     *   - the tenant does NOT file via myDATA (sandbox/production) — those
     *     invoices get the mail on the VALID response instead, so firing
     *     here too would double-send;
     *   - the tenant opted in (companies.auto_email_on_issue); and
     *   - the customer hasn't opted out (customers.auto_email_invoices).
     */
    public function shouldAutoEmailOnFinalize(): bool
    {
        $company = $this->company;

        if ($company === null) {
            return false;
        }

        $filesViaMyData = in_array($company->mydata_mode, ['sandbox', 'production'], true);

        return ! $filesViaMyData
            && (bool) $company->auto_email_on_issue
            && $this->customerAcceptsAutoEmail();
    }
}
