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
     * @return array{connection: array{ok: bool, text: string}, missing_afm: list<string>, trial_only: list<string>, blocking: bool}
     */
    public function checks(Company $company): array
    {
        // 1. Production login + employer (read-only EX_BASE_01).
        $prod = clone $company;
        $prod->ergani_mode = 'production';   // in memory only
        try {
            $info = (new ErganiClient($prod))->employerInfo();
            $afmOk = blank($info['afm']) || blank($company->afm) || $info['afm'] === $company->afm;
            $connection = ['ok' => $afmOk, 'text' => $afmOk
                ? 'Σύνδεση Παραγωγής OK — '.($info['name'] ?? '—').' (ΑΦΜ '.($info['afm'] ?? '—').')'
                : 'Ο ΑΦΜ του ΕΡΓΑΝΗ ('.$info['afm'].') δεν ταιριάζει με της εταιρείας ('.$company->afm.')'];
        } catch (\RuntimeException $e) {
            $connection = ['ok' => false, 'text' => 'Αποτυχία σύνδεσης Παραγωγής: '.$e->getMessage()];
        } catch (ConnectionException) {
            $connection = ['ok' => false, 'text' => 'Το ΕΡΓΑΝΗ (Παραγωγή) δεν απαντά — δοκιμάστε αργότερα.'];
        }

        // 2. Every active employee needs a ΑΦΜ (ΕΡΓΑΝΗ identifies them by it).
        $missingAfm = Employee::query()->where('company_id', $company->getKey())->where('is_active', true)
            ->whereNull('afm')->orderBy('last_name')->get()->map(fn (Employee $e): string => $e->full_name)->all();

        // 3. Future items that exist ONLY in the trial (no legal force) — someone must have declared them for real.
        $today = now()->toDateString();
        $trialOnly = LeaveRequest::query()->where('company_id', $company->getKey())
            ->where('status', LeaveStatus::Approved->value)->whereDate('ends_on', '>=', $today)
            ->where(fn ($q) => $q->whereNull('ergani_env')->orWhere('ergani_env', 'trial'))
            ->with('employee')->orderBy('starts_on')->get()
            ->map(fn (LeaveRequest $l): string => 'Άδεια · '.$l->employee?->full_name.' · '.$l->periodLabel())
            ->merge(OvertimeDeclaration::query()->where('company_id', $company->getKey())
                ->whereDate('work_date', '>=', $today)->where('ergani_status', 'submitted')->where('ergani_env', 'trial')
                ->with('employee')->orderBy('work_date')->get()
                ->map(fn (OvertimeDeclaration $o): string => 'Υπερωρία · '.$o->employee?->full_name.' · '.$o->slotLabel()))
            ->values()->all();

        return [
            'connection' => $connection,
            'missing_afm' => $missingAfm,
            'trial_only' => $trialOnly,
            'blocking' => ! $connection['ok'] || $missingAfm !== [],
        ];
    }

    /**
     * Switch to production. Re-checks first; refuses (returns the reason) when a
     * blocking check fails. Returns null on success.
     */
    public function goLive(Company $company, User $by, bool $emailAccountant): ?string
    {
        if ($company->ergani_mode === 'production') {
            return 'Η εταιρεία είναι ήδη σε Παραγωγή.';
        }
        $checks = $this->checks($company);
        if ($checks['blocking']) {
            return ! $checks['connection']['ok']
                ? $checks['connection']['text']
                : 'Λείπει ΑΦΜ σε: '.implode(', ', $checks['missing_afm']).'.';
        }

        $company->forceFill([
            'ergani_mode' => 'production',
            'ergani_production_since' => now(),
            'ergani_production_by_user_id' => $by->getKey(),
        ])->save();
        Log::notice('ΕΡΓΑΝΗ switched to PRODUCTION', ['company' => $company->getKey(), 'by' => $by->getKey()]);

        $to = trim((string) $company->leave_notify_email);
        if ($emailAccountant && $to !== '') {
            try {
                $this->mailers->for($company)->to($to)->send(new ErganiGoLiveMail(
                    $company,
                    $company->mail_from_address ?: (string) config('mail.from.address'),
                    $company->mail_from_name ?: $company->name,
                ));
            } catch (\Throwable $e) {
                Log::warning('ΕΡΓΑΝΗ go-live accountant mail failed', ['company' => $company->getKey(), 'error' => $e->getMessage()]);

                return null;   // switched; the page warns that the email wasn't sent
            }
        }

        return null;
    }

    public function backToTrial(Company $company): void
    {
        $company->forceFill(['ergani_mode' => 'trial', 'ergani_production_since' => null, 'ergani_production_by_user_id' => null])->save();
        Log::notice('ΕΡΓΑΝΗ switched back to TRIAL', ['company' => $company->getKey()]);
    }
}
