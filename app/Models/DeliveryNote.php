<?php

namespace App\Models;

use App\Contracts\MovableDocument;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\TracksActivity;
use App\Services\Stock\StockService;
use App\Support\Afm;
use App\Support\DocumentSeries;
use App\Support\IsoCountry;
use App\Support\ProvisionalCode;
use App\Support\Tenancy\TenantCoherence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Παραστατικό Διακίνησης (Δελτίο Αποστολής). Value-less — carries no money/VAT,
 * so it never enters InvoiceScope or the money services. Issued via the same
 * SendInvoices path as invoices (a 9.x type); the delivery lifecycle layer
 * (RegisterTransfer/ConfirmDeliveryOutcome/…) drives `delivery_state` + the
 * `*_mark` columns.
 *
 * The myDATA cache (`mydata_*`) + the lifecycle marks are NOT fillable — written
 * ONLY by the submitter / lifecycle service via forceFill, alongside their
 * `delivery_marks` audit row. Same guard as Invoice's mydata cache.
 */
class DeliveryNote extends Model implements MovableDocument
{
    use BelongsToCompany;
    use HasAttachments;
    use HasFactory;
    use HasInternalNotes;
    use SoftDeletes;
    use TracksActivity;

    /**
     * Business columns worth auditing — never the money-less doc's churn. We log
     * mydata_state/mydata_mark (set once on issue/cancel — meaningful events, as
     * on Invoice) but NOT delivery_state: that column is re-forceFilled on every
     * AADE status poll (DeliveryLifecycleService::refreshStatus), so logging it
     * would spam «Ιστορικό» with sync flips — exactly the cache-column churn the
     * TracksActivity house rule excludes. Each lifecycle transition is already
     * captured as its own DeliveryMark row.
     *
     * @return list<string>
     */
    protected function loggedAttributes(): array
    {
        return [
            'code', 'customer_id', 'delivery_type_id', 'invoice_id', 'issued_at',
            'recipient_name', 'recipient_afm', 'recipient_country',
            'move_purpose', 'local_status', 'mydata_state', 'mydata_mark',
        ];
    }

    protected $fillable = [
        'company_id',
        'legacy_id',
        'invcode',
        'series',
        'code',
        'delivery_type_id',
        'customer_id',
        'invoice_id',
        'issued_at',
        'mydata_type',
        'move_purpose',
        'other_move_purpose_title',
        'distribution_aim_id',
        'delivery_method_id',
        'dispatch_at',
        'vehicle_number',
        'transport_type',
        'carrier_afm',
        'non_obligated_recipient',
        'third_party_collection',
        'loading_street',
        'loading_number',
        'loading_postcode',
        'loading_city',
        'start_shipping_branch',
        'delivery_street',
        'delivery_number',
        'delivery_postcode',
        'delivery_city',
        'complete_shipping_branch',
        'recipient_name',
        'recipient_afm',
        'recipient_country',
        'local_status',
        'printed',
        'notes',
    ];

    /**
     * AADE's placeholder recipient ΑΦΜ for an ενδοδιακίνηση (Α.1123/2024 Παρ. ΙΙ §3).
     */
    public const INTERNAL_MOVEMENT_AFM = '000000000';

    /**
     * The recipient's ΑΦΜ when there is a real EXTERNAL party, else null.
     *
     * A stored «000000000» is the operator's explicit «this is an internal
     * movement» — it must not read as an external ΑΦΜ, and it must NOT fall back
     * to a linked customer's ΑΦΜ either (that would silently file a different
     * legal counterpart than the one declared).
     */
    public function externalRecipientAfm(): ?string
    {
        $stored = trim((string) $this->recipient_afm);

        // Any all-zeros value, not only the exact nine-zero sentinel: «0» meant the
        // same thing to the operator, and Afm::uniqueKey() already reads it that
        // way — leaving the two strictnesses apart let «0» read as a real ΑΦΜ here
        // while every identity comparison treated it as absent.
        if (Afm::isZeroPlaceholder($stored)) {
            return null;
        }

        // JUNK («-», «.») is NOT that declaration. Treating it as one turned a hard
        // refusal into a silently-filed ενδοδιακίνηση, and made a linked customer's
        // KNOWN ΑΦΜ be replaced by the «no ΑΦΜ» placeholder — a guess, where MYD-011's
        // whole posture is to refuse. It falls through to the real identity instead.
        if ($stored !== '' && Afm::uniqueKey($stored) === null) {
            $stored = '';
        }

        return $stored !== ''
            ? $stored
            // Canonicalised: a customer row holding «00000» is the same placeholder,
            // and reading it verbatim here filed it as though it were an ΑΦΜ.
            : (Afm::isZeroPlaceholder($this->customer?->afm) ? null : ($this->customer?->afm ?: null));
    }

