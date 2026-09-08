<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\BaseListRecords;
use App\Filament\Resources\Invoices\InvoiceResource;
use App\Filament\Support\Tags\TagControls;
use App\Models\Company;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

class ListInvoices extends BaseListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('+ Νέο Παραστατικό'),
            $this->recomputeBalancesAction(),
        ];
    }

    /**
     * Admin repair: rebuild the denormalised money cache
     * (paid_total/credited_total/payment_status) of THIS company's invoices from
     * payments + credit notes. Safe + idempotent — the same `invoices:recompute-
     * balances` the CLI/scheduler runs, scoped to the active tenant, no terminal.
     *
     * Rehomed here from the retired «Εργαλεία» (MaintenanceTools) page, whose last
     * button this was — it lives where invoices/money do. Gated on the existing
     * `View:CompanySettings` ability (company_admin + super_admin, the exact
     * audience the old page had), so operators don't see it and no page-bound
     * permission is left dangling.
     */
    private function recomputeBalancesAction(): Action
    {
        return Action::make('recompute_balances')
            ->label('Επανυπολογισμός υπολοίπων')
            ->icon('heroicon-o-calculator')
            ->color('gray')
            ->visible(fn (): bool => (bool) auth()->user()?->can('View:CompanySettings'))
            ->requiresConfirmation()
            ->modalHeading('Επανυπολογισμός υπολοίπων εταιρίας')
            ->modalDescription('Ξαναϋπολογίζει τα cache πεδία χρημάτων (πληρωμένο/πιστωμένο/κατάσταση) όλων των παραστατικών αυτής της εταιρίας από πληρωμές + πιστωτικά. Ασφαλές, idempotent.')
            ->action(function (): void {
                $tenant = Filament::getTenant();
                if (! $tenant instanceof Company) {
                    Notification::make()->title('Δεν υπάρχει ενεργή εταιρία.')->danger()->send();

                    return;
                }

                try {
                    $exit = Artisan::call('invoices:recompute-balances', ['--company' => $tenant->getKey()]);
                    $output = trim(Artisan::output());
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('Επανυπολογισμός υπολοίπων — σφάλμα')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return;
                }

                $note = Notification::make()
                    ->title('Επανυπολογισμός υπολοίπων'.($exit === 0 ? ' — ολοκληρώθηκε' : ' — ολοκληρώθηκε με προειδοποιήσεις'))
                    ->body(Str::limit($output === '' ? '(χωρίς έξοδο)' : $output, 600));
                $exit === 0 ? $note->success() : $note->warning();
                $note->send();
            });
    }

    public function getTabs(): array
    {
        $outbox = Invoice::query()->awaitingMyData()->count();

        return [
            'all' => Tab::make('Όλα'),

            // The myDATA «Outbox»: live παραστατικά that should be filed but carry
            // no MARK (drafts + failed/skipped submissions). One click to the
            // worklist of «τι μένει να υποβληθεί». Badge only when there's work.
            'outbox' => Tab::make('Προς υποβολή')
                ->icon('heroicon-o-cloud-arrow-up')
                ->badge($outbox ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingMyData()),
        ] + TagControls::tagTabs(Invoice::class);
    }
}
