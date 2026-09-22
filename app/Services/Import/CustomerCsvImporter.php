<?php

namespace App\Services\Import;

use App\Models\Company;
use App\Models\Customer;

/**
 * Customers from CSV. Matched by ΑΦΜ identity (soft-deleted included — the
 * unique index covers them); a customer without an ΑΦΜ (retail) is matched by
 * exact name among the other ΑΦΜ-less customers.
 */
final class CustomerCsvImporter extends EntityCsvImporter
{
    /** @var array<string, int> in-file identity => first line */
    private array $seen = [];

    public function entityLabel(): string
    {
        return 'πελατών';
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Επωνυμία', 'aliases' => ['Όνομα', 'Πελάτης', 'Ονοματεπώνυμο', 'Name', 'Company', 'Company name', 'Customer']],
            'afm' => ['label' => 'ΑΦΜ', 'aliases' => ['VAT', 'VAT number', 'VAT ID', 'TIN', 'Tax ID', 'Αριθμός ΦΠΑ']],
            'tax_office' => ['label' => 'ΔΟΥ', 'aliases' => ['Tax office']],
            'occupation' => ['label' => 'Επάγγελμα', 'aliases' => ['Δραστηριότητα', 'Occupation', 'Profession']],
            'address1' => ['label' => 'Διεύθυνση', 'aliases' => ['Οδός', 'Address', 'Street', 'Address 1']],
            'address2' => ['label' => 'Διεύθυνση 2', 'aliases' => ['Address 2']],
            'city' => ['label' => 'Πόλη', 'aliases' => ['Περιοχή', 'City', 'Town']],
            'postcode' => ['label' => 'ΤΚ', 'aliases' => ['Ταχυδρομικός κώδικας', 'Ταχ. κώδικας', 'Postcode', 'Postal code', 'Zip', 'Zip code']],
            'country' => ['label' => 'Χώρα', 'aliases' => ['Country']],
            'email' => ['label' => 'Email', 'aliases' => ['E-mail', 'Ηλεκτρονικό ταχυδρομείο']],
            'phone1' => ['label' => 'Τηλέφωνο', 'aliases' => ['Τηλ', 'Phone', 'Telephone']],
            'phone2' => ['label' => 'Κινητό', 'aliases' => ['Τηλέφωνο 2', 'Mobile', 'Phone 2']],
            'vat_vies' => ['label' => 'VIES', 'aliases' => ['ΑΦΜ VIES', 'VIES VAT']],
        ];
    }

    public function sampleRow(): array
    {
        return [
            'name' => 'Παράδειγμα Α.Ε.', 'afm' => '094019245', 'tax_office' => 'ΦΑΕ ΑΘΗΝΩΝ',
            'occupation' => 'Εμπόριο', 'address1' => 'Πανεπιστημίου 1', 'city' => 'Αθήνα',
            'postcode' => '10564', 'country' => 'GR', 'email' => 'info@example.gr', 'phone1' => '2100000000',
        ];
    }

    protected function reset(): void
    {
        $this->seen = [];
    }

    protected function planRow(Company $company, array $row, PlannedRow $planned): void
    {
        $country = $this->country($row, 'country', $planned);
        $effectiveCountry = $this->effectiveCountry($country, $row, 'afm', $company);
        $afm = $this->afm($row, 'afm', $effectiveCountry, $planned);
        $values = [
            'name' => $this->text($row, 'name', 191, $planned),
            'tax_office' => $this->text($row, 'tax_office', 60, $planned),
            'occupation' => $this->text($row, 'occupation', 120, $planned),
            'address1' => $this->text($row, 'address1', 60, $planned),
            'address2' => $this->text($row, 'address2', 60, $planned),
            'city' => $this->text($row, 'city', 60, $planned),
            'postcode' => $this->text($row, 'postcode', 10, $planned),
            'email' => $this->email($row, 'email', 120, $planned),
            'phone1' => $this->text($row, 'phone1', 30, $planned),
            'phone2' => $this->text($row, 'phone2', 30, $planned),
            'vat_vies' => $this->text($row, 'vat_vies', 30, $planned),
            'country' => $country,
        ];
        $planned->label = $values['name'] ?? $afm ?? '—';
        if ($planned->failed()) {
            return;
        }

        $identity = $afm !== null ? 'afm:'.$afm : ($values['name'] !== null ? 'name:'.mb_strtolower($values['name']) : null);
        if ($identity !== null && isset($this->seen[$identity])) {
            $planned->fail('Διπλή εγγραφή στο αρχείο (ίδια με τη γραμμή '.$this->seen[$identity].').');

            return;
        }
        if ($identity !== null) {
            $this->seen[$identity] = $planned->line;
        }

        $existing = match (true) {
            $afm !== null => Customer::afmOwnerQuery((int) $company->getKey(), $afm)->first(),
            $values['name'] !== null => Customer::withTrashed()
                ->where('company_id', $company->getKey())
                ->whereNull('afm_key')
                ->where('name', $values['name'])
                ->first(),
            default => null,
        };

        if ($existing !== null) {
            if ($existing->trashed()) {
                $planned->fail("Ο πελάτης υπάρχει στον κάδο (#{$existing->getKey()}) — επανέφερέ τον πρώτα.");

                return;
            }
            $this->settleExisting($planned, $existing, $this->blanksToFill($existing, $values));

            return;
        }

        if ($values['name'] === null) {
            $planned->fail('Λείπει η επωνυμία.');

            return;
        }

        $values['afm'] = $afm;
        $values['country'] = $effectiveCountry;
        $values['is_active'] = true;

        $planned->action = PlannedRow::CREATE;
        $planned->values = array_filter($values, static fn ($v): bool => $v !== null);
    }

    protected function persist(Company $company, PlannedRow $row): void
    {
        if ($row->action === PlannedRow::CREATE) {
            Customer::create(['company_id' => $company->getKey()] + $row->values);

            return;
        }

        Customer::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->findOrFail($row->existingId)
            ->fill($row->values)
            ->save();
    }
}
