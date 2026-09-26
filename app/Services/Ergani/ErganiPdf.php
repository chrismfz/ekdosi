<?php

namespace App\Services\Ergani;

use App\Models\Company;
use App\Models\ErganiSubmission;
use App\Models\LeaveRequest;
use App\Models\OvertimeDeclaration;
use Illuminate\Mail\Attachment;
use Illuminate\Support\Facades\Log;

/**
 * The official ΕΡΓΑΝΗ PDF of a successful declaration (`GET Documents/{code}`),
 * fetched live from the environment it was declared in. Trial-verified
 * 2026-09-26 for WTOLeave / WTOOv / WRKCardSE; a withdrawn declaration has none.
 * Never throws — a missing PDF must not break an email or a page.
 */
class ErganiPdf
{
    public const DOCUMENTS = ['WTOLeave', 'WTOOv', 'WRKCardSE'];

    public function fetch(ErganiSubmission $submission): ?string
    {
        $company = $submission->company;
        $date = self::submitDateYmd($submission);
        if (! $company instanceof Company || $date === null || ! $submission->ok || blank($submission->protocol)
            || ! in_array($submission->document, self::DOCUMENTS, true)) {
            return null;
        }
        $env = clone $company;
        $env->ergani_mode = $submission->environment;   // in memory only
        try {
            $pdf = (new ErganiClient($env))->pdf($submission->document, (string) $submission->protocol, $date);
        } catch (\Throwable $e) {
            Log::info('ΕΡΓΑΝΗ PDF not available', ['submission' => $submission->getKey(), 'error' => $e->getMessage()]);

            return null;
        }

        return is_string($pdf) && str_starts_with($pdf, '%PDF') ? $pdf : null;
    }

    /**
     * For an accountant email: only a PRODUCTION declaration (a trial PDF has no
     * legal force and would confuse the one who still declares for real).
     *
     * @return list<Attachment>
     */
    public function attachmentFor(LeaveRequest|OvertimeDeclaration $record): array
    {
        if ($record->ergani_status !== 'submitted' || $record->ergani_env !== 'production' || blank($record->ergani_protocol)) {
            return [];
        }
        $submission = $record->erganiSubmissions()->where('ok', true)->where('action', 'submit')
            ->where('protocol', $record->ergani_protocol)->latest('id')->first();
        $pdf = $submission ? $this->fetch($submission) : null;
        if ($pdf === null) {
            return [];
        }

        return [Attachment::fromData(fn (): string => $pdf, 'ergani-'.preg_replace('/[^\w]+/u', '-', (string) $record->ergani_protocol).'.pdf')
            ->withMime('application/pdf')];
    }

    /** «25/09/2026 21:45» (as ΕΡΓΑΝΗ returned it) → «20260925». */
    public static function submitDateYmd(ErganiSubmission $submission): ?string
    {
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', (string) $submission->submit_date, $m)) {
            return $m[3].$m[2].$m[1];
        }

        return $submission->created_at?->timezone('Europe/Athens')->format('Ymd');
    }
}
