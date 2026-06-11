<?php

namespace App\Console\Commands;

use App\Filament\Resources\Invoices\InvoiceResource;
use App\Models\Company;
use App\Models\Invoice;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;

/**
 * #6 — overdue digest as Filament DATABASE notifications (the bell), NO email.
 *
 *   php artisan invoices:notify-overdue [--tenant=SLUG] [--dry-run]
 *
 * For each tenant with overdue receivables, sends ONE summary notification
 * (count + total €, linking to the invoices list pre-filtered to overdue) to
 * every user of that tenant. Meant to run on a daily schedule (default OFF —
 * see config/ekdosi.php → schedule.overdue_notifications_enabled). The dashboard
 * «Ληξιπρόθεσμα τιμολόγια» widget is the always-on surface; this is the nudge.
 *
 * Read-only: it never touches money or invoice state. Overdue is the single
 * Invoice::scopeOverdue predicate shared with the list filter + the widget.
 */
class NotifyOverdueInvoices extends Command
{
    protected $signature = 'invoices:notify-overdue {--tenant= : Limit to one company slug} {--dry-run : Report only, send nothing}';

    protected $description = 'Στέλνει ειδοποίηση (bell) για ληξιπρόθεσμα τιμολόγια ανά tenant — χωρίς email.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($companies->isEmpty()) {
            $this->warn('Δεν βρέθηκε tenant.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $overdue = Invoice::query()
                ->where('invoices.company_id', $company->id)
                ->whereNull('invoices.deleted_at')
                ->overdue()
                ->get(['id', 'gross_total', 'credited_total', 'paid_total']);

            $count = $overdue->count();
            if ($count === 0) {
                $this->line("[{$company->slug}] κανένα ληξιπρόθεσμο.");

                continue;
            }

            $total = round($overdue->sum(fn (Invoice $i) => $i->payableTotal() - (float) $i->credited_total - (float) $i->paid_total), 2);
            $totalLabel = number_format($total, 2, ',', '.').' €';

            $this->info("[{$company->slug}] {$count} ληξιπρόθεσμα, σύνολο {$totalLabel}".($dryRun ? ' (dry-run)' : ''));

            if ($dryRun) {
                continue;
            }

            $users = $company->users()->get();
            foreach ($users as $user) {
                Notification::make()
                    ->title('Ληξιπρόθεσμα τιμολόγια')
                    ->body("{$count} τιμολόγια εκπρόθεσμα — σύνολο {$totalLabel}.")
                    ->icon('heroicon-o-exclamation-triangle')
                    ->warning()
                    ->actions([
                        Action::make('view')
                            ->label('Προβολή')
                            ->url(InvoiceResource::getUrl('index', ['tableFilters' => ['overdue' => ['isActive' => true]]], tenant: $company))
                            ->markAsRead(),
                    ])
                    ->sendToDatabase($user);
            }
        }

        return self::SUCCESS;
    }
}
