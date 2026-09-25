<?php

namespace App\Services\Ergani;

use App\Filament\Resources\WorkCardEvents\WorkCardEventResource;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ErganiSubmission;
use App\Models\User;
use App\Models\WorkCardEvent;
use App\Models\WorkCardKioskDevice;
use App\Services\Hr\LeaveWorkflow;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Ψηφιακή Κάρτα Εργασίας (ΕΡΓΑΝΗ WRKCardSE) — recording a «χτύπημα» and
 * declaring it, verified on the ΕΡΓΑΝΗ trial (docs/ergani/README.md §7).
 *
 * A card can NOT be withdrawn through the API, so the safeguards matter even
 * more than for leaves: one declaration per movement (atomic claim), an unclear
 * outcome is «unknown» (never auto-retried), a double punch within
 * DOUBLE_PUNCH_SECONDS is refused, and ΕΡΓΑΝΗ's 15' deadline is enforced — a
 * later submission must carry an f_aitiologia code.
 *
 * Proof of presence: the office tablet shows a QR that changes every
 * KIOSK_WINDOW seconds (HMAC of company + time window); scanning it opens the
 * card page with that token → the punch is recorded as source «kiosk».
 */
class WorkCardService
{
    public const DOCUMENT = 'WRKCardSE';

    public const DOUBLE_PUNCH_SECONDS = 60;

    /** ΕΡΓΑΝΗ: a movement must be declared within 15'. */
    public const DEADLINE_MINUTES = 15;

    public const KIOSK_WINDOW = 30;

    /**
     * An «in» older than this no longer counts as open — a forgotten «out» must
     * not turn next morning's arrival into a ~24h shift declared irrevocably.
     */
    public const OPEN_SHIFT_HOURS = 16;

    /** A scanned QR stays valid for this many windows (scan → tap «Είσοδος»). */
    private const KIOSK_GRACE_WINDOWS = 4;

    public static function enabledFor(?Company $company): bool
    {
        return $company instanceof Company
            && $company->hasErgani()
            && (bool) $company->ergani_submit_cards
            && filled($company->ergani_username)
            && filled($company->ergani_password);
    }

    // ── recording ───────────────────────────────────────────────────────────

    /** The movement the employee would make now (in after out/nothing, else out). */
    public function nextType(Employee $employee): string
    {
        $last = $this->lastEvent($employee);

        return $last?->type === WorkCardEvent::IN && $last->occurred_at->gt(now()->subHours(self::OPEN_SHIFT_HOURS))
            ? WorkCardEvent::OUT
            : WorkCardEvent::IN;
    }

    public function lastEvent(Employee $employee): ?WorkCardEvent
    {
        return WorkCardEvent::query()->withoutGlobalScopes()
            ->where('employee_id', $employee->getKey())
            ->latest('occurred_at')->latest('id')
            ->first();
    }

