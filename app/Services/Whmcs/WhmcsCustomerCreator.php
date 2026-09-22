<?php

namespace App\Services\Whmcs;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\AadeRegistryLookup;
use App\Support\Afm;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Slice 3 of the WHMCS bridge-fetch work: create the ekdosi Customer for a
 * staged WHMCS invoice when its ΑΦΜ isn't in ekdosi yet — operator-triggered
 * («Δημ. πελάτη» in the inbox, AND the inline «Εισαγωγή πελάτη από ΑΦΜ» button
 * inside the «Δημιουργία Παραστατικού» modal).
 *
 * Reuses what we already have: the same ΑΦΜ-keyed find-or-create discipline as
 * ContactCustomerResolver (third-party contacts) + the GSIS registry lookup the
 * Customer form's «Άντληση/Διόρθωση από ΑΑΔΕ» uses.
 *
 * The customer the customer TYPED in WHMCS is only a fallback: when the ΑΦΜ
 * resolves in the GSIS registry, the OFFICIAL data wins (name / ΔΟΥ / address /
 * δραστηριότητα) — validating the ΑΦΜ in passing. GSIS failure (foreign ΑΦΜ,
 * registry down, bad creds) degrades to the WHMCS-typed data so the operator
 * still gets a usable row. Idempotent by ΑΦΜ (tenant-scoped). Stamps the
 * operator-confirmed whmcs_client_id link so future invoices auto-match.
 *
 * $afmOverride lets the operator type/correct the ΑΦΜ at the point of creation
 * (the modal button) — for rows where WHMCS carries no ΑΦΜ, or a wrong one the
 * customer fixed later. Null → use the ΑΦΜ the customer set in WHMCS.
 */
class WhmcsCustomerCreator
{
    public function createForPending(
        Company $tenant,
        PendingWhmcsInvoice $pending,
        ?string $afmOverride = null,
    ): WhmcsCustomerCreateResult {
        // Operator-typed ΑΦΜ (the modal button) wins when given; else the ΑΦΜ
        // the customer set in WHMCS. Both reduced to the ΑΦΜ IDENTITY (Afm::uniqueKey:
        // "EL123…" and "123…" collapse, a foreign VAT keeps its letters, a
        // placeholder is no ΑΦΜ at all).
        $afm = Afm::uniqueKey($afmOverride) ?? $pending->whmcsAfm();
        if ($afm === null) {
            return new WhmcsCustomerCreateResult(null, false, 'no_afm');
        }

        // withTrashed: a soft-deleted owner holds the ΑΦΜ (UNIQUE covers it) —
        // never a raw unique error; tell the operator to restore instead.
        $existing = Customer::afmOwnerQuery($tenant->id, $afm)->first();
        if ($existing !== null) {
            return $this->existingResult($existing, $pending);
        }

        // Authoritative GSIS lookup; degrade to WHMCS-typed data on any failure.
        $record = null;
        $source = 'whmcs';
        try {
            $record = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm);
            $source = 'aade';
        } catch (AadeAfmNotFound|AadeUnreachable|AadeCredentialsInvalid $e) {
            Log::info('WHMCS create-customer: GSIS lookup failed — using WHMCS-typed data.', [
                'company_id' => $tenant->id,
                'afm' => $afm,
                'reason' => $e->getMessage(),
            ]);
        }

        $p = is_array($pending->payload) ? $pending->payload : [];
        $activity = $record?->primaryActivity();

