<?php

namespace App\Services\Import;

use App\Enums\SupplierSource;
use App\Models\Company;
use App\Models\Supplier;
use App\Support\Afm;

/**
 * Suppliers from CSV. The ΑΦΜ is stored as its identity key (the form the
 * myDATA sync writes) and matched on the identity of what is on file, however it
 * was typed, soft-deleted included; an ΑΦΜ-less supplier (a foreign one without a VAT
 * id) is matched by exact name. New rows are marked source = import.
 */
final class SupplierCsvImporter extends EntityCsvImporter
{
    /** @var array<string, int> */
    private array $seen = [];

    /** @var array<string, int>|null ΑΦΜ identity key => supplier id, built once per plan */
    private ?array $afmIndex = null;

    public function entityLabel(): string
    {
        return 'προμηθευτών';
    }

    public function fields(): array
    {
        return [
            'name' => ['label' => 'Επωνυμία', 'aliases' => ['Όνομα', 'Προμηθευτής', 'Name', 'Company', 'Company name', 'Supplier']],
            'afm' => ['label' => 'ΑΦΜ', 'aliases' => ['VAT', 'VAT number', 'VAT ID', 'TIN', 'Tax ID', 'Αριθμός ΦΠΑ']],
            'tax_office' => ['label' => 'ΔΟΥ', 'aliases' => ['Tax office']],
            'occupation' => ['label' => 'Επάγγελμα', 'aliases' => ['Δραστηριότητα', 'Occupation', 'Profession']],
            'address1' => ['label' => 'Διεύθυνση', 'aliases' => ['Οδός', 'Address', 'Street', 'Address 1']],
            'city' => ['label' => 'Πόλη', 'aliases' => ['Περιοχή', 'City', 'Town']],
            'postcode' => ['label' => 'ΤΚ', 'aliases' => ['Ταχυδρομικός κώδικας', 'Ταχ. κώδικας', 'Postcode', 'Postal code', 'Zip', 'Zip code']],
            'country' => ['label' => 'Χώρα', 'aliases' => ['Country']],
            'email' => ['label' => 'Email', 'aliases' => ['E-mail', 'Ηλεκτρονικό ταχυδρομείο']],
            'phone1' => ['label' => 'Τηλέφωνο', 'aliases' => ['Τηλ', 'Phone', 'Telephone']],
            'notes' => ['label' => 'Σημειώσεις', 'aliases' => ['Παρατηρήσεις', 'Notes', 'Comments']],
        ];
    }

    public function sampleRow(): array
    {
        return [
            'name' => 'Προμηθευτής Ε.Π.Ε.', 'afm' => '090000045', 'tax_office' => 'Α ΑΘΗΝΩΝ',
            'city' => 'Αθήνα', 'postcode' => '10431', 'country' => 'GR', 'email' => 'sales@example.gr',
        ];
    }

    protected function reset(): void
    {
        $this->seen = [];
        $this->afmIndex = null;
    }

    /**
     * The tenant's suppliers by ΑΦΜ IDENTITY. The column holds whatever was typed
     * («EL094019245», «094 019 245») — the sync writes the bare key, the form does
     * not normalise — so match on Afm::uniqueKey, not the raw string.
     */
    private function supplierIdForAfm(int $companyId, string $key): ?int
    {
        if ($this->afmIndex === null) {
            $this->afmIndex = [];
            Supplier::withTrashed()->where('company_id', $companyId)->whereNotNull('afm')
                ->orderBy('id')->get(['id', 'afm'])
                ->each(function (Supplier $s): void {
                    $k = Afm::uniqueKey($s->afm);
                    if ($k !== null) {
                        $this->afmIndex[$k] ??= (int) $s->getKey();
                    }
                });
        }

        return $this->afmIndex[$key] ?? null;
    }

    protected function planRow(Company $company, array $row, PlannedRow $planned): void
    {
        $country = $this->country($row, 'country', $planned);
        $effectiveCountry = $this->effectiveCountry($country, $row, 'afm', $company);
        $afm = $this->afm($row, 'afm', $effectiveCountry, $planned);
        $values = [
            'name' => $this->text($row, 'name', 191, $planned),
            'tax_office' => $this->text($row, 'tax_office', 120, $planned),
            'occupation' => $this->text($row, 'occupation', 191, $planned),
            'address1' => $this->text($row, 'address1', 191, $planned),
            'city' => $this->text($row, 'city', 120, $planned),
            'postcode' => $this->text($row, 'postcode', 20, $planned),
            'email' => $this->email($row, 'email', 191, $planned),
            'phone1' => $this->text($row, 'phone1', 60, $planned),
            'notes' => $this->text($row, 'notes', 2000, $planned),
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

        $query = Supplier::withTrashed()->where('company_id', $company->getKey());
        $existing = match (true) {
            $afm !== null => ($id = $this->supplierIdForAfm((int) $company->getKey(), $afm)) !== null
                ? (clone $query)->whereKey($id)->first()
                : null,
            $values['name'] !== null => (clone $query)->whereNull('afm')->where('name', $values['name'])->first(),
            default => null,
        };

        if ($existing !== null) {
            if ($existing->trashed()) {
                $planned->fail("Ο προμηθευτής υπάρχει στον κάδο (#{$existing->getKey()}) — επανέφερέ τον πρώτα.");

                return;
            }
            $this->settleExisting($planned, $existing, $this->blanksToFill($existing, $values));

            return;
        }

        if ($values['name'] === null && $afm === null) {
            $planned->fail('Λείπει η επωνυμία ή το ΑΦΜ.');

            return;
        }

        $values['afm'] = $afm;
        // A foreign supplier without a VAT prefix is not assumed to be local.
        $values['country'] = $country ?? Afm::countryPrefix($afm) ?? ($afm !== null && ctype_digit($afm) ? $company->country_code : null);
        $values['source'] = SupplierSource::Import;
        $values['is_active'] = true;

        $planned->action = PlannedRow::CREATE;
        $planned->values = array_filter($values, static fn ($v): bool => $v !== null);
    }

    protected function persist(Company $company, PlannedRow $row): void
    {
        if ($row->action === PlannedRow::CREATE) {
            Supplier::create(['company_id' => $company->getKey()] + $row->values);

            return;
        }

        Supplier::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->findOrFail($row->existingId)
            ->fill($row->values)
            ->save();
    }
}
