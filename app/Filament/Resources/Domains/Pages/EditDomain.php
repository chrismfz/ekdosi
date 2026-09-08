<?php

namespace App\Filament\Resources\Domains\Pages;

use App\Filament\Resources\Domains\DomainResource;
use App\Models\Domain;
use App\Models\DomainTld;
use App\Models\Invoice;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditDomain extends EditRecord
{
    protected static string $resource = DomainResource::class;

    /** The fqdn as it was BEFORE this save — afterSave syncs snapshots off it. */
    private ?string $fqdnBeforeSave = null;

    /**
     * Keep tld/fqdn derived when sld or the catalogue TLD changes — and treat
     * the rename/TLD-change of an ASSIGNED domain as a guarded operation
     * (review r3): the 1:1 ServiceContract snapshots price/cycle/name, so an
     * unguarded change would silently corrupt future legal renewal drafts.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $tenantId = Filament::getTenant()?->getKey();
        $tld = DomainTld::query()
            ->where('company_id', $tenantId)
            ->find($data['domain_tld_id'] ?? $this->record->domain_tld_id);
        $sld = mb_strtolower(trim((string) ($data['sld'] ?? $this->record->sld)));
        $data['sld'] = $sld;
        $data['tld'] = (string) $tld?->tld;
        $data['fqdn'] = Domain::fqdnFor($sld, (string) $tld?->tld);

        $this->fqdnBeforeSave = (string) $this->record->fqdn;
        $tldChanged = (int) ($data['domain_tld_id'] ?? 0) !== (int) $this->record->domain_tld_id;
        $renamed = $data['fqdn'] !== $this->fqdnBeforeSave;

        if ($this->record->service_contract_id !== null) {
            // The SC's amount/billing_cycle were priced off THIS TLD at assign
            // time — a different TLD means a different product, not a rename.
            if ($tldChanged) {
                throw ValidationException::withMessages([
                    'data.domain_tld_id' => 'Το TLD ανατεθειμένου domain δεν αλλάζει — η τιμή/κύκλος του συμβολαίου του κλειδώθηκαν σε αυτό. Ακυρώστε το συμβόλαιο ή καταχωρήστε νέο domain.',
                ]);
            }
            // A staged-but-unissued draft copied the old name at staging time;
            // renaming underneath it would issue/file a legal document with a
            // stale name (the TransferDomainOwnership discipline).
            if ($renamed && $this->hasOpenDraftRenewal()) {
                throw ValidationException::withMessages([
                    'data.sld' => 'Υπάρχει πρόχειρο παραστατικό ανανέωσης με το παλιό όνομα — εκδώστε ή ακυρώστε το πριν τη μετονομασία.',
                ]);
            }
        }

        // Friendly uniqueness (incl. soft-deleted tombstones) instead of a raw
        // QueryException from the unique(company_id, fqdn) constraint.
        if ($renamed) {
            $taken = Domain::query()
                ->withTrashed()
                ->where('company_id', $tenantId)
                ->where('fqdn', $data['fqdn'])
                ->whereKeyNot($this->record->getKey())
                ->exists();
            if ($taken) {
                throw ValidationException::withMessages([
                    'data.sld' => 'Το '.$data['fqdn'].' υπάρχει ήδη στο χαρτοφυλάκιο (ίσως διαγραμμένο).',
                ]);
            }
        }

        return $data;
    }

    /**
     * Sync the ServiceContract snapshot after a RENAME — but only fields still
     * carrying the OLD stock values: an operator-customized description (or a
     * hand-set SC.domain) is never clobbered.
     */
    protected function afterSave(): void
    {
        $record = $this->record->refresh();
        $contract = $record->serviceContract;
        $old = $this->fqdnBeforeSave;
        if ($contract === null || $old === null || $old === $record->fqdn) {
            return;
        }

        $updates = [];
        if ($contract->domain === $old) {
            $updates['domain'] = $record->fqdn;
        }
        if ($contract->description === 'Ανανέωση domain '.$old) {
            $updates['description'] = 'Ανανέωση domain '.$record->fqdn;
        }
        if ($updates !== []) {
            $contract->update($updates);
        }
    }

    private function hasOpenDraftRenewal(): bool
    {
        return Invoice::query()
            ->where('company_id', $this->record->company_id)
            ->where('service_contract_id', $this->record->service_contract_id)
            ->where('local_status', 'draft')
            ->exists();
    }
}
