<?php

namespace App\Filament\Support;

use App\Actions\StageServiceRenewal;
use App\Enums\DomainStatus;
use App\Enums\ServiceContractStatus;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\ServiceContract;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * «Προσχέδιο ανανέωσης τώρα» — the WHMCS «Invoice Selected Items» equivalent
 * (Πυλώνας A + Υπηρεσίες): stage the contract's next renewal draft ON DEMAND,
 * ahead of the nightly sweep — for the customer who wants to renew early (the
 * registrar's own expiry notices start arriving). One definition shared by the
 * ServiceContracts table and the Domains surfaces so guards never drift.
 *
 * Explicit operator intent DELIBERATELY bypasses auto_renew=off (the let-lapse
 * default is about the automatic sweep, not about a customer who called and
 * asked) — but never the dead-domain set: a name the tenant no longer holds is
 * unbillable from every path. Same ΠΡΟΣΧ semantics as the sweep: a DRAFT with
 * no ΑΑ, nothing near AADE until Οριστικοποίηση.
 */
class StageRenewalNowAction
{
    /** For a ServiceContract record (the Υπηρεσίες table). */
    public static function make(): Action
    {
        return self::base('stageRenewalNow', fn (ServiceContract $record): ?ServiceContract => $record);
    }

    /** For a Domain record (list/view) — resolves its 1:1 contract. */
    public static function forDomain(): Action
    {
        return self::base('stageDomainRenewalNow', fn (Domain $record): ?ServiceContract => $record->serviceContract);
    }

    /**
     * The testable core: guards + early-asOf + the existing staging action.
     * Returns the draft, or null when an open draft already covers the cursor.
     */
    public static function stageNow(ServiceContract $contract): ?Invoice
    {
        $domain = Domain::query()
            ->withTrashed()
            ->where('company_id', $contract->company_id)
            ->where('service_contract_id', $contract->id)
            ->first();
        if ($domain !== null) {
            $dead = $domain->trashed()
                || $domain->status->isTerminal()
                || $domain->status === DomainStatus::Redemption;
            if ($dead) {
                $state = $domain->trashed() ? 'διαγραμμένο' : $domain->status->getLabel();

                throw new RuntimeException(
                    'Το domain '.$domain->fqdn.' είναι «'.$state.'» — δεν χρεώνουμε όνομα που δεν κατέχουμε.'
                );
            }
        }

        // Early billing: treat the contract's own due date as arrived (the
        // sweep's --lead-days semantics, for exactly one contract).
        $due = $contract->next_due_date;
        $asOf = $due !== null ? Carbon::parse($due)->max(Carbon::today()) : Carbon::today();

        return app(StageServiceRenewal::class)($contract, $asOf);
    }

    private static function base(string $name, \Closure $resolveContract): Action
    {
        return Action::make($name)
            ->label('Προσχέδιο ανανέωσης τώρα')
            ->icon('heroicon-o-document-plus')
            ->color('gray')
            ->authorize('update')
            ->visible(function ($record) use ($resolveContract): bool {
                $contract = $resolveContract($record);

                return $contract !== null
                    && $contract->status === ServiceContractStatus::Active;
            })
            ->requiresConfirmation()
            ->modalDescription('Δημιουργεί ΠΡΟΧΕΙΡΟ ανανέωσης τώρα (χωρίς ΑΑ/myDATA — τίποτα δεν φεύγει πριν την Οριστικοποίηση) και προχωρά την επόμενη χρέωση έναν κύκλο. Για πελάτη που θέλει να ανανεώσει νωρίτερα.')
            ->action(function ($record) use ($resolveContract): void {
                $contract = $resolveContract($record);
                if ($contract === null) {
                    Notification::make()->title('Χωρίς συμβόλαιο ανανέωσης.')->warning()->send();

                    return;
                }

                try {
                    $invoice = self::stageNow($contract);
                } catch (\Throwable $e) {
                    Notification::make()->title('Δεν δημιουργήθηκε προσχέδιο.')->body($e->getMessage())->danger()->send();

                    return;
                }

                $invoice === null
                    ? Notification::make()
                        ->title('Υπάρχει ήδη ανοιχτό προσχέδιο ανανέωσης.')
                        ->body('Εκδώστε ή ακυρώστε το υπάρχον — δεν δημιουργούμε δεύτερο για τον ίδιο κύκλο.')
                        ->warning()->send()
                    : Notification::make()
                        ->title('Δημιουργήθηκε προσχέδιο ανανέωσης.')
                        ->body('Θα το βρείτε στα Παραστατικά ως «Πρόχειρο» — εκδίδεται όταν πληρωθεί/επιβεβαιωθεί.')
                        ->success()->send();
            });
    }
}
