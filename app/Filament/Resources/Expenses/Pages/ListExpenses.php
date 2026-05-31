<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseSource;
use App\Filament\BaseListRecords;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Support\MyData\Codes;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListExpenses extends BaseListRecords
{
    protected static string $resource = ExpenseResource::class;

    /**
     * Three buckets so supplier invoices, our own real expense documents
     * (13/14), and our accounting entries (μισθοδοσία/πάγια, 17.x) don't read
     * as one undifferentiated list — a €5k payroll must not look like a
     * τιμολόγιο. The "accounting" category set comes from Codes
     * (ACCOUNTING_EXPENSE_CATEGORIES) so the taxonomy lives in one place, not
     * hand-typed here.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $accounting = Codes::ACCOUNTING_EXPENSE_CATEGORIES;

        return [
            'all' => Tab::make('Όλα'),

            'suppliers' => Tab::make('Προμηθευτών')
                ->modifyQueryUsing(fn (Builder $q) => $q->where('source', ExpenseSource::Sync->value)),

            'ours' => Tab::make('Δικά μας παραστατικά')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->where(fn (Builder $w) => $w
                        ->whereNull('category')
                        ->orWhereNotIn('category', $accounting))),

            'accounting' => Tab::make('Λοιπά (πάγια/μισθοδοσία)')
                ->modifyQueryUsing(fn (Builder $q) => $q
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->whereIn('category', $accounting)),
        ];
    }
}
