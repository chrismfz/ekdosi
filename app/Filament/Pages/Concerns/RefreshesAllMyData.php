<?php

namespace App\Filament\Pages\Concerns;

use App\Models\Company;
use App\Services\MyData\MyDataConsoleRefresh;
use App\Services\MyData\RefreshStep;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;

/**
 * The «Ανανέωση όλων» header action, shared by the three live console tabs
 * (Πωλήσεις / Έξοδα / Επισκόπηση Ε3). One click fetches ALL four myDATA pulls
 * for a window (via MyDataConsoleRefresh) and seeds every tab's cache, then
 * rehydrates the CURRENT page from its freshly written snapshot. The per-tab
 * «Έλεγχος» stays as a secondary, single-source refresh.
 *
 * Requires the host page to use RemembersLastFetch (for restoreFetch()).
 */
trait RefreshesAllMyData
{
    protected function refreshAllAction(): Action
    {
        return Action::make('refreshAll')
            ->label('Ανανέωση όλων')
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('primary')
            ->modalHeading('Ανανέωση όλων από myDATA')
            ->modalDescription('Κατεβάζει μαζί Πωλήσεις, Έξοδα, Επισκόπηση Ε3 και εικόνα ΦΠΑ για το διάστημα, και ενημερώνει όλες τις καρτέλες της κονσόλας. Read-only — δεν τροποποιεί παραστατικά.')
            ->modalSubmitActionLabel('Ανανέωση')
            ->schema([
                DatePicker::make('from')->label('Από')->required()->default(now()->startOfQuarter()),
                DatePicker::make('to')->label('Έως')->required()->default(now()),
            ])
            ->action(fn (array $data) => $this->runRefreshAll($data['from'], $data['to']));
    }

    protected function runRefreshAll(string $from, string $to): void
    {
        $tenant = Filament::getTenant();
        if (! $tenant instanceof Company) {
            return;
        }

        $steps = (new MyDataConsoleRefresh)->refreshAll(
            $tenant,
            Carbon::parse($from),
            Carbon::parse($to),
        );

        // Rehydrate THIS page from the snapshot the orchestrator just wrote, so the
        // current tab reflects the refresh without a reload (each page restores its
        // own cached props). The E3 tab also re-reads its ΦΠΑ-τριμήνου box.
        $this->restoreFetch();
        if (method_exists($this, 'loadVatQuarter')) {
            $this->loadVatQuarter();
        }

        $this->notifyRefreshSummary($steps);
    }

    /** @param  list<RefreshStep>  $steps */
    private function notifyRefreshSummary(array $steps): void
    {
        $failed = array_values(array_filter($steps, fn (RefreshStep $s) => ! $s->ok()));
        $okCount = count($steps) - count($failed);

        $title = $failed === []
            ? 'Η ανανέωση ολοκληρώθηκε'
            : "Ανανέωση: {$okCount}/".count($steps).' καρτέλες';

        $body = $failed === []
            ? 'Όλες οι καρτέλες ενημερώθηκαν.'
            : 'Δεν ενημερώθηκαν: '.implode(' · ', array_map(
                fn (RefreshStep $s) => $s->label.($s->note ? " ({$s->note})" : ''),
                $failed,
            ));

        Notification::make()
            ->title($title)
            ->body($body)
            ->{$failed === [] ? 'success' : 'warning'}()
            ->send();
    }
}