    /**
     * Record a punch and (if the tenant opted in and the employee has a card in
     * ΕΡΓΑΝΗ) declare it right away. Serialized per employee so two taps can't
     * both pass the double-punch check.
     *
     * @param  'self'|'kiosk'|'admin'  $source
     *
     * @throws WorkCardRefused with a Greek message when refused by rule (nothing recorded)
     */
    public function punch(Employee $employee, string $source, ?int $userId = null, ?string $type = null, ?CarbonImmutable $at = null, ?string $note = null, ?string $lateReason = null): WorkCardEvent
    {
        $company = $employee->company;
        if (! $company instanceof Company || ! $company->hasErgani()) {
            throw new WorkCardRefused('Η ψηφιακή κάρτα δεν είναι ενεργή για την εταιρεία.');
        }
        if (! $employee->is_active) {
            throw new WorkCardRefused('Ο εργαζόμενος είναι ανενεργός.');
        }
        if ($source === 'self' && $company->ergani_card_requires_kiosk) {
            throw new WorkCardRefused('Η κάρτα χτυπιέται μόνο σκανάροντας το QR του γραφείου.');
        }
        // Normalise to the app (Athens) clock: occurred_at is stored AND declared as such.
        $at = ($at ?? CarbonImmutable::now())->setTimezone(config('app.timezone'));
        if ($at->gt(now())) {
            throw new WorkCardRefused('Η ώρα της κίνησης δεν μπορεί να είναι στο μέλλον.');
        }
        if ($type !== null && ! in_array($type, [WorkCardEvent::IN, WorkCardEvent::OUT], true)) {
            throw new WorkCardRefused('Άγνωστος τύπος κίνησης.');
        }

        $event = DB::transaction(function () use ($employee, $source, $userId, $type, $at, $note): WorkCardEvent {
            Employee::query()->withoutGlobalScopes()->whereKey($employee->getKey())->lockForUpdate()->first();

            // Judge the punch against its NEIGHBOURS in time, not just the latest
            // row: a card can't be withdrawn, so an out-of-order or duplicate
            // movement must be refused before it is ever declared.
            $events = WorkCardEvent::query()->withoutGlobalScopes()->where('employee_id', $employee->getKey());
            $near = (clone $events)
                ->whereBetween('occurred_at', [$at->subSeconds(self::DOUBLE_PUNCH_SECONDS - 1), $at->addSeconds(self::DOUBLE_PUNCH_SECONDS - 1)])
                ->first();
            if ($near !== null) {
                throw new WorkCardRefused('Υπάρχει ήδη κίνηση ('.$near->typeLabel().' '.$near->occurred_at->format('d/m H:i').') — αγνοήθηκε το διπλό χτύπημα.');
            }
            $later = (clone $events)->where('occurred_at', '>', $at)->orderBy('occurred_at')->first();
            if ($later !== null) {
                throw new WorkCardRefused('Υπάρχει μεταγενέστερη κίνηση ('.$later->typeLabel().' '.$later->occurred_at->format('d/m H:i').') — ενδιάμεση κίνηση δεν προστίθεται (η κάρτα δεν ανακαλείται στο ΕΡΓΑΝΗ).');
            }
            $prev = (clone $events)->where('occurred_at', '<=', $at)->orderByDesc('occurred_at')->orderByDesc('id')->first();
            $open = $prev?->type === WorkCardEvent::IN && $prev->occurred_at->gt($at->subHours(self::OPEN_SHIFT_HOURS));

            $type ??= $open ? WorkCardEvent::OUT : WorkCardEvent::IN;
            if ($type === WorkCardEvent::IN && $open) {
                throw new WorkCardRefused('Υπάρχει ήδη ανοιχτή είσοδος ('.$prev->occurred_at->format('d/m H:i').') — πρώτα έξοδος.');
            }
            if ($type === WorkCardEvent::OUT && ! $open) {
                throw new WorkCardRefused('Έξοδος χωρίς προηγούμενη είσοδο (εντός '.self::OPEN_SHIFT_HOURS.' ωρών) — καταχωρίστε πρώτα την είσοδο.');
            }

            // An «out» belongs to the day of its open «in» (night shift past midnight).
            $reference = $type === WorkCardEvent::OUT
                ? CarbonImmutable::parse($prev->reference_date)
                : $at->setTimezone('Europe/Athens');

            return WorkCardEvent::create([
                'company_id' => $employee->company_id,
                'employee_id' => $employee->getKey(),
                'type' => $type,
                'occurred_at' => $at,
                'reference_date' => $reference->toDateString(),
                'source' => $source,
                'created_by_user_id' => $userId,
                'note' => $note !== null ? mb_substr($note, 0, 200) : null,
            ]);
        });

        $this->submit($event, $userId, $lateReason);
        $event = $event->fresh();

        // ΕΡΓΑΝΗ's 15' clock is running: the ADMINS must know now, not only the
        // employee (who may dismiss the toast).
        if (in_array($event->ergani_status, ['failed', 'unknown'], true)) {
            $this->alertAdmins($event, $userId);
        }

        return $event;
    }

    /** A tablet hit the wrong-PIN cap — someone may be guessing PINs there. */
    private function alertDevicePaused(WorkCardKioskDevice $device): void
    {
        try {
            $recipients = LeaveWorkflow::usersWhoCan($device->company, 'View:WorkCardKiosk');
            if ($recipients->isNotEmpty()) {
                Notification::make()
                    ->title('Tablet κάρτας σε παύση: «'.$device->name.'»')
                    ->body('Πολλά λάθος PIN σε λίγα λεπτά — η εισαγωγή PIN σε αυτό το tablet σταμάτησε για '.intdiv(self::PIN_DEVICE_WINDOW_SECONDS, 60).'\'. Ελέγξτε ποιος το χρησιμοποιεί.')
                    ->icon('heroicon-o-shield-exclamation')
                    ->warning()
                    ->sendToDatabase($recipients);
            }
        } catch (\Throwable $e) {
            Log::warning('Kiosk pause alert failed', ['device' => $device->getKey(), 'error' => $e->getMessage()]);
        }
    }

