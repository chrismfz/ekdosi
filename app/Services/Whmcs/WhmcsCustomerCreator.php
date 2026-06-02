<?php

namespace App\Services\Whmcs;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\AadeRegistryLookup;
use Illuminate\Support\Facades\Log;

/**
 * Slice 3 of the WHMCS bridge-fetch work: create the ekdosi Customer for a
 * staged WHMCS invoice when its ΑΦΜ isn't in ekdosi yet — operator-triggered
 * («Δημ. πελάτη» in the inbox).
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
 */
class WhmcsCustomerCreator
{
    public function createForPending(Company $tenant, PendingWhmcsInvoice $pending): WhmcsCustomerCreateResult
    {
        $afm = $pending->whmcsAfm();
        if ($afm === null) {
            return new WhmcsCustomerCreateResult(null, false, 'no_afm');
        }

        $existing = Customer::query()
            ->where('company_id', $tenant->id)
            ->where('afm', $afm)
            ->first();
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
            'email' => self::firstFilled($p['email'] ?? null),
            'whmcs_client_id' => $pending->whmcs_userid ?: null,
        ]);

        return new WhmcsCustomerCreateResult($customer, true, $source);
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
