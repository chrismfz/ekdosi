<?php

namespace App\Mcp\Tools\Concerns;

/**
 * Shared bounded log-tailing primitives for the ops tools (log_tail,
 * error_log_tail): read a bounded window from the END of a file, and cap the
 * returned lines by total bytes WITHOUT ever dropping the newest line to «empty».
 * One home for the logic so a fix (e.g. the over-long-fatal case) lands in both.
 */
trait TailsLogFiles
{
    /**
     * Read up to $maxBytes from the end of $file and return it as a trimmed
     * string, dropping a partial first line when we started mid-file.
     */
    protected function readTailString(string $file, int $maxBytes): string
    {
        $size = filesize($file);
        if ($size === false || $size === 0) {
            return '';
        }

        $read = (int) min($size, $maxBytes);
        $fh = fopen($file, 'rb');
        if ($fh === false) {
            return '';
        }
        fseek($fh, -$read, SEEK_END);
        $chunk = (string) fread($fh, $read);
        fclose($fh);

        // Started mid-file → the first line is likely partial; drop it.
        if ($read < $size) {
            $nl = strpos($chunk, "\n");
            $chunk = $nl === false ? $chunk : substr($chunk, $nl + 1);
        }

        return rtrim($chunk, "\n");
    }

    /**
     * Keep the returned lines under $maxBytes, trimming from the OLDEST (front) so
     * the most recent lines survive. Crucially, the newest line is ALWAYS returned
     * — truncated with a marker when it alone exceeds the budget — so a single
     * over-long fatal / stack trace is never silently dropped to «returned: 0».
     *
     * @param  list<string>  $rows
     * @return list<string>
     */
    protected function capTailBytes(array $rows, int $maxBytes): array
    {
        $total = 0;
        $kept = [];
        foreach (array_reverse($rows) as $line) {
            $len = strlen($line) + 1; // +1 for the joining newline

            if ($total + $len > $maxBytes) {
                if ($kept === []) {
                    // The newest line alone busts the cap — surface it truncated
                    // rather than returning nothing (that would read as «empty»).
                    $marker = '…[γραμμή περικομμένη]';
                    $room = max(0, $maxBytes - strlen($marker));
                    $kept[] = substr($line, 0, $room).$marker;
                }
                break;
            }

            $total += $len;
            $kept[] = $line;
        }

        return array_reverse($kept);
    }
}