    /**
     * Is this an ενδοδιακίνηση — the issuer moving its own goods, with no external
     * recipient at all?
     *
     * ONE definition, shared by the AADE payload, the PDF, the CMR and the provider
     * document, so they cannot disagree about the same note. Keying on the ΑΦΜ alone
     * would misclassify a named foreign party with no ΑΦΜ as internal (MYD-011).
     */
    public function isInternalMovement(): bool
    {
        // Junk in the ΑΦΜ («-», «.») is an identity ATTEMPT that failed, not the
        // «this party has no ΑΦΜ» declaration — so it must never classify the note as
        // an ενδοδιακίνηση. Without this, a note carrying junk and no other identity
        // was filed as "the issuer moved its own goods" (discarding the country the
        // form MADE the operator pick) where it used to be refused outright. Only a
        // blank or an all-zeros value is the declaration.
        $stored = trim((string) $this->recipient_afm);
        if ($stored !== '' && ! Afm::isZeroPlaceholder($stored)) {
            return false;
        }

        // The sentinel is NOT an override — it is simply "no ΑΦΜ", and it is the
        // only placeholder the UI offers for a party that has none. Treating a
        // stored 000000000 as an unconditional internal declaration filed a NAMED
        // foreign recipient (with an explicitly picked country) as the issuer.
        // Internal means no recipient identity of ANY kind.
        return $this->externalRecipientAfm() === null
            && ! filled($this->recipient_name)
            && $this->customer_id === null;
    }

    /**
     * Has this note already been transmitted? Both submit paths (direct myDATA and
     * the provider transport) forceFill `mydata_sent` + `mydata_mark` together, so
     * either one answers it; both are checked because a rejected-then-repaired note
     * can carry the flag without a MARK.
     */
    public function hasBeenFiled(): bool
    {
        return $this->mydata_sent || filled($this->mydata_mark);
    }

    /**
     * Is the recipient actually the LINKED CUSTOMER, rather than someone else typed
     * over the top of a customer pick?
     *
     * `customer_id` is a Hidden field the form never clears, so an operator can pick
     * a customer and then overtype «Επωνυμία/ΑΦΜ παραλήπτη» with a different party.
     * The customer relation is then a stale link, not a description of the recipient
     * — and inheriting its country from there is the same guess as the inferences
     * removed in round 8, just by another route (MYD-011).
     *
     * The sentinel reads as "this party has no ΑΦΜ" — externalRecipientAfm() maps it
     * to null and stops there (it does NOT go on to the customer's own ΑΦΜ; only a
     * BLANK does). A null either way skips the comparison, and a blank name means
     * the payload files the customer's name, so both still read as "this IS the
     * customer".
     *
     * The ΑΦΜ comparison keeps LETTERS (Afm::uniqueKey, shared with the invoice
     * counterpart check in MYD-009) — a digit-strip turns «DE811234567» into
     * «811234567», which matches a Greek customer's ΑΦΜ, and the German party then
     * inherits that customer's country and files as GR.
     *
     * The migration's SQL mirror (`orWhereColumn`) is a plain column comparison, so
     * the two are CLOSE but not identical, in both directions — and both fail safe:
     *  - SQL is stricter on separators («123 456 789» vs «123456789» matches here,
     *    not there) → the row is simply not pre-filled, and the read-time fallback
     *    still covers it, because that same predicate means the note is unfiled;
     *  - SQL is looser on the NAME, since `utf8mb4_unicode_ci` is case- and
     *    accent-insensitive («ΑΦΟΙ ΠΑΠΑΔΟΠΟΥΛΟΥ ΑΕ» = «Αφοί Παπαδόπουλου ΑΕ») → it
     *    pre-fills for the same party spelled differently, which is right.
     * An «EL…»/«ΕΛ…» prefixed ΑΦΜ against a bare one is the SAME taxpayer and matches
     * (uniqueKey canonicalises first) — `customers.afm` legitimately carries the
     * prefix, since the VIES form-fill seeds a full VAT id. A FOREIGN prefix survives
     * canonicalisation, so «DE811234567» still does not match a Greek «811234567».
     */
    public function recipientIsTheLinkedCustomer(): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        $afm = $this->externalRecipientAfm();
        if ($afm !== null && Afm::uniqueKey($afm) !== Afm::uniqueKey($customer->afm)) {
            return false;
        }

