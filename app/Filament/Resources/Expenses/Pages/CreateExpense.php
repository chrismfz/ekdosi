<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseSource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Support\PersistsExpenseLines;
use App\Models\Expense;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Manual expense creation. Page-controlled persistence (not a relationship
 * repeater) so we deterministically stamp company_id + line_number and recompute
 * header totals from the lines — see {@see PersistsExpenseLines}.
 */
class CreateExpense extends CreateRecord
{
    use PersistsExpenseLines;

    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $tenant = Filament::getTenant();
        [$header, $lines] = $this->splitExpenseData($data, $tenant->getKey());

        $header['company_id'] = $tenant->getKey();
        $header['source'] = ExpenseSource::Manual->value;

        $expense = Expense::create($header);
        $this->syncExpenseLines($expense, $lines, $tenant->getKey());

        return $expense;
    }
}
