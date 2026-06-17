<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Support\PartySyncWindow;
use App\Filament\Support\Tags\TagControls;
use App\Models\Customer;
use App\Services\MyData\CustomerSyncFromMyData;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

class ListCustomers extends BaseListRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Bulk customer discovery: scan our sales (RequestTransmittedDocs)
            // and create any B2B customer we don't have yet from the counterpart
            // AFMs (GSIS-enriched). The mirror of the Suppliers «Συγχρονισμός».
            // Only for tenants that can READ myDATA — hidden otherwise.
            Action::make('syncFromMyData')
                ->label('Συγχρονισμός από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('warning')
                ->visible(fn (): bool => (bool) Filament::getTenant()?->canReadMyData())
                ->modalHeading('Συγχρονισμός πελατών από myDATA')
                ->modalDescription('Σαρώνει τα παραστατικά πωλήσεων (RequestTransmittedDocs) για το διάστημα και δημιουργεί πελάτες για όσα ΑΦΜ συναλλασσομένων δεν υπάρχουν ήδη. Για ελληνικά ΑΦΜ αντλεί στοιχεία από το μητρώο ΑΑΔΕ (GSIS). Η λιανική (χωρίς ΑΦΜ) δεν δημιουργεί πελάτη.')
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
            $result = (new CustomerSyncFromMyData($tenant))->sync(
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
            ->title("Δημιουργήθηκαν {$result->created} νέοι πελάτες")
            ->body($result->summary());

        if ($result->gsisFailures !== []) {
            $notification->warning();
        } else {
            $notification->success();
        }

        $notification->send();
    }

    public function getTabs(): array
    {
        return TagControls::pinnedTabs(Customer::class);
    }
}
