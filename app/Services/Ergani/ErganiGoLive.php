<?php

namespace App\Services\Ergani;

use App\Enums\LeaveStatus;
use App\Mail\ErganiGoLiveMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use App\Models\User;
use App\Services\TenantMailerFactory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * «Πέρασμα σε Παραγωγή» — the checks before ekdosi starts declaring to ΕΡΓΑΝΗ for
 * REAL, and the switch itself. The biggest practical risk is a DOUBLE declaration
 * (ekdosi AND the accountant, out of habit), so the switch requires the operator
 * to confirm the accountant was told — and can email them. Checks are re-run at
 * switch time; the modal's view is never trusted.
 */
class ErganiGoLive
{
    public function __construct(private readonly TenantMailerFactory $mailers) {}

    /**
     * @return array{connection: array{ok: bool, text: string}, missing_afm: list<string>, trial_only: list<string>, undeclared: list<string>, blocking: bool}
     */
    public function checks(Company $company): array
    {
        // 1. Production login + employer (read-only EX_BASE_01).
        $prod = clone $company;
        $prod->ergani_mode = 'production';   // in memory only
        try {
            $info = (new ErganiClient($prod))->employerInfo();
            // Fail CLOSED: the gate to real declarations needs a positive ΑΦΜ match.
            $afmOk = filled($info['afm']) && filled($company->afm) && $info['afm'] === $company->afm;
            $connection = ['ok' => $afmOk, 'text' => match (true) {
                $afmOk => 'Σύνδεση Παραγωγής OK — '.($info['name'] ?? '—').' (ΑΦΜ '.$info['afm'].')',
                blank($company->afm) => 'Η εταιρεία δεν έχει ΑΦΜ — συμπληρώστε τον πρώτα.',
                blank($info['afm']) => 'Το ΕΡΓΑΝΗ δεν επέστρεψε ΑΦΜ εργοδότη — δεν μπορεί να επιβεβαιωθεί ο λογαριασμός.',
                default => 'Ο ΑΦΜ του ΕΡΓΑΝΗ ('.$info['afm'].') δεν ταιριάζει με της εταιρείας ('.$company->afm.') — λάθος κωδικοί;',
            }];
        } catch (\RuntimeException $e) {
            $connection = ['ok' => false, 'text' => 'Αποτυχία σύνδεσης Παραγωγής: '.$e->getMessage()];
        } catch (ConnectionException) {
            $connection = ['ok' => false, 'text' => 'Το ΕΡΓΑΝΗ (Παραγωγή) δεν απαντά — δοκιμάστε αργότερα.'];
        }

        // 2. Every active employee needs a ΑΦΜ (ΕΡΓΑΝΗ identifies them by it).
        $missingAfm = Employee::query()->where('company_id', $company->getKey())->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('afm')->orWhere('afm', ''))->orderBy('last_name')->get()->map(fn (Employee $e): string => $e->full_name)->all();

        // 3. Future approved items with NO real declaration from ekdosi: either declared
        //    only in the trial (no legal force) or not declared at all — the accountant
        //    must have declared them by hand, or someone must now.
        $today = now()->toDateString();
        $leaves = LeaveRequest::query()->where('company_id', $company->getKey())
            ->where('status', LeaveStatus::Approved->value)->whereDate('ends_on', '>=', $today)
            ->where(fn ($q) => $q->whereNull('ergani_env')->orWhere('ergani_env', 'trial'))
            ->with('employee')->orderBy('starts_on')->get();
        $overtime = OvertimeDeclaration::query()->where('company_id', $company->getKey())
            ->whereDate('work_date', '>=', $today)
            ->where(fn ($q) => $q->whereNull('ergani_env')->orWhere('ergani_env', 'trial'))
            ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', '!=', 'superseded'))
            ->with('employee')->orderBy('work_date')->get();
        $leaveLabel = fn (LeaveRequest $l): string => 'Άδεια · '.$l->employee?->full_name.' · '.$l->periodLabel();
        $otLabel = fn (OvertimeDeclaration $o): string => 'Υπερωρία · '.$o->employee?->full_name.' · '.$o->slotLabel();
        $trialOnly = $leaves->where('ergani_status', 'submitted')->map($leaveLabel)
            ->merge($overtime->where('ergani_status', 'submitted')->map($otLabel))->values()->all();
        $undeclared = $leaves->where('ergani_status', '!=', 'submitted')->map($leaveLabel)
            ->merge($overtime->where('ergani_status', '!=', 'submitted')->map($otLabel))->values()->all();

