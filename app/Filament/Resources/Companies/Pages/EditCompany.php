<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\SendChannelFormBridge;
use App\Services\MyData\MyDataLookupSeeder;
use App\Support\Tenancy\CompanyContext;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /** Inject the synthetic «Τρόπος αποστολής» + provider-cred fields from the record (P3). */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data = SendChannelFormBridge::hydrate($data, $this->record);

        // The secret columns are $hidden, so attributesToArray() (Filament's fill
        // source) omits them — re-inject the scalar ones from the record so the
        // admin form prefills exactly as before (the array provider_config is
        // handled above by the bridge's cfg_* fields). Blank-on-save still keeps
        // the stored value via each field's ->dehydrated(filled) rule.
        foreach ($this->record->getHidden() as $column) {
            $value = $this->record->getAttribute($column);
            if (is_scalar($value)) {
                $data[$column] = $value;
            }
        }

        return $data;
    }

    /** Decompose the synthetic fields back into the real columns + encrypted config (P3). */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return SendChannelFormBridge::dehydrate($data, $this->record);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * SET-5: a tenant created as «none»/PEPPOL and later switched to Greek
     * filing starts with EMPTY lookups (CreateCompany::afterCreate only seeds
     * Greek tenants). Mirror that seeding here when einvoice_provider flips INTO
     * a Greek provider, so the operator doesn't face an empty Setup after the
     * switch. The seeder is idempotent + fill-empty, so a Greek→Greek edit
     * (gr-provider↔gr-mydata) re-runs harmlessly (created=0) — we only surface
     * the notification when something was actually seeded, keeping a plain
     * provider-unrelated save silent. einvoice_provider is super_admin-only and
     * edited solely through this page, so this is the one path that matters.
     */
    protected function afterSave(): void
    {
        if (! $this->record->wasChanged('einvoice_provider')) {
            return;
        }

        if (! in_array($this->record->einvoice_provider, ['gr-mydata', 'gr-provider'], true)) {
            return;
        }

        // Pin the ambient tenant to the record BEING SEEDED. CompanyResource is
        // NOT tenant-scoped, so a super_admin can edit company B while the panel
        // context (TenantSet) is pinned to a DIFFERENT tenant A — and the
        // seeder's idempotency probes (`where('company_id', B)`) would then get
        // the CompanyScope predicate `AND company_id = A` appended → always
        // false → duplicate rows (or an invoice_types unique-key throw). actAs
        // makes the scope agree with the explicit where.
        $r = app(CompanyContext::class)->actAs(
            $this->record,
            fn (): array => app(MyDataLookupSeeder::class)->seedStandardLookups($this->record),
        );

        $filled = $r['types']['filled'];
        if ($r['vat']['created'] === 0 && $r['types']['created'] === 0 && $filled === 0) {
            return;  // Nothing to seed (already had them) — stay quiet.
        }

        // «filled» = myDATA classification back-filled onto pre-existing types
        // (created can be 0 while filled > 0), so mention it or the toast reads
        // «0 · 0» even though the switch DID complete the AADE chain.
        $filledNote = $filled > 0 ? " · συμπληρώθηκε κατηγοριοποίηση σε {$filled} τύπους" : '';

        Notification::make()
            ->title('Στήθηκαν τυπικές ρυθμίσεις ΑΑΔΕ')
            ->body("Ο πάροχος άλλαξε σε ελληνική τιμολόγηση — προστέθηκαν οι τυπικές ρυθμίσεις. Κατηγορίες ΦΠΑ: {$r['vat']['created']} · Είδη παραστατικών: {$r['types']['created']}{$filledNote}. Προσαρμόστε τα στο Setup αν χρειάζεται.")
            ->success()
            ->send();
    }
}