    private function alertAdmins(WorkCardEvent $event, ?int $exceptUserId): void
    {
        try {
            $company = $event->company;
            $recipients = LeaveWorkflow::usersWhoCan($company, 'Update:WorkCardEvent')
                ->reject(fn (User $u): bool => (int) $u->getKey() === (int) $exceptUserId);
            if ($recipients->isEmpty()) {
                return;
            }
            Notification::make()
                ->title('Κάρτα εργασίας ΔΕΝ δηλώθηκε στο ΕΡΓΑΝΗ')
                ->body(sprintf('%s — %s %s. %s Προθεσμία 15\' από την κίνηση.',
                    $event->employee?->full_name, $event->typeLabel(), $event->occurred_at->format('H:i'), $event->ergani_error))
                ->icon('heroicon-o-exclamation-triangle')
                ->danger()
                ->actions([
                    Action::make('view')->label('Κάρτες εργασίας')
                        ->url(WorkCardEventResource::getUrl('index', tenant: $company))
                        ->markAsRead(),
                ])
                ->sendToDatabase($recipients);
        } catch (\Throwable $e) {
            Log::warning('Work card admin alert failed', ['event' => $event->getKey(), 'error' => $e->getMessage()]);
        }
    }

    // ── tablet «ρολόι» (name + PIN) ────────────────────────────────────────

    public const PIN_MAX_FAILURES = 5;

    public const PIN_LOCK_MINUTES = 15;

    /** Wrong PINs per activated tablet within PIN_DEVICE_WINDOW_SECONDS before that tablet pauses PIN entry. */
    public const PIN_DEVICE_FAILURES = 20;

    public const PIN_DEVICE_WINDOW_SECONDS = 900;

    /**
     * Punch from the activated office tablet: the employee picks their name and
     * types their PIN — no phone, no login. The device itself is the proof of
     * presence (source «kiosk»). Wrong PINs lock the employee for PIN_LOCK_MINUTES
     * after every PIN_MAX_FAILURES (doubling each time), and THAT tablet pauses PIN
     * entry after PIN_DEVICE_FAILURES wrong ones within PIN_DEVICE_WINDOW_SECONDS
     * (the admins are belled).
     *
     * @throws WorkCardRefused
     */
    public function punchWithPin(WorkCardKioskDevice $device, int $employeeId, string $pin, ?string $seenType = null): WorkCardEvent
    {
        $company = $device->company;

        // Per-DEVICE cap on wrong PINs: stops spraying one common PIN across all
        // names (the per-employee lock never trips for that) — and pauses only
        // THIS tablet, never the company's others.
        $deviceKey = 'card-kiosk-pin-failures|'.$device->getKey();
        if (RateLimiter::tooManyAttempts($deviceKey, self::PIN_DEVICE_FAILURES)) {
            $until = now()->addSeconds(RateLimiter::availableIn($deviceKey))->format('H:i');
            throw new WorkCardRefused('Πολλές λάθος προσπάθειες σε αυτό το tablet — ξανά μετά τις '.$until.' (ή κάρτα από το κινητό).');
        }

        // Verify under a row lock (concurrent wrong tries can't skip the counter);
        // the punch itself — which calls ΕΡΓΑΝΗ — runs AFTER this commits.
        $outcome = DB::transaction(function () use ($company, $employeeId, $pin): array {
            $employee = Employee::query()->withoutGlobalScopes()
                ->where('company_id', $company->getKey())
                ->where('is_active', true)
                ->lockForUpdate()
                ->find($employeeId);
            if (! $employee instanceof Employee || blank($employee->card_pin_hash)) {
                return [null, 'Δεν έχει οριστεί PIN — ζητήστε από τον διαχειριστή.', false];
            }
            if ($employee->card_pin_locked_until?->isFuture()) {
                return [null, 'Πολλές λάθος προσπάθειες — δοκιμάστε ξανά μετά τις '.$employee->card_pin_locked_until->format('H:i').'.', false];
            }
            if (! preg_match('/^\d{4,6}$/', $pin) || ! Hash::check($pin, $employee->card_pin_hash)) {
                // Never reset on lockout: every further batch of wrong tries locks
                // for twice as long (15', 30', 1h … capped at a day).
                $failures = $employee->card_pin_failures + 1;
                $lock = null;
                if ($failures % self::PIN_MAX_FAILURES === 0) {
                    $minutes = min(24 * 60, self::PIN_LOCK_MINUTES * 2 ** (intdiv($failures, self::PIN_MAX_FAILURES) - 1));
                    $lock = now()->addMinutes($minutes);
                }
                $employee->forceFill(['card_pin_failures' => min(255, $failures), 'card_pin_locked_until' => $lock])->saveQuietly();

                return [null, $lock ? 'Λάθος PIN — κλείδωμα έως '.$lock->format('H:i').'.' : 'Λάθος PIN.', true];
            }
            if ($employee->card_pin_failures > 0 || $employee->card_pin_locked_until !== null) {
                $employee->forceFill(['card_pin_failures' => 0, 'card_pin_locked_until' => null])->saveQuietly();
            }

            return [$employee, null, false];
        });

        [$employee, $refusal, $wrongPin] = $outcome;
        if ($wrongPin) {
            // hit() returns the new count atomically — exactly one request sees 20.
            if (RateLimiter::hit($deviceKey, self::PIN_DEVICE_WINDOW_SECONDS) === self::PIN_DEVICE_FAILURES) {
                $this->alertDevicePaused($device);
            }
        }
        if (! $employee instanceof Employee) {
            throw new WorkCardRefused((string) $refusal);
        }

        // The movement the tablet SHOWED next to the name (bound like the modal).
        return $this->punch($employee, 'kiosk', null, in_array($seenType, [WorkCardEvent::IN, WorkCardEvent::OUT], true) ? $seenType : null);
    }