        $name = trim((string) $this->recipient_name);

        return $name === '' || $name === trim((string) $customer->name);
    }

    /**
     * The recipient's country as a normalised ISO-2, or null when it cannot be
     * resolved. The frozen snapshot wins; the linked customer is only a fallback
     * for notes issued before `recipient_country` existed (MYD-011).
     *
     * The fallback is doubly narrowed, because each hole is a foreign party filed
     * as Greek:
     *  - it stops at transmission. `customers.country` is LIVE free text that gets
     *    filled in over time, while `recipient_country` records what was SUBMITTED —
     *    so reading the customer on a filed note would show the PDF, the infolist
     *    and the CMR a country the AADE record never carried, as though it were the
     *    filed one. (Same reason the migration backfill skips filed notes; guarding
     *    only the write left the read wide open.) A note being issued has no MARK
     *    yet, so pre-column drafts are still covered.
     *  - it requires the recipient to BE that customer. Otherwise a note naming
     *    «Müller GmbH / DE811234567» over a Greek customer link filed as GR — with
     *    its own DE VAT id sitting in the same counterpart as contrary evidence.
     */
    public function recipientCountryIso(): ?string
    {
        if ($iso = IsoCountry::tryNormalise($this->recipient_country)) {
            return $iso;
        }

        if ($this->hasBeenFiled() || ! $this->recipientIsTheLinkedCustomer()) {
            return null;
        }

        return $this->customer?->isoCountryCode();
    }

    protected function casts(): array
    {
        return [
            'mydata_pending_since' => 'datetime',
            'issued_at' => 'datetime',
            'dispatch_at' => 'datetime',
            'non_obligated_recipient' => 'boolean',
            'movement_checked_at' => 'datetime',
            'move_purpose' => 'integer',
            'transport_type' => 'integer',
            'start_shipping_branch' => 'integer',
            'complete_shipping_branch' => 'integer',
            'third_party_collection' => 'boolean',
            'printed' => 'boolean',
            'mydata_sent' => 'boolean',
        ];
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

        // Gapless-at-send Phase 2 (reverses MON-4 for δελτία): a note created WITHOUT
        // a real ΑΑ — the new draft flow, which no longer allocates at creation — gets
        // a PROVISIONAL identity keyed by its surrogate id («ΠΡΟΣ-ΔΑΠ-6885»), so every
        // `invcode` reader (list, infolist, PDF, CMR) shows a stable, unique,
        // clearly-provisional label. The real code/invcode/series are written later, at
        // transmission (DeliveryNoteSubmitter → InvoiceNumberer::assignDelivery). Guarded
        // on `code === null`, so ETL/import/fixtures that set a real code are untouched.
        // Post-insert (`created`) because the id is the unique token. Twin of Invoice.
        static::created(function (self $model): void {
            if ($model->code === null && blank($model->invcode)) {
                $model->invcode = ProvisionalCode::make($model->deliveryType?->code, $model->getKey());
                $model->saveQuietly();
            }
        });
    }

    /**
     * The series this document is FILED under — the frozen value, falling back to
     * the live type code only for rows numbered before it was frozen.
     *
     * Every reader (the AADE header, the provider payload, the in-doubt recovery
     * search) must go through here: reading `deliveryType->code` directly is what let a
     * lookup rename change an already-numbered document, and made recovery look
     * for a (series, ΑΑ) that AADE had never seen.
     */
    public function filedSeries(): ?string
    {
        // filled(), NOT `?:` — '0' is a legitimate series in a numeric scheme and
        // is falsy, so `?:` would silently discard the FROZEN value and fall back
        // to the live lookup: the exact bug this method exists to close.
        $series = filled($this->series) ? $this->series : $this->deliveryType?->code;

        // Normalise a blank type code to null so the readers' own fail-closed
        // guards fire, rather than filing a document under an empty series that
        // could never be matched back — at AADE or by the in-doubt recovery.
        return blank($series) ? null : (string) $series;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** The InvoiceType row (a 9.x type) that drives series + numbering. */
    public function deliveryType(): BelongsTo
    {
        return $this->belongsTo(InvoiceType::class, 'delivery_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Optional link to the sale (invoice) this δελτίο dispatches — for stock dedup. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function distributionAim(): BelongsTo
    {
        return $this->belongsTo(DistributionAim::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DeliveryNoteLine::class);
    }

    // Combined ΤΔΑ (3c): the movement audit is polymorphic (`movable_*`), so these
    // key off the morph, not delivery_note_id — a DeliveryNote row carries both (the
    // MirrorsMovableFromDeliveryNote hook), so the results are identical to the old
    // hasMany while an Invoice-backed ΤΔΑ reaches its own audit through the same seam.
    public function marks(): MorphMany
    {
        return $this->morphMany(DeliveryMark::class, 'movable');
    }

    /**
     * AADE-reported lifecycle history (§4.1) — the carrier/recipient timeline,
     * synced by DeliveryLifecycleService::syncLifecycleHistory on refreshStatus.
     * Ordered oldest→newest so the View reads as a timeline.
     */
    public function events(): MorphMany
    {
        return $this->morphMany(DeliveryNoteEvent::class, 'movable')->orderBy('event_timestamp');
    }

    public function latestMark(): MorphOne
    {
        return $this->morphOne(DeliveryMark::class, 'movable')->latestOfMany();
    }

    // ---- MovableDocument (Combined ΤΔΑ, Slice 3c) ---------------------------
    // The lifecycle service (DeliveryLifecycleService) is typed against the
    // contract so the SAME issuer lifecycle drives a 9.x note and a 1.1 ΤΔΑ
    // invoice. For a money-less DeliveryNote the movement-audit relations ARE
    // the existing morph relations; the tenant/stock seams route to the
    // DeliveryNote-typed helpers.

    public function movementMarks(): MorphMany
    {
        return $this->marks();
    }

    public function movementEvents(): MorphMany
    {
        return $this->events();
    }

    public function assertMovementTenant(Company $tenant): void
    {
        TenantCoherence::assertDeliveryNote($tenant, $this);
    }

    public function reverseMovementStock(): void
    {
        app(StockService::class)->reverseSaleForDeliveryNote($this);
    }

    /**
     * The myDATA «Outbox» for delivery notes: NATIVE δελτία that should be
     * registered to AADE but carry no MARK yet (draft awaiting issue, or a
     * registration that never landed). Predicate: a filable type (`mydata_type`
     * set), not cancelled, no `mydata_mark`, AND not imported (`legacy_id` null).
     * Mirrors Invoice::scopeAwaitingMyData.
     */
    public function scopeAwaitingMyData(Builder $query): Builder
    {
        return $query
            ->whereNull('legacy_id')
            ->whereNotNull('mydata_type')
            ->where('local_status', '!=', 'cancelled')
            ->whereNull('mydata_mark');
    }
}
