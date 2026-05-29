<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Expenses\ExpenseResource;

class ListExpenses extends BaseListRecords
{
    protected static string $resource = ExpenseResource::class;
}
