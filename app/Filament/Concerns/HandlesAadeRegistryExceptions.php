<?php

namespace App\Filament\Concerns;

use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeRegistryException;
use App\Exceptions\Aade\AadeUnreachable;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Centralised mapping from AADE registry exceptions to operator-facing
 * messages, so the 4 historically-duplicated catch ladders
 * (CustomerForm suffix-action, CompanyForm GSIS test, CompanyForm AFM
 * lookup, CustomerLedger crosscheck) share a single definition.
 *
 * Two consumption patterns:
 *
 *   - notifyAadeException($e) — for sites that show a Filament
 *     Notification and return. Most existing call sites.
 *
 *   - aadeExceptionDetails($e) — for sites that need the title/body
 *     pair as plain strings (e.g. CustomerLedger's runAadeCrosscheck
 *     returns a structured ['error' => ...] result instead of
 *     showing a notification directly).
 *
 * When a new AADE exception class is added (e.g. AadeRateLimited per
 * the deferred items in CLAUDE.md), ONE arm of the match below
 * captures it for all 4+ call sites.
 */
trait HandlesAadeRegistryExceptions
{
    /**
     * @return array{title: string, body: string, severity: 'danger'|'warning'}
     */
    protected function aadeExceptionDetails(Throwable $e): array
    {
        return match (true) {
            $e instanceof AadeCredentialsInvalid => [
                'title'    => 'GSIS credentials missing or invalid',
                'body'     => 'Configure them on the Company → AADE registry (GSIS) tab.',
                'severity' => 'danger',
            ],
            $e instanceof AadeAfmNotFound => [
                'title'    => 'AFM not found at AADE',
                'body'     => 'Η ΑΑΔΕ δεν αναγνωρίζει αυτό το ΑΦΜ.',
                'severity' => 'warning',
            ],
            $e instanceof AadeUnreachable => [
                'title'    => 'AADE unreachable',
                'body'     => $e->getMessage(),
                'severity' => 'danger',
            ],
            $e instanceof AadeRegistryException => [
                'title'    => 'AADE error',
                'body'     => $e->getMessage(),
                'severity' => 'danger',
            ],
            default => [
                // Unreachable today (CustomerLedger catches the
                // AadeRegistryException base class — all 4 known
                // subclasses are above). Future callers may pass an
                // arbitrary Throwable here, so do NOT expose the raw
                // message in the operator-facing body: Guzzle / SOAP
                // exceptions can carry credentials in URLs or
                // request envelopes. Log the raw message for ops to
                // see in the system log; show a generic toast.
                'title'    => 'Unexpected AADE error',
                'body'     => 'See system logs for details.',
                'severity' => 'danger',
            ],
        };
    }

    /**
     * Construct + send a Notification from an AADE exception. Honours
     * the severity mapping (warning for AFM-not-found, danger for
     * everything else). Does NOT make the notification persistent -
     * callers that want persistence should use aadeExceptionDetails()
     * and build the Notification themselves.
     */
    protected function notifyAadeException(Throwable $e): void
    {
        $d = $this->aadeExceptionDetails($e);

        // For the unmapped default case, log the raw exception so ops
        // can diagnose — the toast body is intentionally generic to
        // avoid leaking credentials in third-party exception messages.
        if ($d['title'] === 'Unexpected AADE error') {
            Log::warning('AADE exception not mapped to a specific operator message', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'tenant_id' => method_exists($this, 'getTenant') ? $this->getTenant()?->getKey() : null,
            ]);
        }

        Notification::make()
            ->title($d['title'])
            ->body($d['body'])
            ->{$d['severity']}()
            ->send();
    }
}
