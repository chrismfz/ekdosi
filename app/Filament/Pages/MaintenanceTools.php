<?php

namespace App\Filament\Pages;

use App\Models\Company;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

/**
 * «Εργαλεία» — surfaces a few safe, idempotent maintenance artisan commands as
 * one-click buttons for the CURRENT tenant, so an operator never needs terminal
 * access for routine upkeep. Each button runs the same command the scheduler /
 * CLI runs, scoped to this company, and shows the captured output on the page.
 *
 * The myDATA bits moved into the Κονσόλα myDATA cluster: the config audit → the
 * structured «Έλεγχος ρυθμίσεων» tab; the εικόνα ΦΠΑ refresh → the «Ανανέωση όλων»
 * one-fetch on the console tabs. What remains here is the non-myDATA upkeep.
 *
 * Admin territory (gated on View:MaintenanceTools — company_admin + super_admin;
 * run shield:generate + shield:sync-super-admin after deploy so the permission
 * exists). Only read-only / re-runnable commands are exposed — nothing that
 * issues, cancels, or deletes.
 */
class MaintenanceTools extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 98;

    protected string $view = 'filament.pages.maintenance-tools';

    /** Captured output of the last command run (rendered on the page). */
    public ?string $lastOutput = null;

    public ?string $lastCommand = null;

    public ?string $lastStatus = null;

    public static function getNavigationLabel(): string
    {
        return 'Εργαλεία';
    }

    public function getTitle(): string
    {
        return 'Εργαλεία συντήρησης';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Setup';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return Filament::getTenant() instanceof Company
            && (bool) auth()->user()?->can('View:MaintenanceTools');
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->commandAction(
                key: 'recompute_balances',
                label: 'Επανυπολογισμός υπολοίπων',
                icon: 'heroicon-o-calculator',
                command: 'invoices:recompute-balances',
                params: fn (Company $c) => ['--company' => $c->getKey()],
                confirm: 'Ξαναϋπολογίζει τα cache πεδία χρημάτων (πληρωμένο/πιστωμένο/κατάσταση) όλων των παραστατικών αυτής της εταιρίας από πληρωμές + πιστωτικά. Ασφαλές, idempotent.',
                color: 'gray',
            ),
        ];
    }

    /**
     * Build a header-button that runs an artisan command scoped to the current
     * tenant and captures its output onto the page.
     *
     * @param  callable(Company):array<string,mixed>  $params
     */
    private function commandAction(
        string $key,
        string $label,
        string $icon,
        string $command,
        callable $params,
        ?string $confirm,
        string $color,
    ): Action {
        $action = Action::make($key)
            ->label($label)
            ->icon($icon)
            ->color($color)
            ->action(function () use ($command, $params, $label): void {
                $tenant = Filament::getTenant();
                if (! $tenant instanceof Company) {
                    Notification::make()->title('Δεν υπάρχει ενεργή εταιρία.')->danger()->send();

                    return;
                }

                try {
                    $exit = Artisan::call($command, $params($tenant));
                    $output = trim(Artisan::output());
                } catch (\Throwable $e) {
                    $this->lastCommand = $command;
                    $this->lastStatus = 'error';
                    $this->lastOutput = $e->getMessage();
                    Notification::make()->title($label.' — σφάλμα')->body($e->getMessage())->danger()->persistent()->send();

                    return;
                }

                $this->lastCommand = $command;
                $this->lastStatus = $exit === 0 ? 'ok' : 'warn';
                $this->lastOutput = $output === '' ? '(χωρίς έξοδο)' : $output;

                $note = Notification::make()
                    ->title($label.($exit === 0 ? ' — ολοκληρώθηκε' : ' — ολοκληρώθηκε με προειδοποιήσεις'))
                    ->body(Str::limit($this->lastOutput, 600));
                $exit === 0 ? $note->success() : $note->warning();
                $note->send();
            });

        if ($confirm !== null) {
            $action->requiresConfirmation()->modalDescription($confirm);
        }

        return $action;
    }
}
