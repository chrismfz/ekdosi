<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SuperAdminMcpTool;
use App\Mcp\Tools\Concerns\TailsLogFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Tail the PHP / FPM / web-server ERROR log — the errors that NEVER reach
 * `laravel.log`: a fatal (max nesting / execution time / memory), an infinite
 * recursion or segfault that kills the FPM worker, or a 500 raised before the
 * framework's exception handler runs. «There was an error while attempting to load
 * this page» with an EMPTY `laravel.log` lands HERE — `log_tail` (which reads
 * storage/logs) will not show it.
 *
 * Portable across standalone / cPanel / DirectAdmin / Virtualmin without hardcoded
 * paths: the primary source is PHP's OWN runtime error log, resolved from
 * `ini_get('error_log')`. PHP writes that file as the account user, so it is
 * (almost) always readable by this app. A curated candidate list of common FPM /
 * nginx / apache / panel locations is probed as best-effort — many are root-owned
 * on shared hosting and are simply skipped when unreadable (reported under
 * `checked` so the operator can name a missing path).
 *
 * Read-only, super_admin. Reads only from the END of each file (bounded), so a
 * huge log never blows memory, and returns at most a few files.
 */
#[Name('error_log_tail')]
#[Description('Tail the PHP/FPM/web-server ERROR log — the errors that never reach laravel.log (fatals, infinite recursion, FPM-worker death, pre-framework 500s). «Error while loading page» with an empty laravel.log is HERE. Primary source is PHP\'s own error_log (ini_get, portable across cPanel/DirectAdmin/Virtualmin/standalone); common fpm/nginx/apache locations are probed as best-effort. Optional `lines` (default 80, max 300) and `contains` (case-insensitive substring). Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class ErrorLogTailTool extends SuperAdminMcpTool
{
    use TailsLogFiles;

    private const MAX_TAIL_BYTES = 262144; // 256 KiB read window from EOF, per file

    private const MAX_OUTPUT_BYTES = 65536; // 64 KiB cap on returned text, per file

    private const MAX_FILES = 6; // never return more than this many logs

    private const MAX_GLOB_PER_PATTERN = 4; // cap fan-out of a home-dir glob

    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->integer()
                ->description('How many trailing lines to return per file (default 80, max 300).'),
            'contains' => $schema->string()
                ->description('Keep only lines containing this text (case-insensitive). Optional.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $lines = max(1, min((int) ($request->get('lines') ?? 80), 300));
        $contains = trim((string) ($request->get('contains') ?? ''));

        $iniErrorLog = trim((string) ini_get('error_log'));

        $files = [];      // realpath => payload
        $checked = [];    // every candidate we probed + why it was/ wasn't used
        foreach ($this->candidates($iniErrorLog) as [$source, $path]) {
            if (count($files) >= self::MAX_FILES) {
                // Budget spent — record the rest as skipped (not silently dropped)
                // so «name a missing path» stays honest about what wasn't probed.
                $checked[] = ['source' => $source, 'path' => $path, 'status' => 'skipped (file budget)'];

                continue;
            }

            if (! is_file($path)) {
                // is_file() is false BOTH when the file is truly absent AND when we
                // can't even STAT it because the parent dir isn't traversable (the app
                // user ≠ the log owner — e.g. /var/log/php-fpm is 0770 apache:root and
                // we run as the pool user). Distinguish them so «absent» never masks a
                // permissions problem the operator must actually fix.
                $dir = dirname($path);
                $noAccess = is_dir($dir) && ! (@is_readable($dir) && @is_executable($dir));
                $checked[] = ['source' => $source, 'path' => $path, 'status' => $noAccess ? 'no access (parent dir)' : 'absent'];

                continue;
            }
            if (! is_readable($path)) {
                // The common shared-hosting case for root-owned system logs.
                $checked[] = ['source' => $source, 'path' => $path, 'status' => 'unreadable'];

                continue;
            }

            $real = realpath($path) ?: $path;
            if (isset($files[$real])) {
                $files[$real]['sources'][] = $source;
                $checked[] = ['source' => $source, 'path' => $path, 'status' => 'duplicate'];

                continue;
            }

            $rows = $this->tailRows($path, $lines, $contains);
            $files[$real] = [
                'path' => $real,
                'sources' => [$source],
                'size_bytes' => (int) (filesize($path) ?: 0),
                'modified_at' => date('c', (int) (filemtime($path) ?: time())),
                'returned' => count($rows),
                'lines' => $rows,
            ];
            $checked[] = ['source' => $source, 'path' => $path, 'status' => 'read'];
        }

        // Diagnose the PHP logging setup itself — the «why is nothing logged?»
        // answer. If PHP is told to log to a path the app user can't WRITE, its
        // fatals are silently DROPPED (the exact reason a crash left no trace).
        $logging = $this->phpLoggingDiagnostic($iniErrorLog);

        return self::json([
            'php_logging' => $logging,
            // Kept for back-compat with the first version's key.
            'php_error_log_ini' => $iniErrorLog !== '' ? $iniErrorLog : null,
            'files_found' => count($files),
            'files' => array_values($files),
            'checked' => $checked,
            'contains' => $contains !== '' ? $contains : null,
            'note' => $this->buildNote($logging, count($files)),
        ]);
    }

    /**
     * Is PHP actually able to record fatals, and where? Surfaces `log_errors`, the
     * `error_log` target, and — the killer field — whether the app user can WRITE
     * there. `writable_by_app === false` means PHP fatals are being DROPPED.
     *
     * @return array<string, mixed>
     */
    private function phpLoggingDiagnostic(string $iniErrorLog): array
    {
        $isFile = $iniErrorLog !== '' && strtolower($iniErrorLog) !== 'syslog';
        $target = $iniErrorLog === '' ? 'stderr → FPM (no explicit error_log)'
            : (strtolower($iniErrorLog) === 'syslog' ? 'syslog' : 'file');

        $writable = null;
        if ($isFile) {
            $writable = is_file($iniErrorLog) ? @is_writable($iniErrorLog) : @is_writable(dirname($iniErrorLog));
        }

        return [
            'log_errors' => (bool) ini_get('log_errors') ? 'on' : 'off',
            'error_log' => $iniErrorLog !== '' ? $iniErrorLog : null,
            'target' => $target,
            // null = not a plain file target (syslog / stderr); true/false = the app
            // user can / cannot write where PHP is configured to log.
            'writable_by_app' => $writable,
        ];
    }

    /** @param array<string, mixed> $logging */
    private function buildNote(array $logging, int $filesFound): string
    {
        if (($logging['writable_by_app'] ?? null) === false) {
            return 'ΠΡΟΣΟΧΗ: το PHP είναι ρυθμισμένο να γράφει errors στο «'.$logging['error_log'].'» αλλά ο '
                .'app user ΔΕΝ έχει δικαίωμα εγγραφής εκεί — άρα τα PHP fatals ΧΑΝΟΝΤΑΙ (γι\' αυτό δεν βλέπεις '
                .'τίποτα). Διόρθωση: στο FPM pool δείξε το error_log σε path που ανήκει στον app user, π.χ. '
                .'`php_admin_value[error_log] = '.base_path('storage/logs/php-error.log').'` (+ `php_admin_flag[log_errors] = on`), '
                .'reload το php-fpm — μετά το error_log_tail θα το διαβάζει αυτόματα.';
        }

        if (($logging['log_errors'] ?? null) === 'off') {
            return 'ΠΡΟΣΟΧΗ: `log_errors` = off — το PHP δεν καταγράφει errors καθόλου. Βάλε '
                .'`php_admin_flag[log_errors] = on` στο FPM pool και όρισε writable `error_log`.';
        }

        if ($filesFound === 0) {
            return 'Κανένα αναγνώσιμο error log δεν βρέθηκε (δες `checked`: «no access (parent dir)» = θέμα '
                .'δικαιωμάτων, όχι ότι λείπει). Το `error_log` του PHP δείχνει «'.($logging['error_log'] ?? '—').'». '
                .'Αν ο app user δεν το διαβάζει, δείξε το σε path υπό το storage/. Για το laravel.log: log_tail.';
        }

        return 'Αυτά είναι PHP/FPM/web-server error logs (ΟΧΙ το laravel.log — γι\' αυτό: log_tail). Εδώ '
            .'πέφτουν fatals/recursion/worker deaths που δεν πιάνει ο Laravel handler.';
    }

    /**
     * Ordered (source-label, absolute-path) candidates. The PHP `error_log` ini
     * value goes FIRST (portable + readable); then common absolute system logs;
     * then home-relative globs that a shared-hosting account user can actually read.
     *
     * @return list<array{0:string,1:string}>
     */
    private function candidates(string $iniErrorLog): array
    {
        $out = [];

        // 1) The authoritative, portable source — where PHP itself logs. First so it
        //    can never be starved by the file budget.
        if ($iniErrorLog !== '' && strtolower($iniErrorLog) !== 'syslog') {
            $out[] = ['php_error_log (ini)', $iniErrorLog];
        }

        // 2) Home-relative logs the account user CAN read (cPanel/DirectAdmin/
        //    Virtualmin per-account + per-domain error logs) — the MOST
        //    app-relevant on shared hosting, so BEFORE the broad system list so a
        //    box with many readable /var/log files doesn't exhaust the budget first.
        $home = $this->accountHome();
        $patterns = [];
        if ($home !== null) {
            $patterns[] = [$home.'/logs/*error*', 'account home'];
            $patterns[] = [$home.'/domains/*/logs/*.error.log', 'domain (DirectAdmin)'];
        }
        // Some layouts keep vhost logs a level above the docroot.
        $patterns[] = [dirname(base_path()).'/logs/*error*', 'above app root'];

        foreach ($patterns as [$pattern, $label]) {
            $matches = array_filter(glob($pattern) ?: [], $this->isPlainLog(...));
            // Newest first, so the most relevant (live) domain log wins the budget.
            usort($matches, static fn ($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
            foreach (array_slice($matches, 0, self::MAX_GLOB_PER_PATTERN) as $m) {
                $out[] = [$label, $m];
            }
        }

        // 3) Common absolute FPM / PHP / web-server / panel locations (best-effort;
        //    root-owned ones are reported and skipped on shared hosting).
        foreach ([
            '/var/log/php-fpm/www-error.log',
            '/var/log/php-fpm/error.log',
            '/var/log/php-fpm.log',
            '/var/log/php_errors.log',
            '/var/log/nginx/error.log',
            '/var/log/apache2/error.log',
            '/var/log/httpd/error_log',
            '/usr/local/cpanel/logs/error_log',
            '/var/log/directadmin/error.log',
        ] as $p) {
            $out[] = ['system', $p];
        }

        return $out;
    }

    /**
     * Exclude rotated/compressed logs from a glob — a freshly-rotated `error.log.1`
     * or `.gz` could be newest and tail binary/stale content into the response.
     */
    private function isPlainLog(string $path): bool
    {
        return preg_match('/\.(gz|bz2|xz|zip|zst|\d+)$/i', $path) !== 1;
    }

    /** Best-effort account home for the running process (empty → skipped). */
    private function accountHome(): ?string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '' && is_dir($home)) {
            return rtrim($home, '/');
        }

        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $pw = @posix_getpwuid(posix_geteuid());
            if (is_array($pw) && ! empty($pw['dir']) && is_dir($pw['dir'])) {
                return rtrim((string) $pw['dir'], '/');
            }
        }

        return null;
    }

    /**
     * Tail one file: read the last MAX_TAIL_BYTES, split to lines, optional
     * case-insensitive `contains` filter, keep the last `$lines`, cap bytes.
     *
     * @return list<string>
     */
    private function tailRows(string $file, int $lines, string $contains): array
    {
        $tail = $this->readTailString($file, self::MAX_TAIL_BYTES);
        $rows = $tail === '' ? [] : explode("\n", $tail);

        if ($contains !== '') {
            $rows = array_values(array_filter($rows, static fn ($l) => stripos($l, $contains) !== false));
        }

        $rows = array_slice($rows, -$lines);

        return $this->capTailBytes($rows, self::MAX_OUTPUT_BYTES);
    }
}
