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
 *                  worker is clearly down (no heartbeat for >30 min), or the
 *                  backup monitor is failing.
 *
 * Pure function of the built report array — no DB/cache, fully unit-testable.
 */
class OperatorHealthSeverity
{
    /**
     * A heartbeat runs every 5 min; a deploy STOPS the worker for the whole
     * maintenance window (OPS-6), so a briefly-stale heartbeat right after a
     * deploy is expected — only escalate to CRITICAL once it's been silent long
     * enough to mean a real outage, not a restart.
     */
    private const HEARTBEAT_CRITICAL_MINUTES = 30;

    /**
     * @param  array<string, mixed>  $data  OperatorHealthReport::build() output
     * @return array{level:string, exit_code:int, critical:list<string>, warnings:list<string>}
     */
    public static function evaluate(array $data): array
    {
        $critical = [];
        $warnings = [];

        // --- Queue worker: no/stale heartbeat = jobs (mail, imports, backups) aren't running.
        // 'missing' (never seen / cache cleared) and a briefly-stale beat are only
        // WARNINGS; a heartbeat silent for >30 min is a real outage → CRITICAL.
        $queue = $data['queue'] ?? [];
        $hb = $queue['worker_heartbeat_status'] ?? null;
        $age = $queue['worker_heartbeat_age_minutes'] ?? null;
        if ($hb === 'missing') {
            $warnings[] = 'Queue worker: κανένα heartbeat ακόμη (fresh box / cache;).';
        } elseif ($hb === 'stale') {
            if ($age !== null && $age > self::HEARTBEAT_CRITICAL_MINUTES) {
                $critical[] = 'Queue worker: heartbeat σιωπηλό >'.self::HEARTBEAT_CRITICAL_MINUTES.' λεπτά — πιθανό down.';
            } else {
                $warnings[] = 'Queue worker: heartbeat παλιό (>10 λεπτά) — μόλις έκανε restart;';
            }
        }
        // Only RECENT (24h) failures gate — an old un-flushed row must not warn forever.
        $failedJobs = (int) ($queue['failed_jobs_24h'] ?? 0);
        if ($failedJobs > 0) {
            $warnings[] = "{$failedJobs} αποτυχημένη/ες εργασία/ες στην ουρά (24ω).";
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
