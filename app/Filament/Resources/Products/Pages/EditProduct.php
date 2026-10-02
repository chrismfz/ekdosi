<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Support\GuardedDeleteAction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Services\Products\VariantGenerator;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Simple → variable, only while the product has no history (see
            // VariantGenerator::canBecomeVariable).
            Action::make('make_variable')
                ->label('Μετατροπή σε προϊόν με παραλλαγές')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray')
                ->authorize('update', $this->getRecord())
                ->visible(fn () => $this->getRecord()->kind === Product::KIND_SIMPLE)
                ->requiresConfirmation()
                ->modalDescription('Το προϊόν θα γίνει «ομάδα» (δεν πουλιέται το ίδιο) και θα πουλιούνται οι παραλλαγές του. Επιτρέπεται μόνο αν δεν έχει ακόμη κινήσεις αποθέματος ή παραστατικά.')
                ->action(function (): void {
                    /** @var Product $record */
                    $record = $this->getRecord();
                    if (! app(VariantGenerator::class)->canBecomeVariable($record)) {
                        Notification::make()
                            ->danger()
                            ->title('Δεν γίνεται μετατροπή')
                            ->body('Το προϊόν έχει ήδη ιστορικό (κινήσεις αποθέματος ή παραστατικά). Φτιάξε νέο προϊόν «με παραλλαγές».')
                            ->send();

                        return;
                    }
                    $record->update(['kind' => Product::KIND_VARIABLE, 'barcode' => null]);
                    Notification::make()->success()->title('Έγινε προϊόν με παραλλαγές')->send();
                    $this->redirect(ProductResource::getUrl('edit', ['record' => $record]));
                }),

            GuardedDeleteAction::make(fn ($record): array => ProductResource::dependents($record)),
        ];
    }

    /**
     * On mount, hydrate the live-only `markup_display` field by DERIVING
     * it from the persisted (buy_price, sell_price) pair. This preserves
     * the historical relationship — if operator later nudges buy_price,
     * sell_price recomputes against the markup that was originally
     * applied, not against whatever the category's markup happens to be
     * NOW (admins can edit category markups after products are saved).
     *
     * Fallback: if buy_price is 0 / null (no price relationship to derive
     * from), fall back to the category's current markup as a hint. If
     * neither, leave at 0.
     *
     * Markup is purely a UX helper, not persisted (dehydrated:false),
     * matching the legacy app where markup lived in a Windows Registry
     * app-wide setting.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $buy = (float) ($data['buy_price'] ?? 0);
        $sell = (float) ($data['sell_price'] ?? 0);

        if ($buy > 0) {
            $data['markup_display'] = round(($sell - $buy) / $buy * 100, 2);
        } elseif (! empty($data['product_category_id'])) {
            $data['markup_display'] = (float) (ProductCategory::query()
                ->where('company_id', Filament::getTenant()?->getKey())
                ->whereKey($data['product_category_id'])
                ->value('markup') ?? 0);
        } else {
            $data['markup_display'] = 0;
        }

        return $data;
    }
}
