<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\PartySyncWindow;
use App\Filament\Support\Tags\TagControls;
use App\Models\Supplier;
use App\Services\MyData\SupplierSyncFromMyData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

class ListSuppliers extends BaseListRecords
{
    protected static string $resource = SupplierResource::class;

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Supplier::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Bulk "sync" provenance: pull RequestDocs issuer AFMs and create
            // any supplier we don't have yet (GSIS-enriched). Only meaningful
            // for tenants that can READ from myDATA (direct gr-mydata OR a
            // gr-provider tenant with its own read credentials) — hidden otherwise.
            Action::make('syncFromMyData')
                ->label('Συγχρονισμός από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('warning')
                ->visible(fn (): bool => (bool) Filament::getTenant()?->canReadMyData())
                ->modalHeading('Συγχρονισμός προμηθευτών από myDATA')
                ->modalDescription('Σαρώνει τα παραστατικά εξόδων (RequestDocs) για το διάστημα και δημιουργεί προμηθευτές για όσα ΑΦΜ δεν υπάρχουν ήδη. Για ελληνικά ΑΦΜ αντλεί στοιχεία από το μητρώο ΑΑΔΕ (GSIS).')
                ->modalSubmitActionLabel('Συγχρονισμός')
                ->schema([
                    ...PartySyncWindow::schema(),
                    Toggle::make('enrich')
                        ->label('Άντληση στοιχείων από ΑΑΔΕ (GSIS)')
                        ->helperText('Για ελληνικά ΑΦΜ χωρίς όνομα στο παραστατικό.')
                        ->default(true),
                ])
                ->action(fn (array $data) => $this->runSync($data)),
        ];
    }

    private function runSync(array $data): void
    {
        $tenant = Filament::getTenant();
        if ($tenant === null) {
            Notification::make()->title('Λείπει το tenant context.')->warning()->send();

            return;
        }

        [$from, $to] = PartySyncWindow::resolve($data);

        try {
            $result = (new SupplierSyncFromMyData($tenant))->sync(
                $from,
                $to,
                (bool) ($data['enrich'] ?? true),
            );
        } catch (RuntimeException $e) {
            // Guard messages (non-GR / mode off / missing creds) — safe to show.
            Notification::make()->title('Ο συγχρονισμός δεν έτρεξε')->body($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            Notification::make()->title('Σφάλμα myDATA')->body($e->getMessage())->danger()->send();

            return;
        }

        $notification = Notification::make()
            ->title("Δημιουργήθηκαν {$result->created} νέοι προμηθευτές")
            ->body($result->summary());

        if ($result->gsisFailures !== []) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }
}