    /**
     * Who is in right now — the tablet's presence board.
     *
     * @return list<array{id: int, name: string, in: bool, since: ?string, next: string, has_pin: bool}>
     */
    public function presence(Company $company): array
    {
        $employees = Employee::query()->withoutGlobalScopes()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        return $employees->map(function (Employee $e): array {
            $last = $this->lastEvent($e);
            $in = $last?->type === WorkCardEvent::IN && $last->occurred_at->gt(now()->subHours(self::OPEN_SHIFT_HOURS));

            return [
                'id' => (int) $e->getKey(),
                'name' => $e->full_name,
                'in' => $in,
                'since' => $in ? $last->occurred_at->format('H:i') : null,
                'next' => $in ? WorkCardEvent::OUT : WorkCardEvent::IN,
                'has_pin' => filled($e->card_pin_hash),
            ];
        })->all();
    }

    // ── kiosk (proof of presence) ──────────────────────────────────────────

    public function kioskToken(Company $company, ?int $window = null): string
    {
        $window ??= intdiv(time(), self::KIOSK_WINDOW);

        return $window.'.'.substr(hash_hmac('sha256', 'work-card-kiosk|'.$company->getKey().'|'.$window, (string) config('app.key')), 0, 32);
    }

    public function kioskTokenValid(Company $company, ?string $token): bool
    {
        if (! is_string($token) || ! preg_match('/^(\d+)\.([a-f0-9]{32})$/', $token, $m)) {
            return false;
        }
        $window = (int) $m[1];
        $now = intdiv(time(), self::KIOSK_WINDOW);
        if ($window > $now || $window < $now - self::KIOSK_GRACE_WINDOWS) {
            return false;
        }

        return hash_equals($this->kioskToken($company, $window), $token);
    }

    // ── declaring ───────────────────────────────────────────────────────────

