<?php

namespace App\Filament\Resources\ExpenseClassificationRules\Pages;

use App\Filament\Resources\ExpenseClassificationRules\ExpenseClassificationRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditExpenseClassificationRule extends EditRecord
{
    protected static string $resource = ExpenseClassificationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
