<?php

namespace App\Filament\Pages;

use App\Models\Company;
use App\Services\Accounting\LedgerBook as LedgerBookService;
use App\Services\Accounting\LedgerBookResult;
use App\Support\MyData\Codes;
use BackedEnum;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Firebed\AadeMyData\Enums\ExpenseClassificationCategory;
use UnitEnum;

/**
 * «Βιβλίο Εσόδων-Εξόδων» — a read-only, chronological book of the tenant's
 * documents (invoices = έσοδα, expenses = έξοδα), classified by the myDATA
 * category we already store and totalled the way an απλογραφικό βιβλίο (Β'
 * κατηγορίας) reads. Built on top of LedgerBook (a pure read-model over the
 * existing tables — no new persistence, no effect on the money/myDATA path).
 *
 * Filters (period / book / category) are plain Livewire props bound live in the
 * Blade; the view pulls a fresh LedgerBookResult on every render. Admin-gated
 * on View:LedgerBook (run shield:generate after deploy), like Reports.
 */
class LedgerBook extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static string|UnitEnum|null $navigationGroup = 'Data';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.ledger-book';

    public ?string $from = null;

    public ?string $to = null;

    public string $book = 'all';

    public ?string $category = null;

    public static function getNavigationLabel(): string
    {
        return 'Βιβλίο Εσόδων-Εξόδων';
    }

    public function getTitle(): string
    {
        return 'Βιβλίο Εσόδων-Εξόδων';
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:LedgerBook');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        $this->from ??= now()->startOfMonth()->toDateString();
        $this->to ??= now()->endOfMonth()->toDateString();
    }

    public function getResult(): LedgerBookResult
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();

        $start = Carbon::parse($this->from ?: now()->startOfMonth()->toDateString())->startOfDay();
        $end = Carbon::parse($this->to ?: now()->endOfMonth()->toDateString())->endOfDay();

        return (new LedgerBookService($tenant))->forPeriod(
            $start,
            $end,
            in_array($this->book, ['income', 'expense'], true) ? $this->book : 'all',
            $this->category ?: null,
        );
    }

    /**
     * value => "code — Greek label" for the category filter: income
     * (category1_x) + expense (category2_x), both resolved to Greek.
     *
     * @return array<string, string>
     */
    public function getCategoryOptions(): array
    {
        $out = [];

        foreach (Codes::INCOME_CLASS_CATEGORIES as $code) {
            $label = Codes::e3CategoryLabel($code);
            $out[$code] = $code.($label ? ' — '.$label : '');
        }

        foreach (ExpenseClassificationCategory::cases() as $case) {
            $out[$case->value] = $case->value.' — '.$case->label();
        }

        return $out;
    }
}
