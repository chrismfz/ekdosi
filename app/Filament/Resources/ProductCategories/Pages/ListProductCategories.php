<?php

namespace App\Filament\Resources\ProductCategories\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ProductCategories\ProductCategoryResource;
use App\Services\MyData\MyDataLookupSeeder;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;

class ListProductCategories extends BaseListRecords
{
    protected static string $resource = ProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),

            // Minimal generic categories (Υπηρεσίες/Εμπορεύματα/Προϊόντα) aligned
            // with the income categories. NOT AADE-codified — a starting point.
            // Idempotent: existing rows (by short description) are kept.
            Action::make('seed_standard')
                ->label('Εισαγωγή τυπικών')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Εισαγωγή τυπικών κατηγοριών προϊόντων')
                ->modalDescription('Προστίθενται βασικές κατηγορίες (Υπηρεσίες, Εμπορεύματα, Προϊόντα) με μηδενικό περιθώριο. Υπάρχουσες διατηρούνται — δεν διπλασιάζονται.')
                ->modalSubmitActionLabel('Εισαγωγή')
                ->action(function (): void {
                    $tenant = Filament::getTenant();
                    if (! $tenant) {
                        return;
                    }
                    $r = app(MyDataLookupSeeder::class)->seedProductCategories($tenant);
                    Notification::make()
                        ->title("Προστέθηκαν {$r['created']} · Υπήρχαν ήδη {$r['skipped']}")
                        ->success()->send();
                }),
        ];
    }
}
