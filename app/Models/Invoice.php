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
use App\Support\Afm;
use App\Support\DocumentSeries;
use App\Support\InvoiceScope;
use App\Support\IsoCountry;
use Firebed\AadeMyData\Enums\WithheldPercentCategory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            ? IsoCountry::tryNormalise($this->customer?->country)
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

    /** Request-scoped memo for latestProviderMark() (not an attribute). */
    private bool $providerMarkResolved = false;

    private ?MyDataMark $providerMarkCache = null;

    /**
     * The CURRENT provider filing's mark, or null (PROV-003).
     *
     * «Current» = a PROVIDER_INSERT mark whose MARK equals the live mirror
     * `mydata_mark`. After a provider→direct re-file the mirror MARK is the direct
     * one, so a stale provider mark must not be presented as this document's
     * provider evidence. This is the ONE selector the PDF renderer and the invoice
     * page share, so print and screen never disagree — and it resolves the whole
     * provider-evidence block from a single query instead of one per field.
     *
     * Memoised per instance (the infolist reads several fields off it in one
     * render). The private cache is request-scoped — never part of $attributes,
     * so it doesn't serialise or leak into fresh()/replicate().
     */
    public function latestProviderMark(): ?MyDataMark
    {
        if ($this->providerMarkResolved) {
            return $this->providerMarkCache;
        }
        $this->providerMarkResolved = true;

        if (blank($this->mydata_mark)) {
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