        return [
            'connection' => $connection,
            'missing_afm' => $missingAfm,
            'trial_only' => $trialOnly,
            'undeclared' => $undeclared,
            'blocking' => ! $connection['ok'] || $missingAfm !== [],
        ];
    }

    /**
     * Switch to production. Re-checks first; refuses (error = the reason) when a
     * blocking check fails. `mailed` tells the page whether the accountant got the
     * written «stop declaring» notice (null = not requested / no address).
     *
     * @return array{error: ?string, mailed: ?bool}
     */
    public function goLive(Company $company, User $by, bool $emailAccountant): array
    {
        if ($company->ergani_mode === 'production') {
            return ['error' => 'Η εταιρεία είναι ήδη σε Παραγωγή.', 'mailed' => null];
        }
        $checks = $this->checks($company);
        if ($checks['blocking']) {
            return ['error' => ! $checks['connection']['ok']
                ? $checks['connection']['text']
                : 'Λείπει ΑΦΜ σε: '.implode(', ', $checks['missing_afm']).'.', 'mailed' => null];
        }

        $company->forceFill([
            'ergani_mode' => 'production',
            'ergani_production_since' => now(),
            'ergani_production_by_user_id' => $by->getKey(),
        ])->save();
        Log::notice('ΕΡΓΑΝΗ switched to PRODUCTION', ['company' => $company->getKey(), 'by' => $by->getKey()]);
        $this->audit($company, $by, 'ΕΡΓΑΝΗ: πέρασμα σε Παραγωγή');

        $to = trim((string) $company->leave_notify_email);
        if (! $emailAccountant || $to === '') {
            return ['error' => null, 'mailed' => null];
        }
        try {
            $this->mailers->for($company)->to($to)->send(new ErganiGoLiveMail(
                $company,
                $company->mail_from_address ?: (string) config('mail.from.address'),
                $company->mail_from_name ?: $company->name,
                $checks['trial_only'],
                $checks['undeclared'],
            ));
        } catch (\Throwable $e) {
            Log::warning('ΕΡΓΑΝΗ go-live accountant mail failed', ['company' => $company->getKey(), 'error' => $e->getMessage()]);

            return ['error' => null, 'mailed' => false];   // switched — the page warns loudly
        }

        return ['error' => null, 'mailed' => true];
    }

    public function backToTrial(Company $company, ?User $by = null): void
    {
        $company->forceFill(['ergani_mode' => 'trial', 'ergani_production_since' => null, 'ergani_production_by_user_id' => null])->save();
        Log::notice('ΕΡΓΑΝΗ switched back to TRIAL', ['company' => $company->getKey()]);
        $this->audit($company, $by, 'ΕΡΓΑΝΗ: επιστροφή σε Δοκιμαστικό');
    }

    /** A durable trail in the tenant's «Δραστηριότητα» (the columns are cleared on the way back). */
    private function audit(Company $company, ?User $by, string $what): void
    {
        try {
            activity('ergani')->performedOn($company)->causedBy($by)
                ->tap(fn ($activity) => $activity->company_id = $company->getKey())
                ->log($what);
        } catch (\Throwable $e) {
            Log::warning('ΕΡΓΑΝΗ switch audit failed', ['company' => $company->getKey(), 'error' => $e->getMessage()]);
        }
    }
}
