<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\SuperAdminMcpTool;
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
                break;
            }

            if (! is_file($path)) {
                $checked[] = ['source' => $source, 'path' => $path, 'status' => 'absent'];

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

        return self::json([
            // The portable primary source — surfaced so the operator sees exactly
            // where PHP is configured to log, regardless of the hosting panel.
            'php_error_log_ini' => $iniErrorLog !== '' ? $iniErrorLog : null,
            'files_found' => count($files),
            'files' => array_values($files),
            'checked' => $checked,
            'contains' => $contains !== '' ? $contains : null,
            'note' => count($files) === 0
                ? 'Κανένα αναγνώσιμο error log δεν βρέθηκε. Αν ξέρεις το path (π.χ. από το vhost/pool config), πες το — το app user συχνά ΔΕΝ διαβάζει τα system nginx/apache logs. Για το laravel.log χρησιμοποίησε log_tail.'
                : 'Αυτά είναι PHP/FPM/web-server error logs (ΟΧΙ το laravel.log — γι\' αυτό: log_tail). Εδώ πέφτουν fatals/recursion/worker deaths που δεν πιάνει ο Laravel handler.',
        ]);
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

        // 1) The authoritative, portable source — where PHP itself logs.
        if ($iniErrorLog !== '' && strtolower($iniErrorLog) !== 'syslog') {
            $out[] = ['php_error_log (ini)', $iniErrorLog];
        }

        // 2) Common absolute FPM / PHP / web-server / panel locations (best-effort;
        //    unreadable ones are reported and skipped).
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

        // 3) Home-relative logs the account user CAN read (cPanel/DirectAdmin/
        //    Virtualmin per-account + per-domain error logs). Globbed, bounded.
        $home = $this->accountHome();
        $patterns = [];
        if ($home !== null) {
            $patterns[] = [$home.'/logs/*error*', 'account home'];
            $patterns[] = [$home.'/domains/*/logs/*.error.log', 'domain (DirectAdmin)'];
        }
        // Some layouts keep vhost logs a level above the docroot.
        $patterns[] = [dirname(base_path()).'/logs/*error*', 'above app root'];

        foreach ($patterns as [$pattern, $label]) {
            $matches = glob($pattern) ?: [];
            // Newest first, so the most relevant domain log wins the file budget.
            usort($matches, static fn ($a, $b) => (int) @filemtime($b) <=> (int) @filemtime($a));
            foreach (array_slice($matches, 0, self::MAX_GLOB_PER_PATTERN) as $m) {
                $out[] = [$label, $m];
            }
        }

        return $out;
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
        $size = filesize($file);
        if ($size === false || $size === 0) {
            return [];
        }

        $read = (int) min($size, self::MAX_TAIL_BYTES);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return [];
        }
        fseek($fh, -$read, SEEK_END);
        $chunk = (string) fread($fh, $read);
        fclose($fh);

        // Dropped a partial first line if we started mid-file.
        if ($read < $size) {
            $nl = strpos($chunk, "\n");
            $chunk = $nl === false ? $chunk : substr($chunk, $nl + 1);
        }

        $rows = $chunk === '' ? [] : explode("\n", rtrim($chunk, "\n"));

        if ($contains !== '') {
            $rows = array_values(array_filter($rows, static fn ($l) => stripos($l, $contains) !== false));
        }

        $rows = array_slice($rows, -$lines);

        return $this->capBytes($rows);
    }

    /**
     * Keep the payload under MAX_OUTPUT_BYTES, trimming from the OLDEST (front) so
     * the most recent lines survive.
     *
     * @param  list<string>  $rows
     * @return list<string>
     */
    private function capBytes(array $rows): array
    {
        $total = 0;
        $kept = [];
        foreach (array_reverse($rows) as $line) {
            $total += strlen($line) + 1;
            if ($total > self::MAX_OUTPUT_BYTES) {
                break;
            }
            $kept[] = $line;
        }

        return array_reverse($kept);
    }
}
