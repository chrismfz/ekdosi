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
 * Tail the Laravel application log for remote debugging — «τι λέει το log;». Reads
 * the newest `storage/logs/laravel*.log` file from the END only (bounded, so a
 * huge log never blows memory), optionally filtered by level and/or a substring.
 * The go-to when `app_health`/`failed_jobs` point at a problem and you need the
 * actual error text and its context. Read-only, super_admin.
 *
 * With a `level` filter only the matching header lines are returned (a triage
 * view); with no filter you get the raw tail including multi-line stack traces.
 */
#[Name('log_tail')]
#[Description('Tail the ekdosi application log (storage/logs/laravel*.log), newest file, from the end. Optional `lines` (default 100, max 300), `level` (error/warning/info/debug/critical/... — keeps matching header lines), and `contains` (case-insensitive substring). Read-only, super-admin. Note: with no level filter you get the raw tail incl. stack traces; with a level filter only header lines match.')]
#[IsReadOnly]
#[IsIdempotent]
class LogTailTool extends SuperAdminMcpTool
{
    use TailsLogFiles;

    private const MAX_TAIL_BYTES = 262144; // 256 KiB read window from EOF

    private const MAX_OUTPUT_BYTES = 65536; // 64 KiB cap on returned text

    public function schema(JsonSchema $schema): array
    {
        return [
            'lines' => $schema->integer()
                ->description('How many trailing lines to return (default 100, max 300).'),
            'level' => $schema->string()
                ->description('Keep only lines of this log level, e.g. "error" or "warning". Optional.'),
            'contains' => $schema->string()
                ->description('Keep only lines containing this text (case-insensitive). Optional.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $file = $this->newestLogFile();
        if ($file === null) {
            return self::json(['error' => 'Δεν βρέθηκε αρχείο log στο storage/logs.', 'lines' => []]);
        }

        $lines = max(1, min((int) ($request->get('lines') ?? 100), 300));
        $level = trim((string) ($request->get('level') ?? ''));
        $contains = trim((string) ($request->get('contains') ?? ''));

        $tail = $this->readTailString($file, self::MAX_TAIL_BYTES);
        $rows = $tail === '' ? [] : explode("\n", $tail);

        if ($level !== '') {
            $rows = array_values(array_filter($rows, static fn ($l) => stripos($l, '.'.$level.':') !== false));
        }
        if ($contains !== '') {
            $rows = array_values(array_filter($rows, static fn ($l) => stripos($l, $contains) !== false));
        }

        $rows = array_slice($rows, -$lines);
        $rows = $this->capTailBytes($rows, self::MAX_OUTPUT_BYTES);

        return self::json([
            'file' => basename($file),
            'returned' => count($rows),
            'level' => $level ?: null,
            'contains' => $contains ?: null,
            'lines' => $rows,
        ]);
    }

    /** Newest storage/logs/laravel*.log by mtime (covers single + daily channels). */
    private function newestLogFile(): ?string
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        if ($files === []) {
            $single = storage_path('logs/laravel.log');

            return is_file($single) ? $single : null;
        }

        usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0];
    }
}
