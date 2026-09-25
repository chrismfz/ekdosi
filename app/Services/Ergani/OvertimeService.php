<?php

namespace App\Services\Ergani;

use App\Filament\Resources\OvertimeDeclarations\OvertimeDeclarationResource;
use App\Mail\OvertimeAccountantMail;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\OvertimeDeclaration;
use App\Models\User;
use App\Services\Hr\LeaveWorkflow;
use App\Services\TenantMailerFactory;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Υπερωρία → ΕΡΓΑΝΗ «WTOOv» (docs/ergani/README.md). Trial-verified 2026-09-26:
 * f_type «ΥΠ» accepted; a slot that already started → 400 «Η υποβολή σας
 * θεωρείται εκπρόθεσμη» — so ekdosi refuses it BEFORE calling ΕΡΓΑΝΗ.
 *
 * Same hardening as leaves/cards: atomic claim, «failed» (certainly not sent)
 * vs «unknown» (may have landed → retry only after the operator confirms), an
 * append-only ergani_submissions row per attempt. A WTOOv can't be withdrawn
 * via the API, so a declaration is never edited or deleted here.
 */
class OvertimeService
{
    public const DOCUMENT = 'WTOOv';

    public const TYPE = 'ΥΠ';

    public function __construct(private readonly TenantMailerFactory $mailers) {}

    public static function enabledFor(?Company $company): bool
    {
        return $company instanceof Company
            && $company->hasErgani()
            && (bool) $company->ergani_submit_overtime
            && filled($company->ergani_username)
            && filled($company->ergani_password);
    }

