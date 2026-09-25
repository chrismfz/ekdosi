<?php

namespace App\Services\Ergani;

use App\Enums\LeaveStatus;
use App\Enums\LeaveType;
use App\Models\Company;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Support\Hr\WorkingDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Declares an approved leave to ΕΡΓΑΝΗ ΙΙ («Οργάνωση Χρόνου Εργασίας – Άδειες»,
 * WTOLeave) and withdraws it on revocation (CancelSubmittedDocument — the guide
 * allows cancelling ONLY the Άδειες documents). Every call is written to
 * ergani_submissions (append-only audit); leave_requests.ergani_* is the cache.
 *
 * Payload shape (docs/ergani/schemas/WTOLeave.json, verified on trial): one WTO
 * header per branch with the date range, and ONE Ergazomenoi entry PER leave
 * day (f_date) carrying the ΕΡΓΑΝΗ leave code; κανονική also carries the year
 * and the yearly entitlement (f_req_days, 3 digits). Only working days are
 * declared — weekends/holidays inside the range aren't leave days.
 *
 * Never throws: a failure is recorded (status + message) and surfaced by the
 * caller; the business decision (approve/cancel) always stands.
 */
class LeaveErganiSubmitter
{
    public const DOCUMENT = 'WTOLeave';

    /** CancelSubmittedDocument's TypeOfDocument for WTOLeave (see docs/ergani/README.md). */
    public const CANCEL_TYPE = 'WTOLeave';

    /** A «submitting» claim older than this is a crashed attempt (PHP killed mid-call). */
    public const CLAIM_STALE_MINUTES = 10;

    /**
     * 4xx replies that certainly mean «not registered» (ΕΡΓΑΝΗ's validation /
     * auth / throttling). Anything else (408, a proxy's 4xx page, 5xx, a 2xx
     * without a protocol) is ambiguous → «unknown».
     */
    public const DEFINITE_REJECTIONS = [400, 401, 403, 404, 422, 429];

    /** Is automatic submission switched on (and configured) for this tenant? */
    public static function enabledFor(?Company $company): bool
    {
        return $company instanceof Company
            && $company->hasErgani()
            && (bool) $company->ergani_submit_leaves
            && filled($company->ergani_username)
            && filled($company->ergani_password);
    }

