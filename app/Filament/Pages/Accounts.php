<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Support\Accounting\ChartOfAccounts;
use App\Support\MyData\Codes;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use UnitEnum;

/**
 * «Λογαριασμοί» — a read-only reference of the indicative ΕΓΛΣ chart and the
 * default myDATA-classification → account mapping the Βιβλίο Εσόδων-Εξόδων
 * uses. NOT editable (the mapping is a standard convenience layer; the
 * accountant's own software does the definitive mapping). Lives in the
 * «Λογιστικά» group next to the book.
 */
class Accounts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Λογιστικά';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.pages.accounts';

    public static function getNavigationLabel(): string
    {
        return 'Λογαριασμοί';
    }

    public function getTitle(): string
    {
        return 'Λογαριασμοί (ΕΓΛΣ)';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:Accounts');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public function getChart(): array
    {
        return ChartOfAccounts::chart();
    }

    /**
     * The default classification → account mapping, split income/expense, with
     * Greek labels for both sides.
     *
     * @return array{income: list<array<string,?string>>, expense: list<array<string,?string>>}
     */
    public function getMapping(): array
    {
        $income = [];
        $expense = [];

        foreach (ChartOfAccounts::CATEGORY_MAP as $category => $code) {
            $entry = [
                'category' => $category,
                'categoryLabel' => Codes::e3CategoryLabel($category),
                'code' => $code,
                'name' => ChartOfAccounts::nameFor($code),
            ];

            if (str_starts_with($category, 'category1_')) {
                $income[] = $entry;
            } else {
                $expense[] = $entry;
            }
        }

        return ['income' => $income, 'expense' => $expense];
    }
}
