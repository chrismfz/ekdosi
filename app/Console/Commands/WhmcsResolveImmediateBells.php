<?php

namespace App\Console\Commands;

use App\Services\Whmcs\ImmediateInvoiceBell;
use Illuminate\Console\Command;

/**
 * One-shot (also safe to re-run) backlog sweep for the «Άμεσο παραστατικό προς
 * έκδοση» operator bell:
 *
 *   php artisan whmcs:resolve-immediate-bells
 *
 * Going forward, PendingWhmcsInvoiceObserver auto-clears the bell the moment its
 * WHMCS row is handled. This command clears the ALREADY-EXISTING unread bells
 * (including legacy ones created before the tag existed) whose WHMCS row is no
 * longer waiting on the operator — so the desk stops seeing stale «unread»
 * badges for παραστατικά that are already out. Read-only apart from flipping the
 * notifications' read_at; never touches money or invoice state.
 */
class WhmcsResolveImmediateBells extends Command
{
    protected $signature = 'whmcs:resolve-immediate-bells';

    protected $description = 'Καθαρίζει (mark-read) τις αδιάβαστες ειδοποιήσεις «άμεσης τιμολόγησης» των οποίων το WHMCS παραστατικό έχει ήδη εκδοθεί/χειριστεί.';

    public function handle(): int
    {
        $cleared = ImmediateInvoiceBell::sweep();

        $this->info($cleared === 0
            ? 'Καμία αδιάβαστη ειδοποίηση άμεσης τιμολόγησης προς καθαρισμό.'
            : "Καθαρίστηκαν {$cleared} ειδοποιήσεις άμεσης τιμολόγησης (το WHMCS παραστατικό έχει ήδη χειριστεί).");

        return self::SUCCESS;
    }
}