    /**
     * Declare an approved leave. Outcomes (leave_requests.ergani_status):
     *   submitted — ΕΡΓΑΝΗ returned a protocol
     *   failed    — certainly NOT declared (precondition, login failure, a 4xx
     *               rejection carrying ΕΡΓΑΝΗ's message) → safe to retry
     *   unknown   — the POST went out but the outcome is unclear (timeout, 5xx,
     *               unreadable 2xx) → ΕΡΓΑΝΗ may have registered it; retrying needs
     *               $confirmed = 'unknown' (the operator checked it did NOT land) or the
     *               protocol entered by hand (recordProtocol)
     *
     * @param  ?string  $confirmed  WHICH state the operator confirmed «it's NOT in
     *                              ΕΡΓΑΝΗ» for — the confirmation covers only that one:
     *                              'accountant' = the approval email asked the accountant
     *                              to declare it by hand (failed / opted in later);
     *                              'unknown' = an unknown outcome or a crashed claim.
     */
    public function submit(LeaveRequest $leave, ?int $userId = null, ?string $confirmed = null): bool
    {
        $leave->loadMissing(['employee', 'company']);
        $company = $leave->company;
        if (! self::enabledFor($company) || ! $leave->isApproved()) {
            return false;
        }

        // Atomic CLAIM: exactly one caller may declare a given leave (double-click,
        // two tabs, approve_now + retry). An unknown outcome, or a claim left by a
        // crashed attempt (stale «submitting»), may be taken over ONLY with the
        // operator's confirmation — the crashed POST may have landed too.
        $claimed = LeaveRequest::query()->withoutGlobalScopes()
            ->whereKey($leave->getKey())
            ->where('status', LeaveStatus::Approved->value)
            ->where(fn ($q) => $q
                ->when($confirmed === null, fn ($q) => $q
                    ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', 'failed'))
                    ->whereNull('accountant_notified_at'))
                ->when($confirmed === 'accountant', fn ($q) => $q
                    ->where(fn ($q) => $q->whereNull('ergani_status')->orWhere('ergani_status', 'failed')))
                ->when($confirmed === 'unknown', fn ($q) => $q
                    ->where(fn ($q) => $q->where('ergani_status', 'unknown')
                        ->orWhere(fn ($q) => $q->where('ergani_status', 'submitting')
                            ->where('updated_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES)))))
                ->when(! in_array($confirmed, [null, 'accountant', 'unknown'], true), fn ($q) => $q->whereRaw('1 = 0')))
            ->update(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode, 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }
        $leave->forceFill(['ergani_status' => 'submitting', 'ergani_env' => $company->ergani_mode])->syncOriginal();

        $employee = $leave->employee;
        if (blank($employee?->afm)) {
            return $this->refuse($leave, $userId, 'Λείπει ο ΑΦΜ του εργαζομένου (Προσωπικό → Εργαζόμενοι).');
        }

        $payload = $this->payload($leave);
        if ($payload === null) {
            return $this->refuse($leave, $userId, 'Το διάστημα δεν περιέχει εργάσιμες ημέρες — δεν υπάρχει τι να δηλωθεί.');
        }

        // The declaration lists DATES; the approved day count must match them, or
        // ΕΡΓΑΝΗ would record days the approver deliberately excluded.
        $declared = count($payload['WTOS']['WTO'][0]['Ergazomenoi']['ErgazomenoiWTO']);
        if ($declared !== (int) $leave->days) {
            return $this->refuse($leave, $userId, sprintf(
                'Οι εγκεκριμένες ημέρες (%d) διαφέρουν από τις εργάσιμες του διαστήματος (%d). Αν κάποια μέρα ήταν κλειστό το γραφείο, '
                .'προσθέστε τη στις «Τοπικές αργίες»· αλλιώς διορθώστε το διάστημα. Δεν στάλθηκε τίποτα.',
                $leave->days, $declared,
            ));
        }

        $client = new ErganiClient($company);
        try {
            // Log in FIRST: a failure here certainly declared nothing.
            $client->ensureToken();
        } catch (\Throwable $e) {
            $this->audit($leave, 'submit', $payload, ['ok' => false, 'status' => 0, 'message' => $e->getMessage(), 'response' => ''], $userId);

            return $this->fail($leave, $e->getMessage());
        }

        try {
            $result = $client->submit(self::DOCUMENT, $payload);
        } catch (\Throwable $e) {
            // The POST went out (or may have) but no usable answer came back.
            return $this->unknown($leave, $payload, $userId, 'το ΕΡΓΑΝΗ δεν απάντησε: '.$e->getMessage());
        }

        if (! $result['ok']) {
            // Only a 4xx is a definite «not registered» (ΕΡΓΑΝΗ's validation
            // messages come as 400); 5xx / gateway pages / a 2xx without a
            // protocol are ambiguous.
            if (in_array($result['status'], self::DEFINITE_REJECTIONS, true)) {
                $this->audit($leave, 'submit', $payload, $result, $userId);

                return $this->fail($leave, (string) ($result['message'] ?? 'Άγνωστο σφάλμα ΕΡΓΑΝΗ'));
            }

            return $this->unknown($leave, $payload, $userId, 'απάντηση '.$result['status'].': '.$result['message'], $result);
        }

        // Persist the protocol BEFORE anything else can fail — it's the only
        // handle to withdraw the declaration later.
        $leave->forceFill([
            'ergani_status' => 'submitted',
            'ergani_env' => $company->ergani_mode,
            'ergani_protocol' => $result['protocol'],
            'ergani_submitted_at' => $this->parseSubmitDate($result['submitted_at']) ?? now(),
            'ergani_error' => null,
        ])->saveQuietly();

        // Nothing after the protocol is saved may lose the withdrawal of a leave
        // revoked meanwhile — an audit hiccup is logged, never thrown.
        try {
            $this->audit($leave, 'submit', $payload, $result, $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ audit row failed after a successful declaration', ['leave' => $leave->getKey(), 'protocol' => $result['protocol'], 'error' => $e->getMessage()]);
        }
        try {
            $this->withdrawIfRevokedMeanwhile($leave, $userId);
        } catch (\Throwable $e) {
            Log::error('ΕΡΓΑΝΗ withdrawal after a racing revocation failed', ['leave' => $leave->getKey(), 'error' => $e->getMessage()]);
        }

        return true;
    }

    /**
     * The operator found the declaration in ΕΡΓΑΝΗ (after an «unknown» outcome) —
     * record its protocol so it can be managed (and withdrawn if the leave was
     * revoked meanwhile).
     */
    public function recordProtocol(LeaveRequest $leave, string $protocol, CarbonImmutable $submittedOn, ?int $userId = null): bool
    {
        if (blank(trim($protocol))) {
            return false;
        }
        $leave->loadMissing('company');
        $env = $leave->ergani_env ?: $leave->company?->ergani_mode;

        // Only to resolve an unclear attempt — never to overwrite a known protocol,
        // and not while another attempt is live. Checked atomically in the DB.
        $updated = LeaveRequest::query()->withoutGlobalScopes()
            ->whereKey($leave->getKey())
            ->where(fn ($q) => $q->where('ergani_status', 'unknown')
                ->orWhere(fn ($q) => $q->where('ergani_status', 'submitting')
                    ->where('updated_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES))))
            ->update([
                'ergani_status' => 'submitted',
                'ergani_env' => $env,
                'ergani_protocol' => trim($protocol),
                'ergani_submitted_at' => $submittedOn,
                'ergani_error' => null,
                'updated_at' => now(),
            ]);
        if ($updated === 0) {
            return false;
        }
        $leave->setRawAttributes(LeaveRequest::query()->withoutGlobalScopes()->whereKey($leave->getKey())->firstOrFail()->getAttributes(), true);
        $this->audit($leave, 'manual', ['protocol' => trim($protocol), 'submitted_on' => $submittedOn->format('d/m/Y')],
            ['ok' => true, 'status' => 0, 'protocol' => trim($protocol), 'message' => 'Καταχωρίστηκε χειροκίνητα', 'response' => ''], $userId);

        $this->withdrawIfRevokedMeanwhile($leave, $userId);

        return true;
    }

    /** Withdraw a previously submitted declaration (after the leave was revoked). */
    public function cancel(LeaveRequest $leave, ?int $userId = null): bool
    {
        $leave->loadMissing('company');
        $company = $leave->company;
        if (! $company instanceof Company || blank($leave->ergani_protocol)) {
            return false;
        }
        // Retrying a withdrawal whose request already went out (crashed mid-call).
        $resumingCrashedWithdrawal = $leave->ergani_status === 'cancelling';

        // Claim (submitted | cancel_failed → cancelling): a double-click must not
        // send a second withdrawal (the 2nd gets «No objects found» and would
        // wrongly flip a done withdrawal to cancel_failed).
        // A «cancelling» left by a crashed attempt may be retried — re-sending a
        // withdrawal is harmless (at worst «No objects found»).
        $claimed = LeaveRequest::query()->withoutGlobalScopes()
            ->whereKey($leave->getKey())
            ->where(fn ($q) => $q
                ->whereIn('ergani_status', ['submitted', 'cancel_failed'])
                ->orWhere(fn ($q) => $q->where('ergani_status', 'cancelling')
                    ->where('updated_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES))))
            ->update(['ergani_status' => 'cancelling', 'updated_at' => now()]);
        if ($claimed === 0) {
            return false;
        }

        // Always cancel in the environment it was SUBMITTED to, even if the
        // tenant switched trial↔production since.
        $client = new ErganiClient($company->replicate()->forceFill(['ergani_mode' => $leave->ergani_env ?: $company->ergani_mode]));
        $date = $leave->erganiSubmitDateYmd() ?? now('Europe/Athens')->format('Ymd');

        try {
            $result = $client->cancel(self::CANCEL_TYPE, (string) $leave->ergani_protocol, $date);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'status' => 0, 'message' => $e->getMessage(), 'response' => ''];
        }

        // The crashed attempt's withdrawal landed: ΕΡΓΑΝΗ no longer has it.
        if (! $result['ok'] && $resumingCrashedWithdrawal && str_contains((string) $result['message'], 'No objects found')) {
            $result['ok'] = true;
            $result['message'] = 'Ήδη ανακλημένη (No objects found μετά από διακοπείσα ανάκληση)';
        }

        $leave->forceFill([
            'ergani_status' => $result['ok'] ? 'cancelled' : 'cancel_failed',
            'ergani_error' => $result['ok'] ? null : (string) ($result['message'] ?? 'Άγνωστο σφάλμα ΕΡΓΑΝΗ'),
        ])->saveQuietly();
        $this->audit($leave, 'cancel', ['TypeOfDocument' => self::CANCEL_TYPE, 'Protocol' => $leave->ergani_protocol, 'SubmittedDate' => $date], $result + ['protocol' => $leave->ergani_protocol], $userId);

        return $result['ok'];
    }

    /**
     * A revocation that raced the declaration saw «nothing declared» and skipped
     * the withdrawal — withdraw now, or a cancelled leave stays in ΕΡΓΑΝΗ.
     */
    private function withdrawIfRevokedMeanwhile(LeaveRequest $leave, ?int $userId): void
    {
        $current = LeaveRequest::query()->withoutGlobalScopes()->whereKey($leave->getKey())->toBase()->value('status');
        if ($current === LeaveStatus::Cancelled->value) {
            $leave->forceFill(['status' => LeaveStatus::Cancelled])->syncOriginalAttribute('status');
            $this->cancel($leave, $userId);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $result
     */
    private function unknown(LeaveRequest $leave, array $payload, ?int $userId, string $why, ?array $result = null): bool
    {
        $message = 'Άγνωστο αποτέλεσμα ('.$why.'). Ελέγξτε στο ΕΡΓΑΝΗ αν δηλώθηκε ΠΡΙΝ το ξαναστείλετε — αν δηλώθηκε, καταχωρίστε το πρωτόκολλο.';
        $leave->forceFill(['ergani_status' => 'unknown', 'ergani_error' => mb_substr($message, 0, 1000)])->saveQuietly();
        $this->audit($leave, 'submit', $payload, ($result ?? ['status' => 0, 'response' => '']) + ['ok' => false, 'message' => $message], $userId);
        Log::warning('ΕΡΓΑΝΗ leave submission outcome unknown', ['leave' => $leave->getKey(), 'why' => $why]);

        return false;
    }

    /**
     * @return array<string, mixed>|null null when the range has no working day
     */
    public function payload(LeaveRequest $leave): ?array
    {
        $employee = $leave->employee;
        $calendar = WorkingDays::for((int) $leave->company_id);
        $from = CarbonImmutable::parse($leave->starts_on);
        $to = CarbonImmutable::parse($leave->ends_on);
        $annual = $leave->type === LeaveType::Annual;

        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            if (! $calendar->isWorkingDay($d)) {
                continue;
            }
            $days[] = [
                'f_afm' => (string) $employee->afm,
                'f_eponymo' => self::erganiName((string) $employee->last_name, 50),
                'f_onoma' => self::erganiName((string) $employee->first_name, 30),
                'f_date' => $d->format('d/m/Y'),
                'ErgazomenosAnalytics' => ['ErgazomenosWTOAnalytics' => [[
                    'f_type' => (string) $leave->type?->value,
                    'f_from' => '',
                    'f_to' => '',
                    'f_year' => $annual ? $d->format('Y') : '',
                    'f_req_days' => $annual ? sprintf('%03d', min(999, (int) $employee->annual_leave_days)) : '',
                ]]],
            ];
        }

        if ($days === []) {
            return null;
        }

        return ['WTOS' => ['WTO' => [[
            'f_aa_pararthmatos' => (string) (int) $employee->ergani_branch,
            'f_rel_protocol' => '',
            'f_rel_date' => '',
            // Reference only — the employee's own note may be private (health etc.)
            // and has no business in an official declaration.
            'f_comments' => 'ekdosi #'.$leave->getKey(),
            'f_from_date' => $from->format('d/m/Y'),
            'f_to_date' => $to->format('d/m/Y'),
            'Ergazomenoi' => ['ErgazomenoiWTO' => $days],
        ]]]];
    }

    /** ΕΡΓΑΝΗ keeps names in capitals without accents («Παπαδόπουλος» → «ΠΑΠΑΔΟΠΟΥΛΟΣ»). */
    public static function erganiName(string $name, int $max): string
    {
        $upper = mb_strtoupper(trim($name));
        // Decompose and drop every combining mark (τόνος, διαλυτικά — incl. ΐ/ΰ).
        if (class_exists(\Normalizer::class)) {
            $upper = (string) preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($upper, \Normalizer::FORM_D));
        }
        $upper = strtr($upper, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ']);

        return mb_substr($upper, 0, $max);
    }

    /** A precondition stopped the declaration before anything was sent — audited too. */
    private function refuse(LeaveRequest $leave, ?int $userId, string $message): bool
    {
        $this->audit($leave, 'submit', [], ['ok' => false, 'status' => 0, 'message' => 'Δεν στάλθηκε: '.$message, 'response' => ''], $userId);

        return $this->fail($leave, $message);
    }

    private function fail(LeaveRequest $leave, string $message): bool
    {
        $leave->forceFill([
            'ergani_status' => 'failed',
            'ergani_env' => $leave->company?->ergani_mode,
            'ergani_error' => mb_substr($message, 0, 1000),
        ])->saveQuietly();
        Log::warning('ΕΡΓΑΝΗ leave submission failed', ['leave' => $leave->getKey(), 'error' => $message]);

        return false;
    }

    /** @param array<string, mixed> $result */
    private function audit(LeaveRequest $leave, string $action, array $request, array $result, ?int $userId): void
    {
        ErganiSubmission::create([
            'company_id' => $leave->company_id,
            'leave_request_id' => $leave->getKey(),
            'user_id' => $userId,
            'document' => self::DOCUMENT,
            'action' => $action,
            // The environment the leave is PINNED to (set at claim / submission).
            'environment' => (string) ($leave->ergani_env ?: $leave->company?->ergani_mode),
            'ok' => (bool) $result['ok'],
            'http_status' => $result['status'] ?: null,
            'protocol' => $result['protocol'] ?? null,
            'ergani_id' => $result['id'] ?? null,
            'submit_date' => $result['submitted_at'] ?? null,
            'message' => $result['message'] ?? null,
            'request' => $request,
            'response' => $result['response'] ?? null,
        ]);
    }

    /** ΕΡΓΑΝΗ returns «dd/mm/yyyy HH:ii». */
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