    /**
     * Declare one movement (WRKCardSE). Outcomes as for leaves: submitted |
     * failed (certainly not sent) | unknown (may have landed → only with
     * $confirmedUnknown). Late (> 15') needs an f_aitiologia code.
     */
    public function submit(WorkCardEvent $event, ?int $userId = null, ?string $lateReason = null, bool $confirmedUnknown = false): bool
    {
        $event->loadMissing(['employee', 'company']);
        $company = $event->company;
        if (! self::enabledFor($company) || ! $event->employee?->has_work_card) {
            return false;
        }

        $claimed = WorkCardEvent::query()->withoutGlobalScopes()
            ->whereKey($event->getKey())
            ->where(fn ($q) => $q
                ->when(! $confirmedUnknown, fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', 'failed'))
                ->when($confirmedUnknown, fn ($q) => $q->where('ergani_status', 'unknown')
                    ->orWhere(fn ($q) => $q->where('ergani_status', 'submitting')
                        ->where('updated_at', '<', now()->subMinutes(LeaveErganiSubmitter::CLAIM_STALE_MINUTES)))))
            ->update(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode, 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        $event->forceFill(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode])->syncOriginal();

        $employee = $event->employee;
        if (blank($employee->afm) || blank($company->afm)) {
            return $this->refuse($event, $userId, 'Λείπει ο ΑΦΜ του εργαζομένου ή της εταιρείας.');
        }
        if ($event->isLate() && ! array_key_exists((string) $lateReason, WorkCardEvent::LATE_REASONS)) {
            return $this->refuse($event, $userId, 'Εκπρόθεσμη κίνηση (πάνω από '.self::DEADLINE_MINUTES.'\') — χρειάζεται αιτιολογία εκπρόθεσμης υποβολής.');
        }

        $payload = $this->payload($event, $event->isLate() ? $lateReason : null);
        try {
            $client = new ErganiClient($company);
            $client->ensureToken();
        } catch (\Throwable $e) {
            return $this->refuse($event, $userId, $e->getMessage(), $payload);
        }

        try {
            $result = $client->submit(self::DOCUMENT, $payload);
        } catch (\Throwable $e) {
            return $this->unknown($event, $payload, $userId, 'το ΕΡΓΑΝΗ δεν απάντησε: '.$e->getMessage());
        }

        if (! $result['ok']) {
            if (in_array($result['status'], LeaveErganiSubmitter::DEFINITE_REJECTIONS, true)) {
                $this->audit($event, $payload, $result, $userId);

                return $this->fail($event, (string) ($result['message'] ?? 'Άγνωστο σφάλμα ΕΡΓΑΝΗ'));
            }

            return $this->unknown($event, $payload, $userId, 'απάντηση '.$result['status'].': '.$result['message'], $result);
        }

        $event->forceFill([
            'ergani_status' => 'submitted',
            'ergani_protocol' => $result['protocol'],
            'ergani_submitted_at' => $this->parseSubmitDate($result['submitted_at']) ?? now(),
            'late_reason' => $event->isLate() ? $lateReason : null,
            'ergani_error' => null,
        ])->saveQuietly();
        try {
            $this->audit($event, $payload, $result, $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ audit row failed after a successful card', ['event' => $event->getKey(), 'error' => $e->getMessage()]);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public function payload(WorkCardEvent $event, ?string $lateReason = null): array
    {
        $employee = $event->employee;
        $company = $event->company;
        $at = CarbonImmutable::parse($event->occurred_at)->setTimezone('Europe/Athens');

        return ['Cards' => ['Card' => [[
            'f_afm_ergodoti' => (string) $company->afm,
            'f_aa' => (string) (int) $employee->ergani_branch,
            'f_comments' => 'ekdosi #'.$event->getKey(),
            'Details' => ['CardDetails' => [[
                'f_afm' => (string) $employee->afm,
                'f_eponymo' => LeaveErganiSubmitter::erganiName((string) $employee->last_name, 50),
                'f_onoma' => LeaveErganiSubmitter::erganiName((string) $employee->first_name, 30),
                'f_type' => $event->type === WorkCardEvent::IN ? '0' : '1',
                'f_reference_date' => CarbonImmutable::parse($event->reference_date)->toDateString(),
                'f_date' => $at->format('Y-m-d\TH:i:s.vP'),
                'f_aitiologia' => $lateReason,
            ]]],
        ]]]];
    }

    private function refuse(WorkCardEvent $event, ?int $userId, string $message, array $payload = []): bool
    {
        $result = $this->fail($event, $message);   // status first — never leave it «submitting»
        try {
            $this->audit($event, $payload, ['ok' => false, 'status' => 0, 'message' => 'Δεν στάλθηκε: '.$message, 'response' => ''], $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ card audit failed', ['event' => $event->getKey(), 'error' => $e->getMessage()]);
        }

        return $result;
    }

    private function fail(WorkCardEvent $event, string $message): bool
    {
        $event->forceFill(['ergani_status' => 'failed', 'ergani_error' => mb_substr($message, 0, 1000)])->saveQuietly();
        Log::warning('ΕΡΓΑΝΗ card submission failed', ['event' => $event->getKey(), 'error' => $message]);

        return false;
    }

    /** @param array<string, mixed>|null $result */
    private function unknown(WorkCardEvent $event, array $payload, ?int $userId, string $why, ?array $result = null): bool
    {
        $message = 'Άγνωστο αποτέλεσμα ('.$why.'). Ελέγξτε στο ΕΡΓΑΝΗ αν καταχωρήθηκε ΠΡΙΝ την ξαναστείλετε.';
        $event->forceFill(['ergani_status' => 'unknown', 'ergani_error' => mb_substr($message, 0, 1000)])->saveQuietly();
        $this->audit($event, $payload, ($result ?? ['status' => 0, 'response' => '']) + ['ok' => false, 'message' => $message], $userId);
        Log::warning('ΕΡΓΑΝΗ card outcome unknown', ['event' => $event->getKey(), 'why' => $why]);

        return false;
    }

    /** @param array<string, mixed> $result */
    private function audit(WorkCardEvent $event, array $request, array $result, ?int $userId): void
    {
        ErganiSubmission::create([
            'company_id' => $event->company_id,
            'work_card_event_id' => $event->getKey(),
            'user_id' => $userId,
            'document' => self::DOCUMENT,
            'action' => 'submit',
            'environment' => (string) ($event->ergani_env ?: $event->company?->ergani_mode),
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
