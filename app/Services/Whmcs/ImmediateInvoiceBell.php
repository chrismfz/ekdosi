<?php

namespace App\Services\Whmcs;

use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * The «Άμεσο παραστατικό προς έκδοση» operator bell — a Filament DB notification
 * fired by WhmcsInvoiceIngestor when an immediate-invoice («άμεση τιμολόγηση»)
 * WHMCS row is staged for review.
 *
 * The bell used to stay UNREAD until an operator clicked it — even after the
 * παραστατικό was already issued — which needlessly worried the desk. This
 * resolves it automatically: the moment the WHMCS row is HANDLED (issued /
 * drafted / rejected / merged / split), the matching bell is marked read for
 * every recipient (PendingWhmcsInvoiceObserver::updated calls resolve()).
 *
 * The bell carries a structured tag in the notification's `viewData`
 * (kind + company + WHMCS id) so we match it precisely, never by parsing text.
 * Legacy bells (created before the tag existed) are handled by the one-shot
 * sweep(), which falls back to the WHMCS id in the body.
 */
class ImmediateInvoiceBell
{
    /** Notification title — also the matcher for legacy, pre-tag bells (sweep()). */
    public const TITLE = 'Άμεσο παραστατικό προς έκδοση';

    /** viewData discriminator so a sweep only ever touches THIS bell. */
    public const KIND = 'immediate_invoice';

    /**
     * Statuses where the operator still has to act — the bell must stay lit.
     * Everything else is «handled» (filed, drafted, rejected, resolved, split).
     */
    public const WAITING_STATUSES = [
        PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        PendingWhmcsInvoice::STATUS_HELD,
    ];

    /**
     * The structured tag stored in the notification's viewData. Strings on
     * purpose: the `data->viewData->…` JSON where-clauses then compare cleanly
     * on both sqlite and MariaDB (Laravel's `->` operator unquotes to string).
     *
     * @return array<string, string>
     */
    public static function tag(int $companyId, int $whmcsInvoiceId): array
    {
        return [
            'kind' => self::KIND,
            'company_id' => (string) $companyId,
            'whmcs_invoice_id' => (string) $whmcsInvoiceId,
        ];
    }

    /** A WHMCS row status is «handled» when it is no longer waiting on the operator. */
    public static function isHandled(?string $status): bool
    {
        return $status !== null && ! in_array($status, self::WAITING_STATUSES, true);
    }

    /**
     * Mark read every unread immediate bell carrying our tag for this exact
     * (company, WHMCS invoice). The precise, real-time path from the observer.
     *
     * @return int how many bells were cleared
     */
    public static function resolve(int $companyId, int $whmcsInvoiceId): int
    {
        return DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->viewData->kind', self::KIND)
            ->where('data->viewData->company_id', (string) $companyId)
            ->where('data->viewData->whmcs_invoice_id', (string) $whmcsInvoiceId)
            ->update(['read_at' => Carbon::now()]);
    }

    /**
     * One-shot backlog sweep: clear every unread immediate bell whose WHMCS row
     * is no longer waiting (already handled, or gone). Handles BOTH tagged bells
     * and LEGACY untagged ones — for the latter the WHMCS id is read from the
     * body and the company scoped to the notified user's own tenants. Low volume
     * (a handful of unread bells per tenant), so a plain get() is fine.
     *
     * @return int how many bells were cleared
     */
    public static function sweep(): int
    {
        $cleared = 0;

        $notes = DatabaseNotification::query()
            ->whereNull('read_at')
            ->where('data->title', self::TITLE)
            ->get();

        foreach ($notes as $note) {
            if (self::shouldClear($note)) {
                $note->markAsRead();
                $cleared++;
            }
        }

        return $cleared;
    }

    private static function shouldClear(DatabaseNotification $note): bool
    {
        $data = is_array($note->data) ? $note->data : [];
        $view = $data['viewData'] ?? [];

        $whmcsId = isset($view['whmcs_invoice_id'])
            ? (int) $view['whmcs_invoice_id']
            : self::whmcsIdFromBody((string) ($data['body'] ?? ''));
        if ($whmcsId <= 0) {
            return false; // can't identify the WHMCS invoice → leave it alone
        }

        // Company scope: the tag if present, else the notified user's tenants
        // (a WHMCS id is per-tenant, so we must scope the row lookup).
        $companyIds = isset($view['company_id'])
            ? [(int) $view['company_id']]
            : self::companyIdsOfNotifiable($note);
        if ($companyIds === []) {
            return false;
        }

        // Keep the bell lit ONLY while a still-waiting row exists; clear it when
        // the row is handled or gone.
        $hasWaitingRow = PendingWhmcsInvoice::query()
            ->whereIn('company_id', $companyIds)
            ->where('whmcs_invoice_id', $whmcsId)
            ->whereIn('status', self::WAITING_STATUSES)
            ->exists();

        return ! $hasWaitingRow;
    }

    private static function whmcsIdFromBody(string $body): int
    {
        return preg_match('/WHMCS #(\d+)/u', $body, $m) === 1 ? (int) $m[1] : 0;
    }

    /** @return array<int, int> */
    private static function companyIdsOfNotifiable(DatabaseNotification $note): array
    {
        if ($note->notifiable_type !== User::class) {
            return [];
        }
        $user = User::find($note->notifiable_id);

        return $user
            ? $user->companies()->pluck('companies.id')->map(fn ($id): int => (int) $id)->all()
            : [];
    }
}
