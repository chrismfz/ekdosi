<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Support\SendChannelFormBridge;
use App\Services\MyData\MyDataLookupSeeder;
use App\Support\LegalEvidence;
use App\Support\Tenancy\CompanyContext;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Checkbox;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

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

    /**
     * MYD-025 — deleting a tenant stays POSSIBLE, but stops being silent.
     *
     * A bare DeleteAction cascaded straight through every MARK, every filed
     * document and the whole audit trail, saying nothing about what it was about
     * to destroy. Blocking it would have been the wrong fix: a tenant that
     * outgrows a shared host is exported, restored elsewhere and then legitimately
     * removed, and a company that can never be deleted is its own operational
     * failure.
     *
     * So the fix is the WARNING, not a gate. The confirmation names exactly what
     * filing evidence dies, and two acknowledgements make it a decision rather
     * than a reflex. Deliberately NOT forcing a backup here: the operator has
     * usually just taken one — this follows an export→restore migration — and a
     * guard that makes them sit through a second copy of the same data is a guard
     * they learn to route around. The links point at the tools instead.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalHeading(fn (): string => 'Οριστική διαγραφή «'.$this->record->name.'»')
                ->modalDescription(fn (): HtmlString => $this->deletionWarning())
                ->schema([
                    Checkbox::make('understood')
                        ->label('Καταλαβαίνω ότι διαγράφονται οριστικά όλα τα δεδομένα αυτής της εταιρείας')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Χρειάζεται επιβεβαίωση.']),
                    Checkbox::make('has_backup')
                        ->label('Έχω κρατήσει αντίγραφο (ή δεν το χρειάζομαι)')
                        ->accepted()
                        ->validationMessages(['accepted' => 'Χρειάζεται επιβεβαίωση.']),
                ])
                ->modalSubmitActionLabel('Οριστική διαγραφή')
                ->before(function (): void {
                    // On the record either way: this is the one moment where the
                    // local proof of everything filed under this ΑΦΜ stops existing.
                    Log::warning('Company deleted', [
                        'company_id' => $this->record->getKey(),
                        'slug' => $this->record->slug,
                        'evidence' => LegalEvidence::for($this->record)->describe(),
                        'user_id' => auth()->id(),
                    ]);
                }),
        ];
    }

    /**
     * The sentence that turns a destructive click into an informed one.
     *
     * Deliberately states that AADE keeps its records: the risk of deleting is
     * losing the LOCAL proof, not un-filing anything, and an operator who thinks
     * otherwise would make the wrong call in both directions.
     */
    private function deletionWarning(): HtmlString
    {
        $evidence = LegalEvidence::for($this->record);
        $slug = e((string) $this->record->slug);

        $lines = [
            '<strong>Διαγράφονται ΟΡΙΣΤΙΚΑ όλα τα δεδομένα αυτής της εταιρείας</strong> — '
            .'παραστατικά, πληρωμές, πελάτες, ΜΑΡΚ και το ιστορικό ενεργειών.',
        ];

        if ($evidence->exists()) {
            $lines[] = '<br><strong>⚠ Υποβεβλημένα στην ΑΑΔΕ: '.e($evidence->describe()).'</strong>';
            $lines[] = 'Η διαγραφή <em>δεν</em> τα ακυρώνει στην ΑΑΔΕ — εκεί παραμένουν. '
                .'Χάνεται μόνο η τοπική απόδειξη του τι υποβλήθηκε.';
        }

        $lines[] = '<br>Πριν συνεχίσεις, αν χρειάζεσαι αντίγραφο:';
        $lines[] = '<br>• <strong>Αντίγραφο για επαναφορά αλλού</strong> — «Αντίγραφα» → «Εξαγωγή ρυθμίσεων» '
            .'με «Πλήρες αντίγραφο», ή <code>php artisan company:export --tenant='.$slug.' --full</code>';
        $lines[] = '<br>• <strong>Παραστατικά σε PDF</strong> (για εταιρεία που φεύγει και δεν θα έχει πρόσβαση) — '
            .'<code>php artisan company:export-pdfs --tenant='.$slug.'</code>';

        return new HtmlString(implode(' ', $lines));
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
        // Gate on the SAME two lookups the toast body reports (VAT + invoice types),
        // so the toast never fires reading «0 · 0». On a provider switch these seed
        // together with the rest, so a run that created only the minor lookups
        // (payments/units/categories) while VAT+types already existed is a rare edge
        // not worth a confusing toast.
        if ($r['vat']['created'] === 0 && $r['types']['created'] === 0 && $filled === 0) {
            return;  // Nothing worth reporting — stay quiet.
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
