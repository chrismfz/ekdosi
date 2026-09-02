<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasInternalNotes;
use App\Models\Concerns\TracksActivity;
use App\Support\IsoCountry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
class DeliveryNote extends Model
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

        if ($stored === self::INTERNAL_MOVEMENT_AFM) {
            return null;
        }

        return $stored !== '' ? $stored : ($this->customer?->afm ?: null);
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
     */
    public function recipientIsTheLinkedCustomer(): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        $afm = $this->externalRecipientAfm();
        if ($afm !== null && self::vatKey($afm) !== self::vatKey($customer->afm)) {
            return false;
        }

        $name = trim((string) $this->recipient_name);

        return $name === '' || $name === trim((string) $customer->name);
    }

    /**
     * Comparison key for the identity check above: separators and case folded away,
     * but LETTERS KEPT.
     *
     * Deliberately not Afm::digits(), which strips everything non-numeric: that turns
     * the German VAT id «DE811234567» into «811234567», which then matches a Greek
     * customer's ΑΦΜ — so a German party inherited that customer's country and was
     * filed as GR, the MYD-011 misreport with its own DE prefix sitting in the same
     * counterpart as contrary evidence. A country prefix is evidence, not noise.
     *
     * The migration's SQL mirror (`orWhereColumn`) is a plain column comparison, so
     * the two are CLOSE but not identical, in both directions — and both directions
     * fail safe:
     *  - SQL is stricter on separators («123 456 789» vs «123456789» matches here,
     *    not there) → the row is simply not pre-filled, and the read-time fallback
     *    still covers it, because that same predicate means the note is unfiled;
     *  - SQL is looser on the NAME, since `utf8mb4_unicode_ci` is case- and
     *    accent-insensitive («ΑΦΟΙ ΠΑΠΑΔΟΠΟΥΛΟΥ ΑΕ» = «Αφοί Παπαδόπουλου ΑΕ») → it
     *    pre-fills for the same party spelled differently, which is right.
     * A prefixed ΑΦΜ («EL…», or the Greek-letter «ΕΛ…») against a bare one is a
     * deliberate false negative here: the note is refused until someone sets a
     * country, rather than inheriting one on a guess.
     */
    private static function vatKey(?string $raw): string
    {
        return mb_strtoupper(preg_replace('/[\s.\-]+/u', '', trim((string) $raw)) ?? '');
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

        return IsoCountry::tryNormalise($this->customer?->country);
    }

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'dispatch_at' => 'datetime',
            'move_purpose' => 'integer',
            'transport_type' => 'integer',
            'start_shipping_branch' => 'integer',
            'complete_shipping_branch' => 'integer',
            'third_party_collection' => 'boolean',
            'printed' => 'boolean',
            'mydata_sent' => 'boolean',
        ];
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

    public function marks(): HasMany
    {
        return $this->hasMany(DeliveryMark::class);
    }

    /**
     * AADE-reported lifecycle history (§4.1) — the carrier/recipient timeline,
     * synced by DeliveryLifecycleService::syncLifecycleHistory on refreshStatus.
     * Ordered oldest→newest so the View reads as a timeline.
     */
    public function events(): HasMany
    {
        return $this->hasMany(DeliveryNoteEvent::class)->orderBy('event_timestamp');
    }

    public function latestMark(): HasOne
    {
        return $this->hasOne(DeliveryMark::class)->latestOfMany();
    }

    /**
     * The myDATA «Outbox» for delivery notes: NATIVE δελτία that should be
     * registered to AADE but carry no MARK yet (draft awaiting issue, or a
     * registration that never landed). Predicate: a filable type (`mydata_type`
     * set), not cancelled, no `mydata_mark`, AND not imported (`legacy_id` null).
     * Mirrors Invoice::scopeAwaitingMyData.
     */
    public function scopeAwaitingMyData(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query
            ->whereNull('legacy_id')
            ->whereNotNull('mydata_type')
            ->where('local_status', '!=', 'cancelled')
            ->whereNull('mydata_mark');
    }
}
