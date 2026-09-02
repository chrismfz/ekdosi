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
use Illuminate\Support\Facades\Log;

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
        // the customer set in WHMCS. Both normalised to digits-only so "EL123…"
        // and "123…" collapse to the same stored value.
        $afm = Afm::normalise($afmOverride) ?? $pending->whmcsAfm();
        if ($afm === null) {
            return new WhmcsCustomerCreateResult(null, false, 'no_afm');
        }

        // withTrashed: a soft-deleted owner holds the ΑΦΜ (UNIQUE covers it) —
        // never a raw unique error; tell the operator to restore instead.
        $existing = Customer::withTrashed()
            ->where('company_id', $tenant->id)
            ->whereAfmKeyOf($afm)
            ->first();
        if ($existing !== null && $existing->trashed()) {
            return new WhmcsCustomerCreateResult($existing, false, 'deleted_owner');
        }
        if ($existing !== null) {
            // Establish the operator-confirmed WHMCS link if missing; never
            // overwrite an existing one.
            if (blank($existing->whmcs_client_id) && $pending->whmcs_userid) {
                $existing->forceFill(['whmcs_client_id' => $pending->whmcs_userid])->save();
            }

            return new WhmcsCustomerCreateResult($existing, false, 'existing');
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
        ]);

        // When GSIS resolved, surface fields where the OFFICIAL value differed
        // from what the customer typed in WHMCS (GSIS won). The operator sees
        // exactly what was corrected — e.g. a half-typed επωνυμία.
        $discrepancies = $record !== null
            ? self::diffFields($record, $pending, $p, $activity)
            : [];

        return new WhmcsCustomerCreateResult($customer, true, $source, $discrepancies);
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