        try {
            $customer = Customer::create([
                'company_id' => $tenant->id,
                'afm' => $afm,
                'name' => self::firstFilled($record?->name, $pending->whmcsClientName(), 'ΑΦΜ '.$afm),
                'tax_office' => self::firstFilled($record?->doy, $pending->whmcsTaxOffice()),
                'address1' => self::firstFilled($record?->address, $p['address1'] ?? null),
                'address2' => self::firstFilled($p['address2'] ?? null),
                'city' => self::firstFilled($record?->city, $p['city'] ?? null),
                'postcode' => self::firstFilled($record?->postcode, $p['postcode'] ?? null),
                'country' => self::firstFilled($p['country'] ?? null, 'GR'),
                'occupation' => self::firstFilled($activity['description'] ?? null, $pending->whmcsActivity()),
                // Contact channels are WHMCS-only (GSIS doesn't expose them): email +
                // phone come straight from the WHMCS client payload.
                'email' => self::firstFilled($p['email'] ?? null),
                'phone1' => self::firstFilled($p['phonenumber'] ?? null),
                'whmcs_client_id' => $pending->whmcs_userid ?: null,
                // Seed «Άμεση τιμολόγηση» from the WHMCS «γκρινιάρης» custom field at
                // creation. WHMCS is the source of truth for this flag on WHMCS-linked
                // customers: WhmcsInvoiceIngestor MIRRORS it onto the matched customer on
                // every later ingest too, so a toggle a month later propagates. null
                // (griniaris unmapped for this tenant) → false here = the column default.
                'needs_immediate_invoice' => $pending->wantsImmediateInvoice() ?? false,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a parallel create for the same ΑΦΜ: the other
            // row IS the customer now — re-read it instead of surfacing SQL.
            $winner = Customer::afmOwnerQuery($tenant->id, $afm)->first();
            if ($winner === null) {
                throw new RuntimeException('Ο πελάτης με ΑΦΜ '.$afm.' δημιουργήθηκε ταυτόχρονα από άλλον χειριστή — ξαναπροσπάθησε.');
            }

            return $this->existingResult($winner, $pending);
        }

        // When GSIS resolved, surface fields where the OFFICIAL value differed
        // from what the customer typed in WHMCS (GSIS won). The operator sees
        // exactly what was corrected — e.g. a half-typed επωνυμία.
        $discrepancies = $record !== null
            ? self::diffFields($record, $pending, $p, $activity)
            : [];

        return new WhmcsCustomerCreateResult($customer, true, $source, $discrepancies);
    }

    /**
     * Third-party variant: create/find the ekdosi Customer for a RESOLVED
     * third-party contact — a resolve.php contact array (company_name, gr_vatno,
     * vies_vatno, tax_office, address1/2, city, postal_code, country, description,
     * email, telephone) — with the SAME AADE-first-then-WHMCS-fallback and
     * ΑΦΜ-idempotency as createForPending().
     *
     * Unlike ContactCustomerResolver (which materialises a contact using ONLY the
     * reseller-typed WHMCS fields, offline), this enriches from GSIS so a
     * manually-imported third party gets the same authoritative επωνυμία / ΔΟΥ /
     * address / δραστηριότητα as the primary. The contact's own email/phone are
     * kept (GSIS doesn't expose them). Does NOT stamp whmcs_client_id (a third
     * party is not the WHMCS client) nor the γκρινιάρης flag (that's the primary's).
     *
     * $referredByCustomerId records provenance — the ekdosi Customer of the WHMCS
     * reseller/agency that brought this third party — the SAME «συστήθηκε από»
     * link a converted lead carries (Customer.referred_by_customer_id). Null when
     * the reseller isn't an ekdosi customer. Set on create; gap-filled on an
     * existing party (never overwritten, never a self-reference).
     *
     * @param  array<string, mixed>  $contact
     */
    public function createFromContact(
        Company $tenant,
        array $contact,
        ?int $referredByCustomerId = null,
    ): WhmcsCustomerCreateResult {
        $afm = Afm::uniqueKey((string) ($contact['gr_vatno'] ?? ''));
        if ($afm === null) {
            return new WhmcsCustomerCreateResult(null, false, 'no_afm');
        }

        $existing = Customer::afmOwnerQuery($tenant->id, $afm)->first();
        if ($existing !== null) {
            if ($existing->trashed()) {
                return new WhmcsCustomerCreateResult($existing, false, 'deleted_owner');
            }
            // Find-and-enrich: backfill the reseller-supplied email/phone + the
            // provenance link when the existing party is missing them — NEVER
            // overwriting existing data. GSIS carries no email/phone, so the
            // reseller-entered ones are the only source, and the third party often
            // needs to receive the document too.
            $this->backfillContactGaps($existing, $contact, $referredByCustomerId);

            return new WhmcsCustomerCreateResult($existing, false, 'existing');
        }

        $record = null;
        $source = 'whmcs';
        try {
            $record = app(AadeRegistryLookup::class, ['tenant' => $tenant])->findByAfm($afm);
            $source = 'aade';
        } catch (AadeAfmNotFound|AadeUnreachable|AadeCredentialsInvalid $e) {
            Log::info('WHMCS create third-party customer: GSIS lookup failed — using contact data.', [
                'company_id' => $tenant->id,
                'afm' => $afm,
                'reason' => $e->getMessage(),
            ]);
        }

        $activity = $record?->primaryActivity();
        $decode = static fn ($v): ?string => is_string($v)
            ? (trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null)
            : null;

        // Cap `occupation` to the column width: the GSIS path is capped by
        // primaryActivity(), but the reseller-typed contact `description` fallback is
        // free text — an un-capped blob overflows VARCHAR(120) (SQLSTATE[22001]).
        $occupation = self::firstFilled($activity['description'] ?? null, $decode($contact['description'] ?? null));
        if ($occupation !== null) {
            $occupation = mb_substr($occupation, 0, AadeRegistryRecord::OCCUPATION_MAX_LENGTH);
        }

        try {
            $customer = Customer::create([
                'company_id' => $tenant->id,
                'afm' => $afm,
                'name' => self::firstFilled($record?->name, $decode($contact['company_name'] ?? null), 'ΑΦΜ '.$afm),
                'vat_vies' => self::firstFilled($decode($contact['vies_vatno'] ?? null)),
                'tax_office' => self::firstFilled($record?->doy, $decode($contact['tax_office'] ?? null)),
                'address1' => self::firstFilled($record?->address, $decode($contact['address1'] ?? null)),
                'address2' => self::firstFilled($decode($contact['address2'] ?? null)),
                'city' => self::firstFilled($record?->city, $decode($contact['city'] ?? null)),
                'postcode' => self::firstFilled($record?->postcode, $decode($contact['postal_code'] ?? null)),
                'country' => self::firstFilled($decode($contact['country'] ?? null), 'GR'),
                'occupation' => $occupation,
                'email' => self::firstFilled($decode($contact['email'] ?? null)),
                'phone1' => self::firstFilled($decode($contact['telephone'] ?? null)),
                'referred_by_customer_id' => $referredByCustomerId,
                'is_active' => true,
            ]);
        } catch (UniqueConstraintViolationException) {
            $winner = Customer::afmOwnerQuery($tenant->id, $afm)->first();
            if ($winner === null) {
                throw new RuntimeException('Ο πελάτης με ΑΦΜ '.$afm.' δημιουργήθηκε ταυτόχρονα από άλλον χειριστή — ξαναπροσπάθησε.');
            }
            if (! $winner->trashed()) {
                $this->backfillContactGaps($winner, $contact, $referredByCustomerId);
            }

            return new WhmcsCustomerCreateResult(
                $winner, false, $winner->trashed() ? 'deleted_owner' : 'existing'
            );
        }

        return new WhmcsCustomerCreateResult($customer, true, $source);
    }

    /**
     * Gap-fill a LIVE existing customer with reseller-supplied contact channels
     * (email/phone) and the provenance link, WITHOUT ever overwriting a value the
     * customer already has, and never as a self-reference. No-op when nothing is
     * missing (no needless write / activity-log noise).
     *
     * @param  array<string, mixed>  $contact
     */
    private function backfillContactGaps(Customer $customer, array $contact, ?int $referredByCustomerId): void
    {
        $decode = static fn ($v): ?string => is_string($v)
            ? (trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?: null)
            : null;

        $fill = [];
        $email = $decode($contact['email'] ?? null);
        if (blank($customer->email) && filled($email)) {
            $fill['email'] = $email;
        }
        $phone = $decode($contact['telephone'] ?? null);
        if (blank($customer->phone1) && filled($phone)) {
            $fill['phone1'] = $phone;
        }
        if ($referredByCustomerId !== null
            && $referredByCustomerId !== $customer->getKey()
            && blank($customer->referred_by_customer_id)) {
            $fill['referred_by_customer_id'] = $referredByCustomerId;
        }

        if ($fill !== []) {
            $customer->forceFill($fill)->save();
        }
    }

    /**
     * The ΑΦΜ already has an owner (found up-front, or the winner of a create
     * race — same outcome either way): a trashed owner is reported, never
     * linked; a live one gets the operator-confirmed WHMCS link if missing
     * (never overwriting an existing one).
     */
    private function existingResult(Customer $existing, PendingWhmcsInvoice $pending): WhmcsCustomerCreateResult
    {
        if ($existing->trashed()) {
            return new WhmcsCustomerCreateResult($existing, false, 'deleted_owner');
        }

        if (blank($existing->whmcs_client_id) && $pending->whmcs_userid) {
            $existing->forceFill(['whmcs_client_id' => $pending->whmcs_userid])->save();
        }

        return new WhmcsCustomerCreateResult($existing, false, 'existing');
    }

    /**
     * Fields where BOTH the GSIS record and the WHMCS-typed data have a value
     * and they DIFFER (content-wise, ignoring case/whitespace). A filled gap
     * (one side blank) is not a conflict — only genuine disagreements, where the
     * GSIS value was kept, are reported.
     *
     * @param  array<string, mixed>  $p  the WHMCS payload
     * @param  array{code: string, description: string, kind: string}|null  $activity
     * @return list<array{field: string, whmcs: string, aade: string}>
     */
    private static function diffFields(
        AadeRegistryRecord $record,
        PendingWhmcsInvoice $pending,
        array $p,
        ?array $activity,
    ): array {
        $pairs = [
            ['Επωνυμία', $record->name, $pending->whmcsClientName()],
            ['ΔΟΥ', $record->doy, $pending->whmcsTaxOffice()],
            ['Διεύθυνση', $record->address, $p['address1'] ?? null],
            ['Πόλη', $record->city, $p['city'] ?? null],
            ['ΤΚ', $record->postcode, $p['postcode'] ?? null],
            ['Δραστηριότητα', $activity['description'] ?? null, $pending->whmcsActivity()],
        ];

        $out = [];
        foreach ($pairs as [$label, $aade, $whmcs]) {
            $aade = is_string($aade) ? trim($aade) : '';
            $whmcs = is_string($whmcs) ? trim($whmcs) : '';
            if ($aade === '' || $whmcs === '') {
                continue;   // a filled gap is not a conflict
            }
            if (self::norm($aade) !== self::norm($whmcs)) {
                $out[] = ['field' => $label, 'whmcs' => $whmcs, 'aade' => $aade];
            }
        }

        return $out;
    }

    /** Case-/whitespace-insensitive normaliser so only real content diffs alarm. */
    private static function norm(string $s): string
    {
        return mb_strtoupper((string) preg_replace('/\s+/u', ' ', trim($s)), 'UTF-8');
    }

    /** First non-blank trimmed value, or null. */
    private static function firstFilled(?string ...$values): ?string
    {
        foreach ($values as $v) {
            $v = is_string($v) ? trim($v) : $v;
            if (filled($v)) {
                return $v;
            }
        }

        return null;
    }
}
