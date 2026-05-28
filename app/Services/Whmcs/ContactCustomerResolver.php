<?php

namespace App\Services\Whmcs;

use App\Models\Company;
use App\Models\Customer;

/**
 * T-1b (timologia v2): turn a resolved third-party contact (a
 * mod_timologia_contacts row, as returned by the bridge's resolve.php) into an
 * ekdosi Customer for the tenant — the end customer the παραστατικό is billed
 * to, instead of the WHMCS reseller.
 *
 * Idempotent by ΑΦΜ: re-running over the same contact returns the existing
 * Customer (so re-ingests don't create duplicates). Match strategy mirrors
 * WhmcsCustomerMatcher's AFM path — digit-only normalisation, tenant-scoped.
 *
 * Contacts WITHOUT an ΑΦΜ can't be materialised into a tax-valid counterpart
 * (a B2B invoice needs the recipient's ΑΦΜ); resolve() returns null and the
 * caller parks the invoice for the operator rather than inventing identity.
 *
 * WHMCS stores some contact fields HTML-entity-encoded ("Σ&amp;Φ ΟΕ"); we
 * decode here — the bridge endpoint returns them as stored, ekdosi owns the
 * presentation form.
 */
class ContactCustomerResolver
{
    /**
     * Find-or-create the ekdosi Customer for a resolved contact.
     *
     * @param  array<string, mixed>  $contact  A resolve.php contact object.
     * @return Customer|null Null when the contact has no usable ΑΦΜ.
     */
    public function resolve(Company $tenant, array $contact): ?Customer
    {
        $afm = $this->normaliseAfm((string) ($contact['gr_vatno'] ?? ''));
        if ($afm === null) {
            return null;
        }

        $existing = Customer::query()
            ->where('company_id', $tenant->getKey())
            ->where('afm', $afm)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return Customer::create([
            'company_id' => $tenant->getKey(),
            'name' => $this->decode((string) ($contact['company_name'] ?? '')) ?: ('ΑΦΜ '.$afm),
            'afm' => $afm,
            'vat_vies' => $this->decode((string) ($contact['vies_vatno'] ?? '')) ?: null,
            'tax_office' => $this->decode((string) ($contact['tax_office'] ?? '')) ?: null,
            'address1' => $this->decode((string) ($contact['address1'] ?? '')) ?: null,
            'address2' => $this->decode((string) ($contact['address2'] ?? '')) ?: null,
            'city' => $this->decode((string) ($contact['city'] ?? '')) ?: null,
            'postcode' => $this->decode((string) ($contact['postal_code'] ?? '')) ?: null,
            'country' => $this->decode((string) ($contact['country'] ?? '')) ?: 'GR',
            'occupation' => $this->decode((string) ($contact['description'] ?? '')) ?: null,
            'email' => $this->decode((string) ($contact['email'] ?? '')) ?: null,
            'is_active' => true,
        ]);
    }

    /**
     * Strip Greek-VAT prefixes + non-digit junk so "EL123456789" and
     * "123456789" collapse to the same value WhmcsCustomerMatcher stores.
     */
    private function normaliseAfm(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return ($digits === null || $digits === '') ? null : $digits;
    }

    private function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
