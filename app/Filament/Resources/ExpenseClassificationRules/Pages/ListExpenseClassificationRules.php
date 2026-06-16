<?php

namespace App\Filament\Resources\ExpenseClassificationRules\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\ExpenseClassificationRules\ExpenseClassificationRuleResource;
use Filament\Actions\CreateAction;

class ListExpenseClassificationRules extends BaseListRecords
{
    protected static string $resource = ExpenseClassificationRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέος κανόνας'),
        ];
    }
}
