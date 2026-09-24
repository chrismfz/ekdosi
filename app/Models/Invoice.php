<?php

namespace App\Models;

use App\Contracts\MovableDocument;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\HasTags;
use App\Models\Concerns\TracksActivity;
use App\Models\Scopes\CompanyScope;
use App\Observers\InvoiceObserver;
use App\Services\InvoiceBalance;
use App\Services\InvoiceBalanceData;
use App\Services\Stock\StockService;
use App\Support\Afm;
use App\Support\DocumentSeries;
use App\Support\EInvoice\ProviderEvidence;
use App\Support\EInvoice\SendChannel;
use App\Support\InvoiceScope;
use App\Support\IsoCountry;
use App\Support\ProvisionalCode;
use App\Support\Tenancy\TenantCoherence;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use RuntimeException;

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
class Invoice extends Model implements MovableDocument
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory, HasInternalNotes, HasTags, SoftDeletes, TracksActivity;

    /**
     * The Combined ΤΔΑ NULLABLE movement-header DATA columns (Slice 3d) — everything
     * EXCEPT the `is_delivery_note` discriminant, the NOT-NULL `without_digital_
     * transport_tracking` flag (reset to false, not null), and the lifecycle CACHE
     * (delivery_state / *_mark, written only by the movement service). Cleared when the
     * invoice is NOT a ΤΔΑ so a plain 1.1 carries no orphan movement header
     * (EditInvoice). The lifecycle cache is untouched — a filed ΤΔΑ can't reach edit.
     */
    public const MOVEMENT_DATA_COLUMNS = [
        'move_purpose', 'other_move_purpose_title', 'dispatch_at', 'vehicle_number',
        'transport_type', 'carrier_afm',
        'loading_street', 'loading_number', 'loading_postcode', 'loading_city', 'start_shipping_branch',
        'delivery_street', 'delivery_number', 'delivery_postcode', 'delivery_city', 'complete_shipping_branch',
    ];

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
            && $this->mydata_state !== 'CANCELLED'
            // An informal (non-fiscal) document is not a «παραστατικό» — never on the
            // public PDF route, the issued-for-client list or the invoice e-mail.
            && ! $this->isInformal();
    }

    public const INFORMAL_NOT_FILEABLE = 'Άτυπο παραστατικό (μη φορολογική σειρά) — δεν διαβιβάζεται στο myDATA ούτε σε πάροχο.';

    /**
     * A document of an INFORMAL (non-fiscal) series — tests and our own internal
     * services (docs/non-billable-services.md): never filed at myDATA, never in any
     * money/VAT total, printed «ΑΤΥΠΟ». The PHP twin of InvoiceScope::excludeInformal().
     */
    public function isInformal(): bool
    {
        $type = self::informalTypeLookup($this->invoice_type_id, $this->invoiceType);
        if ($type !== null && $type->trashed() && $this->invoiceType === null) {
            // Memoise the trashed series on this instance (one lookup, not one per
            // call) — the same «show soft-deleted referenced rows» pattern the
            // resource's eager load follows.
            $this->setRelation('invoiceType', $type);
        }

        return (bool) ($type?->is_informal ?? false);
    }

    /**
     * Would re-typing this document to $newTypeId move it across the informal/fiscal
     * line while it is already COMMITTED — numbered (its ΑΑ belongs to the series it
     * was drawn from: a reverted «ΕΣΩ5» would be filed under ΕΣΩ/5) or holding
     * customer money (an informal document drops out of every balance, so the
     * payment would turn into unexplained credit)? An unnumbered, unpaid draft is
     * free to change series. Shared by the model guard and the edit form's rule.
     */
    public function crossesInformalLine(int|string|null $newTypeId): bool
    {
        if (! $this->exists) {
            return false;
        }
        $was = (bool) self::informalTypeLookup($this->getOriginal('invoice_type_id'))?->is_informal;
        $now = (bool) self::informalTypeLookup($newTypeId)?->is_informal;

        return $was !== $now && ($this->getOriginal('code') !== null || $this->hasRecordedPayments());
    }

    public const INFORMAL_LINE_LOCKED = 'Το παραστατικό έχει ήδη αριθμό ή πληρωμή — δεν αλλάζει από άτυπη σε φορολογική σειρά (ή ανάποδα). Ακύρωσέ το και φτιάξε νέο.';

    /**
     * The series behind an invoice_type_id, TRASHED INCLUDED: a series deleted after
     * issue still made its documents informal, exactly as the SQL twin
     * (excludeInformal) reads trashed types. The plain belongsTo drops a trashed
     * type, so fall back to a direct lookup only in that (rare) case.
     */
    private static function informalTypeLookup(int|string|null $typeId, ?InvoiceType $loaded = null): ?InvoiceType
    {
        if (blank($typeId)) {
            return null;
        }
        $typeId = (int) $typeId;
        if ($loaded !== null && (int) $loaded->getKey() === $typeId) {
            return $loaded;
        }

        return InvoiceType::query()->withoutGlobalScope(CompanyScope::class)->withTrashed()->find($typeId);
    }

    /**
     * Is this a «προτιμολόγιο» — a draft the operator has finalised and offered to
     * the customer? Still a draft in every sense that matters (no ΑΑ, no myDATA, not
     * in any money total); the flag only means «this is the final proposal, the
     * customer may act on it», which also locks it against further editing.
     */
    public function isOffered(): bool
    {
        return $this->local_status === 'draft' && $this->offered_at !== null;
    }

    /**
     * May the LOGGED-IN CUSTOMER see this document in the portal?
     *
     * Deliberately separate from {@see isPubliclyViewable()}, which stays the
     * allow-list for legal documents and is shared by the signed public PDF route,
     * the WHMCS PDF proxy, the issued-for-client list and the invoice e-mail.
     * Widening THAT would push proformas into channels that promise a «παραστατικό
     * ΑΑΔΕ» — so the portal gets its own, wider predicate instead.
     *
     * A proforma shown here must be labelled as one wherever it is rendered: it is
     * not a tax document and must never be mistaken for one.
     */
    public function isCustomerVisible(): bool
    {
        return ($this->isPubliclyViewable() || $this->isOffered()) && ! $this->isInformal();
    }

    /**
     * Does this document already hold customer money?
     *
     * Before the προτιμολόγιο a draft could never carry payments, so the draft
     * lifecycle (free editing, delete) assumed there was nothing to protect. A paid
     * proforma breaks that assumption: reassigning it to another customer would move
     * A's money onto B's document, and deleting it would leave the Payment rows
     * pointing at a soft-deleted invoice — money reducing a balance with no document
     * to explain it.
     */
    public function hasRecordedPayments(): bool
    {
        return $this->payments()->exists();
    }

    /**
     * Captures still in flight for THIS document. Withdrawing an offer (or issuing)
     * re-checks the settle target, so a pending intent would silently fall back to
     * FIFO and pay something else — the operator should be told before, not after.
     */
    public function pendingPaymentIntentsCount(): int
    {
        return PaymentIntent::query()
            ->withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->company_id)
            ->where('invoice_id', $this->getKey())
            ->where('status', PaymentIntent::STATUS_PENDING)
            ->count();
    }

    /**
     * An UNISSUED SALE draft — the PHP twin of
     * {@see InvoiceScope::onlyUnissuedDrafts()}: a local draft, new-app
     * (no `legacy_id`) and not a credit note.
     *
     * This is exactly the set the SQL money surfaces drop via excludeUnissuedDrafts().
     * InvoiceBalance keys its cash-term carve-out on it so the two can never disagree:
     * a broader rule (every draft) would also catch unfiled credit-note drafts, which
     * the SQL surfaces DO count — and the cached badge would then report a receivable
     * that the dashboard and the ledger both say is nothing.
     */
    public function isUnissuedSaleDraft(): bool
    {
        return $this->local_status === 'draft'
            && $this->legacy_id === null
            && $this->credited_invoice_id === null
            && ! ($this->invoiceType?->is_credit ?? false);
    }

    /**
     * May the customer settle this document (pay it, or point existing credit at
     * it)? Both a live issued invoice and an offered proforma qualify — the point
     * of the proforma is that money can land on it BEFORE it becomes a legal
     * document, so a service the customer drops never has to be issued and then
     * cancelled.
     */
    public function isCustomerPayable(): bool
    {
        return $this->mydata_state !== 'CANCELLED'
            && ($this->local_status === 'active' || $this->isOffered())
            && ! $this->isInformal();
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
            // Offering/withdrawing locks editing, exposes the document in the
            // portal and makes it payable — «Ιστορικό» must show who did it and when.
            'offered_at',
            'code', 'customer_id', 'invoice_type_id', 'issued_at', 'local_status',
            'cancel_reason', 'header_discount_percent', 'net_total', 'gross_total',
            'withhold_amount', 'withhold_category', 'payment_method_id',
            // Operator intent on the filed legal party (which customer establishment) —
            // audit-worthy like header_discount_percent; logOnlyDirty means it only
            // records a row on a real change, never for the default-0 no-op.
            'counterpart_branch',
            // Combined ΤΔΑ (3a): the movement header is operator-set and `is_delivery_note`
            // changes the document's legal nature («is this 1.1 ALSO a ΔΑ?»), so it is
            // audited like the other business columns. logOnlyDirty + dontLogEmptyChanges
            // means a plain invoice (all null/false) never records a row. The lifecycle
            // CACHE cols (delivery_state / *_mark) are excluded — written by the movement
            // service via forceFill, like the money cache.
            'is_delivery_note', 'without_digital_transport_tracking',
            'move_purpose', 'other_move_purpose_title', 'dispatch_at', 'vehicle_number',
            'transport_type', 'carrier_afm',
            'loading_street', 'loading_number', 'loading_postcode', 'loading_city', 'start_shipping_branch',
            'delivery_street', 'delivery_number', 'delivery_postcode', 'delivery_city', 'complete_shipping_branch',
            'mydata_state', 'mydata_mark',
        ];
    }

    /**
     * `origin` of a document NOT issued by this app (NULL = issued here): a sale
     * imported from the myDATA orphans. History, not a new issue — no balance
     * snapshot, no automatic reminders (not mass-assignable; set by the importer).
     */
    public const ORIGIN_MYDATA_ORPHAN = 'mydata_orphan';

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
        'series',
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
        'reissued_from_invoice_id',
        'whmcs_pending_id',
        'service_contract_id',
        'local_status',
        'offered_at',
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
        // AADE establishment (εγκατάσταση) of the counterpart this document was
        // issued to; 0 = the party's έδρα. The per-document replacement for the
        // legacy duplicate-ΑΦΜ branch hack (see the migration). Part of the party
        // snapshot: operator-set on the draft, then frozen by living on the row.
        'counterpart_branch',
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
        // Combined ΤΔΑ (Slice 3a) — a 1.1 that is ALSO a delivery note carries a
        // movement header. The lifecycle CACHE cols (delivery_state / transfer_mark /
        // return_mark) are NOT fillable — written ONLY by the movement service via
        // forceFill, exactly like the mydata_* cache.
        'is_delivery_note',
        'without_digital_transport_tracking',
        'move_purpose',
        'other_move_purpose_title',
        'dispatch_at',
        'vehicle_number',
        'loading_street', 'loading_number', 'loading_postcode', 'loading_city', 'start_shipping_branch',
        'delivery_street', 'delivery_number', 'delivery_postcode', 'delivery_city', 'complete_shipping_branch',
        'transport_type', 'carrier_afm',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'offered_at' => 'datetime',
            // Combined ΤΔΑ (Slice 3a/3b)
            'is_delivery_note' => 'boolean',
            'without_digital_transport_tracking' => 'boolean',
            'dispatch_at' => 'datetime',
            'move_purpose' => 'integer',
            'transport_type' => 'integer',
            'start_shipping_branch' => 'integer',
            'complete_shipping_branch' => 'integer',
            'delivery_date' => 'date',
            'header_discount_percent' => 'decimal:2',
            'counterpart_branch' => 'integer',
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
            // MYD-2 (σκέλος γ): "in-doubt" marker set on a transport failure, so
            // submit() reconciles before it may resubmit. Mirror column — written
            // ONLY by MyDataSubmitter (forceFill, not $fillable).
            'mydata_pending_since' => 'datetime',
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

    /**
     * Freeze the FILED series on create (MYD-018 / MYD-024).
     *
     * Deliberately ONE choke point rather than a line in each of the eight
     * creators (the Filament pages, the four Actions, the WHMCS filer/splitter,
     * the delivery page and the sandbox command). A creator that forgot it would
     * leave the document reading the live, editable `invoice_types.code` again — the
     * exact defect this closes — and nothing would notice until a rename.
     *
     * Derived from `invcode`, not from the type relation: `invcode` is already
     * frozen and IS `series . code`, so this records what the document actually
     * claims to be, and the ETL's imported legacy rows get their true historical
     * series for free. A shape the helper cannot read leaves the column null,
     * and the readers fall back to the live type code — i.e. today's behaviour.
     */
    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (blank($model->series)) {
                $model->series = DocumentSeries::fromInvcode($model->invcode, $model->code);
            }
        });

        // Gapless-at-send (reverses MON-4): a document created WITHOUT a real ΑΑ —
        // the new draft flow, which no longer allocates at creation — gets a
        // PROVISIONAL identity keyed by its surrogate id («ΠΡΟΣ-ΤΠΥ-6885»), so every
        // `invcode` reader shows a stable, unique, clearly-provisional label. The real
        // code/invcode/series are written later, at transmission (the submitters).
        // Guarded on `code === null`, so ETL/fixtures/legacy rows that set a real code
        // are untouched. Post-insert (`created`) because the id is the unique token.
        static::created(function (self $model): void {
            if ($model->code === null && blank($model->invcode)) {
                $model->invcode = ProvisionalCode::make($model->invoiceType?->code, $model->getKey());
                $model->saveQuietly();
            }
        });

        // A numbered or paid document never crosses the informal/fiscal line
        // (crossesInformalLine). The edit form freezes/validates the type too; this
        // covers every other path.
        static::updating(function (self $model): void {
            if ($model->isDirty('invoice_type_id') && $model->crossesInformalLine($model->invoice_type_id)) {
                throw new RuntimeException(self::INFORMAL_LINE_LOCKED);
            }
        });
    }

    /**
     * The series this document is FILED under — the frozen value, falling back to
     * the live type code only for rows numbered before it was frozen.
     *
     * Every reader (the AADE header, the provider payload, the in-doubt recovery
     * search) must go through here: reading `invoiceType->code` directly is what let a
     * lookup rename change an already-numbered document, and made recovery look
     * for a (series, ΑΑ) that AADE had never seen.
     */
    public function filedSeries(): ?string
    {
        // filled(), NOT `?:` — '0' is a legitimate series in a numeric scheme and
        // is falsy, so `?:` would silently discard the FROZEN value and fall back
        // to the live lookup: the exact bug this method exists to close.
        $series = filled($this->series) ? $this->series : $this->invoiceType?->code;

        // Normalise a blank type code to null so the readers' own fail-closed
        // guards fire, rather than filing a document under an empty series that
        // could never be matched back — at AADE or by the in-doubt recovery.
        return blank($series) ? null : (string) $series;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /* ===================== Legal counterpart identity (MYD-009) =====================
     |
     | The party-snapshot columns above are the LEGAL counterpart of a filed
     | document. Everything that builds a filing — the AADE payload, the provider
     | payload, the PDF — must read them through these three helpers rather than
     | through `customer`, so the same invoice can never describe two different
     | parties depending on when it is rendered.
     |
     | The bug they close (MYD-009): buildCounterpart() filed `customer->afm` and
     | `customer->name` while taking country and address from the snapshot, so the
     | reported party was assembled HALF frozen and HALF live. Editing a customer
     | changed the XML of an invoice filed a year earlier, and a credit note that
     | faithfully copied its original's snapshot had it overwritten again by
     | today's customer row.
     */

    /**
     * Has this invoice been transmitted? Twin of DeliveryNote::hasBeenFiled().
     * Both submit paths write `mydata_sent` and `mydata_mark` together; a
     * rejected-then-repaired document can carry the flag without a MARK.
     */
    public function hasBeenFiled(): bool
    {
        return (bool) $this->mydata_sent || filled($this->mydata_mark);
    }

    /**
     * May a blank snapshot field fall back to the live customer row?
     *
     * ONLY for a document that has not been filed yet. Rows created by this app
     * always carry a snapshot (the form, the WHMCS mapper and every Action write
     * it); the blanks are legacy/ETL imports, and those must still be issuable —
     * so an unfiled document resolves from the customer and FREEZES the result at
     * submit (MyDataSubmitter::freezePartySnapshot), which is what keeps the
     * column honest afterwards.
     *
     * Once filed, never: `customers` is live and the snapshot is the record of
     * what was reported, so reading through would show a party the AADE record
     * never carried.
     */
    public function mayFallBackToLiveCustomer(): bool
    {
        return ! $this->hasBeenFiled() && $this->counterpartIsTheLinkedCustomer();
    }

    /**
     * Does the linked customer actually DESCRIBE this invoice's counterpart?
     *
     * The party fields are editable on the invoice form while `customer_id` stays
     * put, so an operator can pick a customer and then overtype «ΑΦΜ/Επωνυμία» with
     * a different party. Borrowing the country or address from that customer would
     * then assemble ONE reported party out of TWO real ones — the same hole
     * MYD-011 closed for delivery notes, by the same route.
     *
     * A blank snapshot field is not a disagreement: it is exactly the legacy row the
     * fallback exists for. Only a field that is filled AND different rules it out.
     */
    public function counterpartIsTheLinkedCustomer(): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        $afm = trim((string) $this->vat_no);

        // A value that is not an IDENTITY («-», «0», «N/A») trims non-empty but
        // uniqueKey()s to null — and null equals a null customer ΑΦΜ, which let two
        // different parties short-circuit past the name check entirely. Only a real
        // identity may stand in for the comparison.
        if ($afm !== '' && Afm::uniqueKey($afm) !== null) {
            // The ΑΦΜ is the identity. When it matches, the party IS this customer —
            // a differing NAME is a rename or a spelling correction, not a different
            // taxpayer, and treating it as fatal made a routine customer rename turn
            // every legacy invoice with a blank country into an unissuable document
            // (and its credit notes with it, since IssueCreditNote copies the pair).
            return Afm::uniqueKey($afm) === Afm::uniqueKey($customer->afm);
        }

        // With no ΑΦΜ on the document there is nothing stronger to go on, so the name
        // has to carry it. (The delivery-note twin stays stricter on purpose: its
        // recipient name is operator-typed free text on a document whose whole point
        // is naming a party, and refusing there costs only an edit.)
        $name = trim((string) $this->company_name);

        return $name === '' || $name === trim((string) $customer->name);
    }

    /**
     * The counterpart's ΑΦΜ as filed: the frozen snapshot, else the legacy fallback.
     *
     * CANONICALISED on the way out through Afm::uniqueKey() — the ONE identity rule,
     * shared with `customers.afm_key`. `invoices.vat_no` is free
     * text (a bare TextInput, and an ETL copy of the legacy column), so filing it
     * verbatim sent «IT 12345678901» / «EL123456789» to AADE and earned an opaque
     * rejection. The old code filed `customers.afm`, which the customer form and the
     * GSIS/VIES lookups keep clean; reading the snapshot must not lose that.
     */
    public function counterpartAfm(): ?string
    {
        // uniqueKey() maps a placeholder to null, so «0»/«000000000» falls through
        // to the customer instead of becoming the reported party.
        if (filled($frozen = Afm::uniqueKey($this->vat_no))) {
            return $frozen;
        }

        return $this->mayFallBackToLiveCustomer()
            ? Afm::uniqueKey($this->customer?->afm)
            : null;
    }

    /** The counterpart's legal name as filed. */
    public function counterpartName(): ?string
    {
        $frozen = trim((string) $this->company_name);
        if ($frozen !== '') {
            return $frozen;
        }

        return $this->mayFallBackToLiveCustomer()
            ? (trim((string) $this->customer?->name) ?: null)
            : null;
    }

    /**
     * The counterpart's country as a normalised ISO-3166-1 alpha-2, or null when it
     * cannot be resolved from a source this document is allowed to read.
     *
     * A country PRESENT on the document is authoritative even when it does not
     * normalise: falling through to the customer there let a snapshot reading
     * «Germania» be quietly replaced by the customer's «GR», which is the
     * recorded-value-is-evidence rule (MYD-011 round 7) broken again.
     */
    public function counterpartCountryIso(): ?string
    {
        if (filled($this->country)) {
            return IsoCountry::tryNormalise($this->country);
        }

        return $this->mayFallBackToLiveCustomer()
            ? $this->customer?->isoCountryCode()
            : null;
    }

    /**
     * THE country this document files — one definition, used by the AADE payload,
     * the provider payload and the freeze, so they cannot drift (the first cut kept
     * this policy in the builder and a second copy in the helper, and the two
     * disagreed within one commit).
     *
     * Three outcomes, and telling them apart is the whole point:
     *
     *  - resolvable from a source we may read → that country;
     *  - NO country evidence of any kind → «GR». Unlike a delivery note (MYD-011)
     *    this default is safe: the per-type check in the builder still enforces the
     *    domestic/EU/third-country split, and `invoices.country` is blank on most
     *    legacy domestic rows, which would otherwise all become unissuable;
     *  - evidence exists but this document may not use it → THROW.
     *
     * "Evidence" deliberately includes the ΑΦΜ's own country prefix. An invoice
     * whose snapshot reads «IT12345678901» with a soft-deleted customer has no
     * country column anywhere — but it is plainly not a domestic party, and filing
     * it as GR is the misreport this whole issue exists to stop.
     *
     * @throws RuntimeException when a country exists that this document must not read
     */
    public function counterpartCountryForFiling(): string
    {
        $prefix = Afm::countryPrefix($this->counterpartAfm());

        // A RECORDED country wins, even when the ΑΦΜ's prefix names a different one.
        // Round 4 threw on that disagreement as a "coherence" check and it was wrong:
        // the two legitimately differ (Monaco files under an FR VAT id, the Isle of
        // Man under GB, Northern Ireland under XI), and the recorded country is the
        // operator's explicit statement about the party while the prefix is an
        // inference from a free-text column. Refusing there blocked correct documents
        // and told the operator to "correct" a value that was already right. The
        // prefix stays what it should always have been: evidence for the case where
        // NOTHING is recorded, below.
        if ($iso = $this->counterpartCountryIso()) {
            return $iso;
        }

        // Present on the document but unresolvable — throw with the offending value.
        if (filled($this->country)) {
            return IsoCountry::normalise($this->country);
        }

        // Nothing is recorded, so the ΑΦΜ is the only thing that knows. A GR prefix is
        // positive evidence and ANSWERS the question — falling through to the refusal
        // below discarded it and demanded a country the document already implied.
        if ($prefix !== null) {
            if ($prefix === 'GR') {
                return 'GR';
            }

            throw new RuntimeException(
                "Invoice {$this->invcode} records no counterpart country, but its ΑΦΜ "
                ."«{$this->counterpartAfm()}» is a {$prefix} VAT identifier. "
                .'Set «Χώρα» on the invoice — a foreign party must not be filed as GR.'
            );
        }

        // A country exists on the linked customer that this document cannot use. THREE
        // reasons reach here and they need different remedies — an earlier cut offered
        // only two, so an unresolvable customer country was reported as a party
        // mismatch that did not exist, and (unlike the code this replaced) the
        // offending value was not even named.
        if (filled($this->customer?->country)) {
            $raw = $this->customer->country;

            throw new RuntimeException(
                "Invoice {$this->invcode} records no counterpart country of its own, and its "
                ."linked customer's country cannot be used for it — "
                .match (true) {
                    $this->hasBeenFiled() => 'the document is already filed, so its own snapshot is the only source.',
                    ! $this->counterpartIsTheLinkedCustomer() => 'the invoice names a different party than that customer.',
                    default => "the customer's «{$raw}» is not a country this system recognises.",
                }
                .' Set «Χώρα» on the invoice — filing it as GR on a guess is exactly what this refuses.'
            );
        }

        // Nothing anywhere — the one sanctioned default.
        return 'GR';
    }

    /**
     * The party-snapshot columns to FREEZE at the moment this invoice is filed.
     *
     * A legacy/ETL row can reach submission with the snapshot blank, so the
     * counterpart is resolved from the linked customer — and the same write sets
     * `mydata_sent`, which CLOSES that fallback. Without freezing, the party we
     * actually reported becomes unreadable the instant it is filed, exactly as the
     * delivery-note country did before MYD-011.
     *
     * Covers the ADDRESS too, not just the identity: a non-GR counterpart files
     * street/city/postcode, so freezing only ΑΦΜ/name/country left a filed document
     * unable to reproduce its own counterpart (it then threw "requires a full
     * address" on re-render, while AADE held the real one).
     *
     * Skipped entirely for a RETAIL (11.x) document: AADE files no counterpart at
     * all there, so stamping one into the "what we reported" columns would assert a
     * party that was never declared — and would make the PDF start printing it.
     *
     * Fills ONLY blanks; never overwrites a value the document already carries.
     * Every value is truncated to its column width: `customers.name` is varchar(191)
     * while `company_name` is varchar(120), and MySQL runs in strict mode, so an
     * over-long copy would raise inside the same transaction as the MARK audit row —
     * rolling back a filing AADE had already accepted and leaving the invoice
     * permanently stuck.
     *
     * Returned as columns so a submitter can merge them into the SAME forceFill as
     * the MARK rather than doing a second, racy save. Both the direct myDATA and the
     * provider path use this one definition.
     *
     * @return array<string, string>
     */
    public function frozenPartyColumns(): array
    {
        if ($this->filesNoCounterpart()) {
            return [];
        }

        try {
            $country = $this->counterpartCountryForFiling();
        } catch (RuntimeException) {
            // The document is about to be refused anyway — freeze nothing.
            return [];
        }

        $live = $this->mayFallBackToLiveCustomer() ? $this->customer : null;

        // column => [resolved value, column width]. The COUNTRY is taken from the
        // filing policy, not from counterpartCountryIso(): the sanctioned «GR»
        // default lives only there, so leaving it unfrozen meant a later, routine
        // edit to customers.country made an ALREADY FILED invoice un-renderable.
        $candidates = [
            'vat_no' => [$this->counterpartAfm(), 20],
            'company_name' => [$this->counterpartName(), 191],
            'country' => [$country, 60],
        ];

        // The address is frozen for EVERY counterpart, not just a foreign one. AADE
        // omits it for a GR party, but the PROVIDER document carries it either way
        // (InvoSign prints it) and so does our own PDF — so a GR invoice that did not
        // freeze it could not reproduce its own provider payload afterwards, which is
        // the same "unreadable once filed" failure, one surface over. Not freezing it
        // for a foreign party had already produced the harder version of that bug:
        // a re-render threw "requires a full address" while AADE held the real one.
        $candidates += [
            'address1' => [$live?->address1, 60],
            'address2' => [$live?->address2, 60],
            'city' => [$live?->city, 60],
            'postcode' => [$live?->postcode, 10],
            'occupation' => [$live?->occupation, 120],
            'vies_vat' => [$live?->vat_vies, 30],
        ];

        $frozen = [];
        foreach ($candidates as $column => [$resolved, $width]) {
            if ($this->partyColumnNeedsFreezing($column) && filled($resolved)) {
                $frozen[$column] = mb_substr((string) $resolved, 0, $width);
            }
        }

        return $frozen;
    }

    /**
     * Does this snapshot column carry nothing USABLE, so the resolved value should be
     * written into it?
     *
     * `blank()` alone is not that question for `vat_no`. Once uniqueKey() started
     * reading an all-zeros placeholder as "no ΑΦΜ", a snapshot holding «000000000»
     * was no longer blank yet no longer an identity either — so the payload filed the
     * customer's real ΑΦΜ while the column kept the placeholder. That is precisely the
     * half-frozen legal identity this whole issue exists to eliminate, manufactured by
     * its own freeze: the PDF then printed one party while AADE held another, every
     * later render threw, and the row was unrecoverable because a filed invoice is not
     * editable and credit notes copy the column verbatim.
     */
    private function partyColumnNeedsFreezing(string $column): bool
    {
        if ($column === 'vat_no') {
            return Afm::uniqueKey($this->vat_no) === null;
        }

        // The address-ish columns are selected with `?:` by BOTH the AADE builder and
        // the provider document, and `?:` treats the string «0» as absent while
        // blank() does not. That disagreement filed the customer's postcode while the
        // freeze kept the «0» — leaving the filed document unable to render its own
        // counterpart again, the exact failure this freeze exists to prevent. Mirror
        // the selector instead of guessing at it.
        if (in_array($column, ['address1', 'address2', 'city', 'postcode', 'occupation', 'vies_vat'], true)) {
            // EXACTLY `?:`, not an approximation of it: `?:` is falsy for '' and '0'
            // but NOT for ' ', so trimming here froze the customer's real street while
            // the provider payload carried the blank one.
            return ($this->{$column} ?: '') === '';
        }

        return blank($this->{$column});
    }

    /**
     * Does this document file NO counterpart at all? True for retail (11.x), where
     * AADE forbids one even when the customer has an ΑΦΜ.
     */
    public function filesNoCounterpart(): bool
    {
        // The RELATION first, because that is what AadeInvoiceDocument::build() files
        // from — reading the cache first gave a second, divergent definition of "is
        // this retail?" inside a change whose whole thesis is one definition.
        $type = (string) ($this->invoiceType?->mydata_type ?: $this->mydata_type);

        return str_starts_with($type, '11.');
    }

    /**
     * The counterpart establishment (εγκατάσταση) that ACTUALLY reaches AADE —
     * the ONE definition shared by the filing (AadeInvoiceDocument) and every
     * display, so the two can't drift and show a branch that was never filed.
     *
     * `counterpart_branch` is what the operator typed; this is what survives the
     * filing rules: 0 when the document files no counterpart at all (retail 11.x),
     * and 0 for a foreign party — a branch is a Greek Μητρώο concept, so a non-GR
     * counterpart has none. Defensive on the country resolve (a document about to
     * be refused throws there): treat an unresolvable country as "not GR" → 0.
     */
    public function filedCounterpartBranch(): int
    {
        $branch = (int) ($this->counterpart_branch ?? 0);
        if ($branch === 0 || $this->filesNoCounterpart()) {
            return 0;
        }

        try {
            return $this->counterpartCountryForFiling() === 'GR' ? $branch : 0;
        } catch (RuntimeException) {
            return 0;
        }
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
        // Deterministic insertion order everywhere the lines are read (PDF, the
        // invoice view «Γραμμές» section, breakdowns) — a bare hasMany has no ORDER
        // BY, so row order would be storage-engine dependent.
        return $this->hasMany(InvoiceLine::class)->orderBy('id');
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

    /**
     * The FILED WHMCS bridge row that links this invoice to its WHMCS invoice
     * (forward-only; at most one per invoice). Used by the WHMCS payment-sync
     * worklist to show/act on the WHMCS side.
     */
    public function whmcsPending(): HasOne
    {
        return $this->hasOne(PendingWhmcsInvoice::class, 'invoice_id')
            ->where('status', PendingWhmcsInvoice::STATUS_FILED);
    }

    /** If this invoice IS a credit note, the original it credits. */
    public function creditedInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'credited_invoice_id');
    }

    /**
     * PROV-019: if this invoice is a reissue/replacement (created by
     * ReissueInvoiceAsDraft / «Ακύρωση & επανέκδοση»), the original it replaces.
     */
    public function reissuedFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reissued_from_invoice_id');
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
     * PROV-019: is this original LEGALLY reversed — safe to present as «ακυρώθηκε»
     * and safe to re-bill — as opposed to merely reduced by a still-draft credit?
     *
     * `isFullyCredited()` is the LOCAL commercial measure: it counts issued-but-
     * unfiled (draft) correlated credits, which is correct for the ledger and for
     * off-mode tenants that never reach VALID. But a draft credit is NOT a legal
     * reversal at AADE — the original's turnover is still standing there. This
     * predicate separates the two so the badge / PDF / notifications never claim a
     * legal cancellation that hasn't happened.
     *
     * Legally reversed when:
     *   - the original was cancelled directly at AADE (mydata_state=CANCELLED —
     *     terminal on its own); OR
     *   - it is fully credited AND either it never was a live AADE filing
     *     (mydata_state ≠ VALID → off-mode/draft: local full-credit IS the
     *     reversal, and there is no standing turnover to contradict it), OR every
     *     LIVE correlated credit note is itself VALID at AADE.
     *
     * Legacy-safe: this only ever evaluates for CORRELATED credits
     * (credited_invoice_id → our IssueCreditNote path); legacy ΠΙΣ/returns import
     * as STANDALONE credit docs with no correlation, so a null credit state here
     * unambiguously means «our draft, not filed», never «legacy, state unknown».
     */
    public function isLegallyReversed(): bool
    {
        if ($this->credited_invoice_id !== null) {
            return false; // a credit note is not itself "reversed"
        }
        if ($this->mydata_state === 'CANCELLED') {
            return true; // terminal AADE cancellation
        }
        if (! $this->isFullyCredited()) {
            return false; // not (fully) reduced commercially
        }
        if ($this->mydata_state !== 'VALID') {
            return true; // never a live AADE filing → local full-credit IS the reversal
        }

        // Live at AADE: reversed only when NO live correlated credit is still
        // un-filed (null / non-VALID). A CANCELLED credit is excluded by live().
        $hasUnfiledLiveCredit = InvoiceScope::live($this->creditNotes())
            ->where(fn ($q) => $q->whereNull('mydata_state')->orWhere('mydata_state', '!=', 'VALID'))
            ->exists();

        return ! $hasUnfiledLiveCredit;
    }

    /**
     * PROV-019: this invoice is a replacement (reissued_from set) whose reversed
     * original is STILL STANDING at AADE — the original is VALID and not yet
     * legally reversed (its cancelling credit is an un-filed draft). Filing this
     * replacement now would declare the turnover twice; the UI soft-warns on it.
     */
    public function replacementReversalPending(): bool
    {
        if ($this->reissued_from_invoice_id === null) {
            return false;
        }

        $original = $this->reissuedFrom; // lazy relation load

        return $original !== null
            && $original->mydata_state === 'VALID'
            && ! $original->isLegallyReversed();
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

    // ---- MovableDocument (Combined ΤΔΑ, Slice 3c) ---------------------------
    // A 1.1 invoice with `is_delivery_note = true` (a ΤΔΑ) drives the SAME movement
    // lifecycle as a 9.x DeliveryNote (RegisterTransfer → refresh → ConfirmReturn),
    // via DeliveryLifecycleService typed against the contract. The movement audit is
    // the POLYMORPHIC delivery_marks/delivery_note_events (morph `movable` = Invoice),
    // DISTINCT from the monetary `mydataMarks()` HasMany. The tenant/stock seams route
    // to the Invoice-typed helpers; cancel is NOT here — a ΤΔΑ cancels through the
    // monetary path (MyDataSubmitter::cancel → finaliseCancellation), §7.

    public function movementMarks(): MorphMany
    {
        return $this->morphMany(DeliveryMark::class, 'movable');
    }

    public function movementEvents(): MorphMany
    {
        return $this->morphMany(DeliveryNoteEvent::class, 'movable')->orderBy('event_timestamp');
    }

    public function assertMovementTenant(Company $tenant): void
    {
        TenantCoherence::assertInvoice($tenant, $this);
    }

    public function reverseMovementStock(): void
    {
        app(StockService::class)->reverseSaleForInvoice($this);
    }

    /** Request-scoped memo for latestProviderMark() (not an attribute). */
    private bool $providerMarkResolved = false;

    private ?MyDataMark $providerMarkCache = null;

    /**
     * The mark of the CURRENT, LIVE provider filing, or null (PROV-003).
     *
     * «Current + live» = the invoice is VALID at myDATA AND a PROVIDER_INSERT mark
     * whose MARK equals the live mirror `mydata_mark`. The VALID gate matters: a
     * provider invoice cancelled at AADE keeps its provider MARK on the mirror, but
     * it is no longer a live provider document — so its evidence must vanish from
     * BOTH the page and the PDF (the PDF already gates on VALID; this keeps the two
     * in step). The mark-equality gate drops a stale provider mark after a
     * provider→direct re-file. This is the ONE selector the PDF renderer and the
     * invoice page share — screen and print never disagree — and it resolves the
     * whole evidence block in a single query.
     *
     * Memoised for this instance's lifetime (the infolist reads several fields off
     * it per render). The cache is a private property, so a NEW instance
     * (fresh()/replicate()) re-resolves; note refresh() mutates in place and does
     * NOT reset it, so a caller that mutates the marks and re-reads on the same
     * object should re-load it instead.
     */
    public function latestProviderMark(): ?MyDataMark
    {
        if ($this->providerMarkResolved) {
            return $this->providerMarkCache;
        }
        $this->providerMarkResolved = true;

        // Not VALID (cancelled / unfiled / in-doubt) → no live provider evidence.
        if ($this->mydata_state !== 'VALID' || blank($this->mydata_mark)) {
            return $this->providerMarkCache = null;
        }

        $mark = $this->mydataMarks()
            ->where('mydata_action', 'PROVIDER_INSERT')
            ->whereNotNull('mark')
            ->orderByDesc('mark_date')
            ->orderByDesc('mark_time')
            ->orderByDesc('id')
            ->first();

        if ($mark === null || (string) $mark->mark !== (string) $this->mydata_mark) {
            return $this->providerMarkCache = null;
        }

        return $this->providerMarkCache = $mark;
    }

    /** Request-scoped memo for providerEvidence() (not an attribute). */
    private bool $providerEvidenceResolved = false;

    /** @var array<string, mixed>|null */
    private ?array $providerEvidenceCache = null;

    /**
     * The provider (ΥΠΑΗΕΣ) evidence to show for this document, or null when it
     * has none / must not show one (PROV-003). The SINGLE source both the PDF and
     * the invoice page read, so print and screen apply identical gates (VALID,
     * not-cancelled, current provider mark, licence present) — see
     * `App\Support\EInvoice\ProviderEvidence`. Memoised for this instance (the
     * page reads several fields off it per render); see latestProviderMark() on
     * the memo's refresh() caveat.
     *
     * @return array<string, mixed>|null
     */
    public function providerEvidence(): ?array
    {
        if ($this->providerEvidenceResolved) {
            return $this->providerEvidenceCache;
        }
        $this->providerEvidenceResolved = true;

        return $this->providerEvidenceCache = ProviderEvidence::resolve($this);
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
        // MON-9: mirror scopeOverdue — exclude credit notes (correlated AND
        // standalone legacy is_credit) so a ΠΙΣ never reads as overdue.
        if ($this->local_status !== 'active' || $this->isCreditNote()) {
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

        // Informal (non-fiscal): never a receivable — so never overdue, never dunned
        // (a renewal of our own internal service must not suspend it). Checked last:
        // it may cost a type lookup, the column checks above are free.
        return $due->lt($asOf ?? Carbon::today()) && ! $this->isInformal();
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

        // MON-9: "non-credit-note" must also drop standalone legacy ΠΙΣ (is_credit
        // type, no credited_invoice_id) — otherwise a credit note surfaces as an
        // overdue receivable and gets dunned (invoices:notify-overdue).
        InvoiceScope::excludeCreditNotes($query);

        return InvoiceScope::live($query, 'invoices.');
    }

    /**
     * The myDATA «Outbox»: NATIVE (ekdosi-created) live παραστατικά that SHOULD be
     * filed to AADE but carry no MARK yet — a draft awaiting οριστικοποίηση+υποβολή,
     * or a finalized invoice whose submission never landed. Predicate: filable type
     * (`invoice_types.mydata_type` set), NOT cancelled, no `mydata_mark`, AND NOT
     * imported (`legacy_id` null). The legacy_id guard is essential: pre-myDATA /
     * ΕΑΦΔΣΣ-era imported invoices land active with a NULL mark and can't be re-filed
     * from here — without the guard they'd flood the Outbox. Imported docs belong to
     * the legacy lifecycle, never this worklist.
     */
    public function scopeAwaitingMyData(Builder $query): Builder
    {
        return $query
            ->whereNull('legacy_id')
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
     * the NON-electronic issue path. True only when:
     *   - the tenant does NOT file electronically — a tenant that files, whether
     *     DIRECT to myDATA (gr-mydata, sandbox/production) OR through a PROVIDER
     *     (gr-provider, InvoSign…), gets the mail on the acceptance/VALID response
     *     instead, so firing here too would double-send. Keyed on the canonical
     *     {@see SendChannel} brain so «files electronically» has ONE definition
     *     (a provider tenant has mydata_mode='off', so a raw mydata_mode check
     *     alone would miss it — the exact double-send hazard this guards);
     *   - the tenant opted in (companies.auto_email_on_issue); and
     *   - the customer hasn't opted out (customers.auto_email_invoices).
     */
    public function shouldAutoEmailOnFinalize(): bool
    {
        $company = $this->company;

        if ($company === null || $this->isInformal()) {
            return false; // an informal document is never e-mailed to the customer
        }

        $channel = SendChannel::fromCompany($company);
        $filesElectronically = SendChannel::isDirectMyData($channel) || SendChannel::isProvider($channel);

        return ! $filesElectronically
            && (bool) $company->auto_email_on_issue
            && $this->customerAcceptsAutoEmail();
    }
}
