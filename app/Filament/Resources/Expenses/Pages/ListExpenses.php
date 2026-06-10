<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseSource;
use App\Filament\BaseListRecords;
use App\Filament\Pages\MyDataConsoleExpenses;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Company;
use App\Models\Expense;
use App\Support\MyData\Codes;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Firebed\AadeMyData\Exceptions\RateLimitExceededException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ListExpenses extends BaseListRecords
{
    protected static string $resource = ExpenseResource::class;

    /**
     * «Άντληση από myDATA» — one click from the Έξοδα list: fetch the current
     * quarter's expense docs and jump straight to the «Κονσόλα myDATA — Έξοδα»
     * worklist with the αδέσποτα ready to import (no more «console → fetch →
     * back to Έξοδα» dance). The fetch is read-only; import stays operator-gated
     * on the console. Shown only to users who can actually open that console.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        if (! $this->canFetchMyData()) {
            return [];
        }

        return [
            Action::make('fetchFromMyData')
                ->label('Άντληση από myDATA')
                ->icon('heroicon-o-cloud-arrow-down')
                ->color('primary')
                ->tooltip('Κατεβάζει τα έξοδα του τρέχοντος τριμήνου από το myDATA και ανοίγει την Κονσόλα — Έξοδα με τα αδέσποτα έτοιμα προς καταχώριση. Δεν δημιουργεί εγγραφές.')
                ->action(fn () => $this->fetchFromMyData()),
        ];
    }

    /** Read-only fetch → land on the console worklist; surface AADE errors here. */
    private function fetchFromMyData(): void
    {
        /** @var Company $tenant */
        $tenant = Filament::getTenant();
        $now = now();

        try {
            MyDataConsoleExpenses::refreshSnapshot($tenant, $now->copy()->startOfQuarter(), $now->copy());
        } catch (RateLimitExceededException) {
            Notification::make()->title('Προσωρινό όριο myDATA')->body('Δοκιμάστε ξανά σε λίγο.')->warning()->send();

            return;
        } catch (RuntimeException $e) {
            Notification::make()->title('Η άντληση απέτυχε')->body($e->getMessage())->danger()->send();

            return;
        } catch (Throwable $e) {
            Log::warning('Expenses list myDATA fetch failed', [
                'company_id' => $tenant->getKey(), 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            Notification::make()->title('Η άντληση απέτυχε')->body('Σφάλμα σύνδεσης με το AADE.')->danger()->send();

            return;
        }

        $this->redirect(MyDataConsoleExpenses::getUrl(['tenant' => $tenant]));
    }

    /** A «τελευταία άντληση myDATA … · X αδέσποτα» line under the title. */
    public function getSubheading(): ?string
    {
        if (! $this->canFetchMyData()) {
            return null;
        }

        $tenantKey = Filament::getTenant()?->getKey();
        $at = MyDataConsoleExpenses::lastFetchAt($tenantKey);
        if ($at === null) {
            return 'myDATA: δεν έχει γίνει άντληση εξόδων ακόμη.';
        }

        $line = 'Τελευταία άντληση myDATA: '.$at->diffForHumans();
        $orphans = MyDataConsoleExpenses::lastOrphanCount($tenantKey);

        return $orphans > 0 ? $line." · {$orphans} αδέσποτα έξοδα προς καταχώριση" : $line;
    }

    private function canFetchMyData(): bool
    {
        $tenant = Filament::getTenant();

        return $tenant instanceof Company
            && $tenant->canReadMyData()
            && (bool) auth()->user()?->can('View:MyDataConsoleExpenses');
    }

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

        // The fixed economic buckets, then the operator's pinned-tag tabs
        // (appended after — same Έξοδα-style fast filters, tag-driven).
        return [
            'all' => Tab::make('Όλα'),

            'suppliers' => Tab::make('Προμηθευτών')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('source', ExpenseSource::Sync->value)),

            'ours' => Tab::make('Δικά μας παραστατικά')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->where(fn (Builder $w) => $w
                        ->whereNull('category')
                        ->orWhereNotIn('category', $accounting))),

            'accounting' => Tab::make('Λοιπά (πάγια/μισθοδοσία)')
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->where('source', ExpenseSource::SelfDeclared->value)
                    ->whereIn('category', $accounting)),
        ] + TagControls::tagTabs(Expense::class);
    }
}
