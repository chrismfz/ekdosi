<?php

namespace App\Filament\Resources\Products\Pages;

use App\Actions\ImportLeviedProducts;
use App\Filament\BaseListRecords;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Company;
use App\Models\Product;
use App\Support\Products\LeviedProductTemplates;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;

class ListProducts extends BaseListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            $this->importLeviedTemplatesAction(),
        ];
    }

    /**
     * «Πρότυπα τελών» — selective import of the curated statutory-levy products
     * (πλαστική σακούλα / ανακύκλωσης / διαμονής…) pre-configured with the right
     * myDATA fee, so the operator doesn't look up §8.x codes. Gated on product
     * create rights; idempotent (re-import skips existing).
     */
    private function importLeviedTemplatesAction(): Action
    {
        $templates = LeviedProductTemplates::all();

        return Action::make('import_levied_templates')
            ->label('Πρότυπα τελών')
            ->icon('heroicon-o-receipt-percent')
            ->color('gray')
            ->visible(fn (): bool => ProductResource::canCreate())
            ->modalHeading('Εισαγωγή προϊόντων με θεσμικό τέλος')
            ->modalDescription('Έτοιμα προϊόντα/τέλη, προ-ρυθμισμένα με το σωστό myDATA τέλος (§8.5). Διάλεξε ποια να δημιουργηθούν — το τέλος υπολογίζεται μετά αυτόματα ανά παραστατικό. Προσοχή: δύο διαφορετικά τέλη ΙΔΙΟΥ τύπου (π.χ. σακούλα + ανακύκλωσης) δεν μπαίνουν στο ΙΔΙΟ παραστατικό — χώρισέ τα.')
            ->modalSubmitActionLabel('Εισαγωγή')
            ->schema([
                CheckboxList::make('templates')
                    ->label('Διαθέσιμα πρότυπα')
                    ->options(collect($templates)->mapWithKeys(fn (array $t): array => [
                        $t['key'] => $t['name'].' — €'.number_format($t['per_unit'], 2, ',', '').'/'.$t['unit']
                            .($t['fixed'] ? '' : ' (ρυθμιζόμενο)'),
                    ])->all())
                    ->descriptions(collect($templates)->mapWithKeys(fn (array $t): array => [$t['key'] => $t['note']])->all())
                    ->required(),
            ])
            ->action(function (array $data): void {
                /** @var Company $tenant */
                $tenant = Filament::getTenant();
                $res = app(ImportLeviedProducts::class)($tenant, $data['templates'] ?? []);

                if (($res['error'] ?? null) === 'no_vat') {
                    Notification::make()
                        ->title('Δημιούργησε πρώτα μια κατηγορία ΦΠΑ')
                        ->body('Τα προϊόντα χρειάζονται κατηγορία ΦΠΑ. Πήγαινε Setup → Κατηγορίες ΦΠΑ (ή «Σπορά προτύπων») και ξαναδοκίμασε.')
                        ->warning()
                        ->send();

                    return;
                }

                $body = count($res['created']).' δημιουργήθηκαν';
                if ($res['skipped'] !== []) {
                    $body .= ' · '.count($res['skipped']).' υπήρχαν ήδη';
                }

                Notification::make()
                    ->title('Εισαγωγή προτύπων τελών')
                    ->body($body.'.')
                    ->success()
                    ->send();
            });
    }

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Product::class);
    }
}
