<?php

namespace App\Support\OperatorHealth;

/**
 * OPS-4 (AUDIT): distil the full OperatorHealthReport into ONE actionable
 * severity + exit code, so `ops:health` can return non-zero (the deploy gate in
 * deploy/update.sh and any cron `ops:health || alert` were dead code while it
 * always returned 0).
 *
 * Levels → exit codes:
 *   ok       → 0   nothing to report
 *   warning  → 1   degraded but not data-loss (failed jobs, mail failures, an
 *                  off-site/books backup gap, a failed scheduled task, myDATA
 *                  discrepancies…)
 *   critical → 2   the system can't process or protect data right now: the queue
 *                  worker is down, or the backup monitor is failing.
 *
 * Pure function of the built report array — no DB/cache, fully unit-testable.
 */
class OperatorHealthSeverity
{
    /**
     * @param  array<string, mixed>  $data  OperatorHealthReport::build() output
     * @return array{level:string, exit_code:int, critical:list<string>, warnings:list<string>}
     */
    public static function evaluate(array $data): array
    {
        $critical = [];
        $warnings = [];

        // --- Queue worker: no/stale heartbeat = jobs (mail, imports, backups) aren't running.
        $queue = $data['queue'] ?? [];
        $hb = $queue['worker_heartbeat_status'] ?? null;
        if ($hb === 'missing') {
            $critical[] = 'Queue worker: κανένα heartbeat (ο worker δεν τρέχει;).';
        } elseif ($hb === 'stale') {
            $critical[] = 'Queue worker: heartbeat παλιό (>10 λεπτά) — πιθανό stuck/down.';
        }
        $failedJobs = (int) ($queue['failed_jobs'] ?? 0);
        if ($failedJobs > 0) {
            $warnings[] = "{$failedJobs} αποτυχημένη/ες εργασία/ες στην ουρά (failed_jobs).";
        }

        // --- Backups: the DR surface.
        $backup = $data['backup'] ?? [];
        if (($backup['monitor']['status'] ?? null) === 'failed') {
            $critical[] = 'Backup monitor: FAILED — τα αντίγραφα δεν είναι υγιή.';
        }
        $companies = $backup['companies'] ?? [];
        if (($companies['offsite_gap'] ?? false) === true) {
            $warnings[] = 'Off-site backup gap: κάποιος tenant δεν στέλνει εκτός VM ή απέτυχε η αποστολή.';
        }
        if (($companies['books_gap'] ?? false) === true) {
            $warnings[] = 'Αντίγραφα ΧΩΡΙΣ τα βιβλία: tenant με backup bucket≠full (μόνο ρυθμίσεις).';
        }

        // --- Scheduled tasks that ran and FAILED (missing = never ran, not a failure).
        $failedTasks = [];
        foreach (($data['scheduler'] ?? []) as $task) {
            if (($task['status'] ?? null) === 'failed') {
                $failedTasks[] = $task['label'] ?? '?';
            }
        }
        if ($failedTasks !== []) {
            $warnings[] = 'Απέτυχε προγραμματισμένη εργασία: '.implode(', ', $failedTasks).'.';
        }

        // --- Mail.
        $mailFailed = (int) ($data['mail']['failed_24h'] ?? 0);
        if ($mailFailed > 0) {
            $warnings[] = "{$mailFailed} αποτυχία/ες email τιμολογίων (24ω).";
        }

        // --- WHMCS / myDATA per-tenant failures + discrepancies.
        $whmcsFailed = [];
        foreach (($data['whmcs'] ?? []) as $row) {
            if (($row['status'] ?? null) === 'failed') {
                $whmcsFailed[] = $row['tenant'] ?? '?';
            }
        }
        if ($whmcsFailed !== []) {
            $warnings[] = 'WHMCS fetch απέτυχε: '.implode(', ', $whmcsFailed).'.';
        }

        $mydataFailed = [];
        $mydataDiscrepant = [];
        foreach (($data['mydata'] ?? []) as $row) {
            if (($row['status'] ?? null) === 'failed') {
                $mydataFailed[] = $row['tenant'] ?? '?';
            } elseif ((int) ($row['discrepancies'] ?? 0) > 0) {
                $mydataDiscrepant[] = $row['tenant'] ?? '?';
            }
        }
        if ($mydataFailed !== []) {
            $warnings[] = 'myDATA reconcile απέτυχε: '.implode(', ', $mydataFailed).'.';
        }
        if ($mydataDiscrepant !== []) {
            $warnings[] = 'myDATA αποκλίσεις: '.implode(', ', $mydataDiscrepant).'.';
        }

        $level = $critical !== [] ? 'critical' : ($warnings !== [] ? 'warning' : 'ok');
        $exit = $critical !== [] ? 2 : ($warnings !== [] ? 1 : 0);

        return [
            'level' => $level,
            'exit_code' => $exit,
            'critical' => $critical,
            'warnings' => $warnings,
        ];
    }
}