    /**
     * Record + declare one overtime slot. Throws OvertimeRefused (Greek message)
     * when it can't be declared at all — nothing is stored then.
     */
    public function declare(Employee $employee, string $date, string $from, string $to, ?int $userId, ?string $note = null): OvertimeDeclaration
    {
        $company = $employee->company;
        if (! self::enabledFor($company)) {
            throw new OvertimeRefused('Η δήλωση υπερωριών στο ΕΡΓΑΝΗ δεν είναι ενεργή για την εταιρεία.');
        }
        if (! $employee->is_active || $employee->trashed()) {
            throw new OvertimeRefused('Ο εργαζόμενος δεν είναι ενεργός.');
        }
        if (blank($employee->afm)) {
            throw new OvertimeRefused('Λείπει ο ΑΦΜ του εργαζομένου — συμπληρώστε τον στην καρτέλα του.');
        }
        if (LeaveErganiSubmitter::erganiName((string) $employee->last_name, 50) === '' || LeaveErganiSubmitter::erganiName((string) $employee->first_name, 30) === '') {
            throw new OvertimeRefused('Λείπει επώνυμο ή όνομα του εργαζομένου — το ΕΡΓΑΝΗ τα απαιτεί.');
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) {
            throw new OvertimeRefused('Οι ώρες γράφονται ΩΩ:ΛΛ (π.χ. 18:00).');
        }
        if (OvertimeDeclaration::minutes($from, $to) <= 0) {
            throw new OvertimeRefused('Η λήξη πρέπει να είναι μετά την έναρξη, την ίδια μέρα (υπερωρία μετά τα μεσάνυχτα: δηλώστε τη στο ΕΡΓΑΝΗ).');
        }
        $starts = CarbonImmutable::parse($date.' '.$from, 'Europe/Athens');
        if ($starts->lte(now())) {
            throw new OvertimeRefused('Η υπερωρία δηλώνεται ΠΡΙΝ ξεκινήσει — για '.$starts->format('d/m H:i').' είναι πια εκπρόθεσμη (το ΕΡΓΑΝΗ την απορρίπτει).');
        }
        // Per-employee lock: two admins (or a double click) can't both pass the
        // overlap check and declare the same hours twice — a WTOOv can't be withdrawn.
        $declaration = DB::transaction(function () use ($employee, $starts, $from, $to, $note, $userId): OvertimeDeclaration {
            Employee::query()->withoutGlobalScopes()->whereKey($employee->getKey())->lockForUpdate()->first();
            $mode = (string) $employee->company->ergani_mode;
            if (self::overlapping((int) $employee->getKey(), $starts->toDateString(), $from, $to, $mode)->exists()) {
                throw new OvertimeRefused('Υπάρχει ήδη δηλωμένη υπερωρία του εργαζομένου που επικαλύπτει αυτές τις ώρες.');
            }
            // An older FAILED attempt for these hours is replaced by this one — retire
            // it so its «Δήλωση στο ΕΡΓΑΝΗ» retry can never declare the same hours again.
            self::overlapping((int) $employee->getKey(), $starts->toDateString(), $from, $to, $mode, ['failed'])
                ->update(['ergani_status' => 'superseded', 'updated_at' => now(),
                    'ergani_error' => 'Αντικαταστάθηκε από νέα δήλωση '.$from.'–'.$to.' — ώρες εκτός αυτής ΔΕΝ δηλώθηκαν.']);

            return OvertimeDeclaration::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'work_date' => $starts->toDateString(),
                'from_time' => $from,
                'to_time' => $to,
                'note' => filled($note) ? mb_substr((string) $note, 0, 200) : null,
                'created_by_user_id' => $userId,
            ]);
        });

        $this->submit($declaration, $userId);

        return $declaration->fresh();
    }

    /** Declare (or retry) one slot. $confirmedUnknown = the operator checked ΕΡΓΑΝΗ after an «unknown». */
    public function submit(OvertimeDeclaration $declaration, ?int $userId = null, bool $confirmedUnknown = false): bool
    {
        $declaration->loadMissing(['employee', 'company']);
        $company = $declaration->company;
        if (! self::enabledFor($company)) {
            return false;
        }

        // Claim under the employee lock AND re-check overlaps (excluding itself): a
        // retry of an old row must never re-declare hours another row already holds.
        $claimed = DB::transaction(function () use ($declaration, $company, $confirmedUnknown): int|string {
            Employee::query()->withoutGlobalScopes()->whereKey($declaration->employee_id)->lockForUpdate()->first();
            $clash = self::overlapping((int) $declaration->employee_id, $declaration->work_date->toDateString(),
                $declaration->from_time, $declaration->to_time, (string) $company->ergani_mode)
                ->whereKeyNot($declaration->getKey())->exists();
            if ($clash) {
                return 'clash';
            }

            return OvertimeDeclaration::query()->withoutGlobalScopes()
                ->whereKey($declaration->getKey())
                ->where(fn ($q) => $q
                    ->when(! $confirmedUnknown, fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', 'failed'))
                    ->when($confirmedUnknown, fn ($q) => $q->where('ergani_status', 'unknown')
                        ->orWhere(fn ($q) => $q->where('ergani_status', 'submitting')
                            ->where('updated_at', '<', now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES)))))
                // A maybe-landed row is retried only in the environment it went to —
                // never «moved» to another one (its possible declaration would be lost).
                ->when($confirmedUnknown, fn ($q) => $q->where(fn ($q) => $q->whereNull('ergani_env')->orWhere('ergani_env', $company->ergani_mode)))
                ->update(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode, 'updated_at' => now()]);
        });
        if ($claimed === 'clash') {
            $why = 'Οι ίδιες ώρες είναι ήδη σε άλλη δήλωση υπερωρίας — δεν ξαναστάλθηκε.';
            // Only a row that was certainly NOT declared is retired; an «unknown» (may
            // have landed) keeps its state so it still blocks those hours.
            in_array($declaration->ergani_status, [null, 'failed'], true)
                ? $declaration->forceFill(['ergani_status' => 'superseded', 'ergani_error' => $why])->saveQuietly()
                : $declaration->forceFill(['ergani_error' => $why])->saveQuietly();

            return false;
        }
        if ($claimed === 0) {
            return false;
        }
        $declaration->forceFill(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode])->syncOriginal();

        if (blank($declaration->employee?->afm) || blank($company->afm)) {
            return $this->refuse($declaration, $userId, 'Λείπει ο ΑΦΜ του εργαζομένου ή της εταιρείας.');
        }
        if ($declaration->hasStarted()) {
            return $this->refuse($declaration, $userId, 'Η υπερωρία έχει ήδη ξεκινήσει — εκπρόθεσμη, το ΕΡΓΑΝΗ δεν τη δέχεται.');
        }

        $payload = $this->payload($declaration);
        try {
            $client = new ErganiClient($company);
            $client->ensureToken();
        } catch (\Throwable $e) {
            return $this->refuse($declaration, $userId, $e->getMessage(), $payload);
        }

        try {
            $result = $client->submit(self::DOCUMENT, $payload);
        } catch (\Throwable $e) {
            return $this->unknown($declaration, $payload, $userId, 'το ΕΡΓΑΝΗ δεν απάντησε: '.$e->getMessage());
        }

        if (! $result['ok']) {
            if (in_array($result['status'], LeaveErganiSubmitter::DEFINITE_REJECTIONS, true)) {
                $ok = $this->fail($declaration, (string) ($result['message'] ?? 'Άγνωστο σφάλμα ΕΡΓΑΝΗ'));   // status first
                try {
                    $this->audit($declaration, $payload, $result, $userId);
                } catch (\Throwable $e) {
                    Log::error('ΕΡΓΑΝΗ overtime audit failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
                }
                $this->afterOutcome($declaration, $userId);

                return $ok;
            }

            return $this->unknown($declaration, $payload, $userId, 'απάντηση '.$result['status'].': '.$result['message'], $result);
        }

        // Log the protocol FIRST: if the save below fails, it's the only trace of a real declaration.
        Log::info('ΕΡΓΑΝΗ overtime declared', ['overtime' => $declaration->getKey(), 'protocol' => $result['protocol'], 'env' => $company->ergani_mode]);
        $declaration->forceFill([
            'ergani_status' => 'submitted',
            'ergani_protocol' => $result['protocol'],
            'ergani_submitted_at' => $this->parseSubmitDate($result['submitted_at']) ?? now(),
            'ergani_error' => null,
        ])->saveQuietly();
        try {
            $this->audit($declaration, $payload, $result, $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ audit row failed after a successful overtime', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
        }
        $this->afterOutcome($declaration, $userId);

        return true;
    }

    /**
     * Rows of the employee whose hours overlap [from, to) on $date in the SAME
     * ΕΡΓΑΝΗ environment (a trial row has no legal force and never blocks a real
     * one). Default statuses = anything that is or may be declared.
     *
     * @param  list<string>  $statuses
     */
    public static function overlapping(int $employeeId, string $date, string $from, string $to, string $mode, array $statuses = ['submitted', 'submitting', 'unknown']): Builder
    {
        return OvertimeDeclaration::query()->withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->whereDate('work_date', $date)
            ->where('from_time', '<', $to)->where('to_time', '>', $from)
            ->where(fn (Builder $q) => $q->whereNull('ergani_env')->orWhere('ergani_env', $mode))
            ->where(fn (Builder $q) => $q->whereIn('ergani_status', $statuses)
                ->when(in_array('submitting', $statuses, true), fn (Builder $q) => $q->orWhereNull('ergani_status')));
    }

    /** @return array<string, mixed> */
    public function payload(OvertimeDeclaration $declaration): array
    {
        $employee = $declaration->employee;
        $day = $declaration->work_date->format('d/m/Y');

        return ['WTOS' => ['WTO' => [[
            'f_aa_pararthmatos' => (string) (int) $employee->ergani_branch,
            'f_rel_protocol' => '',
            'f_rel_date' => '',
            // Our reference only — the internal note never leaves ekdosi.
            'f_comments' => 'ekdosi #'.$declaration->getKey(),
            'f_from_date' => $day,
            'f_to_date' => $day,
            'Ergazomenoi' => ['ErgazomenoiWTO' => [[
                'f_afm' => (string) $employee->afm,
                'f_eponymo' => LeaveErganiSubmitter::erganiName((string) $employee->last_name, 50),
                'f_onoma' => LeaveErganiSubmitter::erganiName((string) $employee->first_name, 30),
                'f_date' => $day,
                'ErgazomenosAnalytics' => ['ErgazomenosWTOAnalytics' => [[
                    'f_type' => self::TYPE,
                    'f_from' => $declaration->from_time,
                    'f_to' => $declaration->to_time,
                ]]],
            ]]],
        ]]]];
    }

    /** Payroll needs every overtime: FYI to the accountant; a failure also rings the admins. */
    private function afterOutcome(OvertimeDeclaration $declaration, ?int $userId): void
    {
        try {
            $declaration->refresh();
        } catch (\Throwable $e) {
            Log::warning('Overtime refresh before notifications failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);

            return;
        }
        $company = $declaration->company;
        $to = trim((string) $company?->leave_notify_email);
        if ($to !== '') {
            try {
                $this->mailers->for($company)->to($to)->send(new OvertimeAccountantMail(
                    $declaration,
                    $company->mail_from_address ?: (string) config('mail.from.address'),
                    $company->mail_from_name ?: $company->name,
                ));
            } catch (\Throwable $e) {
                Log::warning('Overtime accountant mail failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
            }
        }

        if (in_array($declaration->ergani_status, ['failed', 'unknown'], true)) {
            try {
                $recipients = LeaveWorkflow::usersWhoCan($company, 'Update:OvertimeDeclaration')
                    ->reject(fn (User $u): bool => (int) $u->getKey() === (int) $userId);
                if ($recipients->isNotEmpty()) {
                    Notification::make()
                        ->title('Υπερωρία ΔΕΝ δηλώθηκε στο ΕΡΓΑΝΗ')
                        ->body($declaration->employee?->full_name.' — '.$declaration->slotLabel().'. '.$declaration->ergani_error)
                        ->icon('heroicon-o-exclamation-triangle')
                        ->danger()
                        ->actions([
                            Action::make('view')->label('Υπερωρίες')
                                ->url(OvertimeDeclarationResource::getUrl('index', tenant: $company))
                                ->markAsRead(),
                        ])
                        ->sendToDatabase($recipients);
                }
            } catch (\Throwable $e) {
                Log::warning('Overtime admin alert failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
            }
        }
    }

    private function refuse(OvertimeDeclaration $declaration, ?int $userId, string $message, array $payload = []): bool
    {
        $result = $this->fail($declaration, $message);
        try {
            $this->audit($declaration, $payload, ['ok' => false, 'status' => 0, 'message' => 'Δεν στάλθηκε: '.$message, 'response' => ''], $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ overtime audit failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
        }
        $this->afterOutcome($declaration, $userId);

        return $result;
    }

    private function fail(OvertimeDeclaration $declaration, string $message): bool
    {
        $declaration->forceFill(['ergani_status' => 'failed', 'ergani_error' => mb_substr($message, 0, 1000)])->saveQuietly();
        Log::warning('ΕΡΓΑΝΗ overtime submission failed', ['overtime' => $declaration->getKey(), 'error' => $message]);

        return false;
    }

    /** @param array<string, mixed>|null $result */
    private function unknown(OvertimeDeclaration $declaration, array $payload, ?int $userId, string $why, ?array $result = null): bool
    {
        $message = 'Άγνωστο αποτέλεσμα ('.$why.'). Ελέγξτε στο ΕΡΓΑΝΗ αν καταχωρήθηκε ΠΡΙΝ την ξαναστείλετε.';
        $declaration->forceFill(['ergani_status' => 'unknown', 'ergani_error' => mb_substr($message, 0, 1000)])->saveQuietly();
        try {
            $this->audit($declaration, $payload, ($result ?? ['status' => 0, 'response' => '']) + ['ok' => false, 'message' => $message], $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ overtime audit failed', ['overtime' => $declaration->getKey(), 'error' => $e->getMessage()]);
        }
        Log::warning('ΕΡΓΑΝΗ overtime outcome unknown', ['overtime' => $declaration->getKey(), 'why' => $why]);
        $this->afterOutcome($declaration, $userId);

        return false;
    }

    /** @param array<string, mixed> $result */
    private function audit(OvertimeDeclaration $declaration, array $request, array $result, ?int $userId): void
    {
        ErganiSubmission::create([
            'company_id' => $declaration->company_id,
            'overtime_declaration_id' => $declaration->getKey(),
            'user_id' => $userId,
            'document' => self::DOCUMENT,
            'action' => 'submit',
            'environment' => (string) ($declaration->ergani_env ?: $declaration->company?->ergani_mode),
            'ok' => (bool) $result['ok'],
            'http_status' => ($result['status'] ?? 0) ?: null,
            'protocol' => $result['protocol'] ?? null,
            'ergani_id' => $result['id'] ?? null,
            'submit_date' => $result['submitted_at'] ?? null,
            'message' => $result['message'] ?? null,
            'request' => $request,
            'response' => $result['response'] ?? null,
        ]);
    }

    private function parseSubmitDate(?string $value): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }
        try {
            return CarbonImmutable::createFromFormat('d/m/Y H:i', $value, 'Europe/Athens');
        } catch (\Throwable) {
            return null;
        }
    }
}
