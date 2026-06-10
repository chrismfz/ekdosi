<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\MyData\ExpenseClassificationSubmitter;
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
            // Per-document expense classification (E5). LOCAL — the operator
            // assigns one §8.x E3 type + category2_x (εμπορεύματα/πάγια/δαπάνες)
            // for the whole doc; stored on the header for the ΦΠΑ/Ε3 reports and
            // then submitted to AADE via the «Υποβολή» action below.
            Action::make('classify')
                ->label('Χαρακτηρισμός')
                ->icon('heroicon-o-tag')
                ->color('primary')
                ->modalHeading('Χαρακτηρισμός εξόδου')
                ->modalDescription(fn (): string => $this->record->classification_state === 'submitted'
                    ? '⚠ Έχει ΗΔΗ υποβληθεί χαρακτηρισμός στην ΑΑΔΕ. Νέος χαρακτηρισμός απαιτεί ΕΠΑΝΥΠΟΒΟΛΗ (η προηγούμενη υποβολή διατηρείται στο ιστορικό).'
                    : 'Επιλέξτε τύπο (E3) και κατηγορία χαρακτηρισμού (εμπορεύματα/πάγια/δαπάνες) για όλο το παραστατικό. Αποθηκεύεται τοπικά — υπόβαλέ το στην ΑΑΔΕ με το «Υποβολή χαρακτηρισμού».')
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
                        ->body('Υπόβαλέ τον στην ΑΑΔΕ με το «Υποβολή χαρακτηρισμού».')
                        ->success()
                        ->send();
                }),

            // Submit the local classification to AADE (SendExpensesClassification).
            // Visible only for a myDATA-pulled doc (has a ΜΑΡΚ) that is classified
            // but not yet submitted.
            Action::make('submit_classification')
                ->label('Υποβολή χαρακτηρισμού')
                ->icon('heroicon-o-paper-airplane')
                ->color('success')
                ->visible(fn (): bool => filled($this->record->mydata_mark)
                    && $this->record->classification_state === 'classified')
                ->requiresConfirmation()
                ->modalHeading('Υποβολή χαρακτηρισμού στην ΑΑΔΕ')
                ->modalDescription('Στέλνει τον χαρακτηρισμό (τύπος E3 + κατηγορία) του παραστατικού στη myDATA. Μη αναστρέψιμο μέσω της εφαρμογής.')
                ->action(function (): void {
                    $tenant = $this->record->company ?? \Filament\Facades\Filament::getTenant();

                    try {
                        $mark = app()->makeWith(ExpenseClassificationSubmitter::class, ['tenant' => $tenant])
                            ->submit($this->record);
                    } catch (\Throwable $e) {
                        Notification::make()->title('Αποτυχία υποβολής')->body($e->getMessage())->danger()->persistent()->send();

                        return;
                    }

                    Notification::make()
                        ->title('Ο χαρακτηρισμός υποβλήθηκε στην ΑΑΔΕ')
                        ->body($mark !== '' ? 'ΜΑΡΚ χαρακτηρισμού: '.$mark : 'Επιτυχία.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
