<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Support\MyData\Codes;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Per-document expense classification (E5). Local only — the
            // operator assigns one §8.x E3 type + category2_x for the whole
            // doc; stored on the header for the ΦΠΑ/Ε3 reports. Filing it at
            // AADE (SendExpensesClassification) is a documented follow-up.
            Action::make('classify')
                ->label('Χαρακτηρισμός')
                ->icon('heroicon-o-tag')
                ->color('primary')
                ->modalHeading('Χαρακτηρισμός εξόδου')
                ->modalDescription('Επιλέξτε τύπο (E3) και κατηγορία χαρακτηρισμού για όλο το παραστατικό. Αποθηκεύεται τοπικά (δεν υποβάλλεται στο myDATA σε αυτή τη φάση).')
                ->modalSubmitActionLabel('Αποθήκευση')
                ->fillForm(fn (): array => [
                    'classification_type' => $this->record->classification_type,
                    'classification_category' => $this->record->classification_category,
                ])
                ->schema([
                    Select::make('classification_type')
                        ->label('Τύπος χαρακτηρισμού (E3)')
                        ->options(Codes::expenseClassTypeOptions())
                        ->searchable()
                        ->required(),
                    Select::make('classification_category')
                        ->label('Κατηγορία χαρακτηρισμού')
                        ->options(Codes::expenseClassCategoryOptions())
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->record->forceFill([
                        'classification_type' => $data['classification_type'],
                        'classification_category' => $data['classification_category'],
                        'classification_state' => 'classified',
                    ])->save();

                    Notification::make()
                        ->title('Ο χαρακτηρισμός αποθηκεύτηκε')
                        ->success()
                        ->send();
                }),
        ];
    }
}
