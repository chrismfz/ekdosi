<?php

namespace App\Filament\Resources\InboundDeliveryNotes\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\InboundDeliveryNotes\InboundDeliveryNoteResource;
use App\Models\Company;
use App\Services\Delivery\InboundDeliveryFetcher;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use RuntimeException;
use Throwable;

class ListInboundDeliveryNotes extends BaseListRecords
{
    protected static string $resource = InboundDeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // On-demand mirror of the scheduled `delivery:fetch-inbound` for THIS
            // tenant: pull the ψηφιακή-διακίνηση docs filed against us and stage the
            // movement-bearing ones. READ-ONLY staging — no AADE mutation. Gated on
            // Update (it triggers an AADE pull + writes staging rows), like the
            // WHMCS inbox's sync action.
            Action::make('fetch_inbound')
                ->label('Λήψη νέων')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->authorize(fn (): bool => (bool) auth()->user()?->can('Update:InboundDeliveryNote'))
                ->requiresConfirmation()
                ->modalHeading('Λήψη εισερχόμενων διακίνησης')
                ->modalDescription('Αντλεί από το myDATA τα παραστατικά διακίνησης που εκδόθηκαν σε εμάς (εμπορεύματα που παραλαμβάνουμε) και τα σταδιοποιεί εδώ. Καμία αλλαγή δεν γίνεται στην ΑΑΔΕ.')
                ->modalSubmitActionLabel('Λήψη')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant instanceof Company) {
                        return;
                    }

                    try {
                        $result = (new InboundDeliveryFetcher($tenant))->fetch();

                        Notification::make()
                            ->title('Ολοκληρώθηκε η λήψη')
                            ->body($result->summary())
                            ->success()
                            ->send();
                    } catch (RuntimeException $e) {
                        // Guard messages (mode off / missing creds) — show verbatim.
                        Notification::make()->title('Δεν έγινε λήψη')->body($e->getMessage())->warning()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('Η λήψη απέτυχε')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
