<?php

namespace App\Services\Import;

use RuntimeException;

/**
 * A CSV file read into a header + rows, tolerant of what spreadsheets actually
 * produce: a UTF-8 BOM, Greek Excel's Windows-1253 «CSV» and its `;` list
 * separator (or `,` / TAB), an Excel `sep=;` hint line, quoted multi-line cells
 * and trailing blank lines.
 */
final class CsvTable
{
    /** Hard ceiling on data rows — an import runs inside one web request. */
    public const MAX_ROWS = 5000;

    /**
     * @param  list<string>  $headers
     * @param  list<array{line: int, cells: list<string>}>  $rows  line = the 1-based file row the record starts on
     */
    private function __construct(
        public readonly array $headers,
        public readonly array $rows,
    ) {}

    public static function fromFile(string $path): self
    {
        $content = @file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Δεν ήταν δυνατή η ανάγνωση του αρχείου.');
        }

        return self::fromString($content);
    }

    public static function fromString(string $content): self
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            // Greek Excel saves «CSV» in the ANSI code page, not UTF-8.
            $converted = @iconv('Windows-1253', 'UTF-8//IGNORE', $content);
            $content = $converted === false ? $content : $converted;
        }

        $content = str_replace(["\r\n", "\r"], "\n", $content);

        // Spreadsheet row numbers: blank lines and an Excel «sep=» hint before the
        // header still count, so «Γραμμή N» points at the row the operator sees.
        $skipped = strspn($content, "\n");
        $content = substr($content, $skipped);

        $delimiter = null;
        if (preg_match('/^sep=(.)\n/i', $content, $m) === 1) {
            $delimiter = $m[1];
            $content = substr($content, strlen($m[0]));
            $skipped++;
        }

        if (trim($content) === '') {
            throw new RuntimeException('Το αρχείο είναι κενό.');
        }

        $firstLine = strtok($content, "\n");
        $delimiter ??= self::detectDelimiter((string) $firstLine);

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $headers = null;
        $rows = [];
        $next = $skipped + 1;   // the physical line the next record starts on

        while (($cells = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line = $next;
            $next += 1 + array_sum(array_map(static fn ($c): int => substr_count((string) $c, "\n"), $cells));
            $cells = array_map(static fn ($c): string => trim((string) $c), $cells);

            if ($headers === null) {
                $headers = $cells;

                continue;
            }

            if (implode('', $cells) === '') {
                continue;   // blank line (spreadsheets pad the end with them)
            }

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);

                throw new RuntimeException('Το αρχείο έχει πάνω από '.self::MAX_ROWS.' γραμμές — χώρισέ το σε μικρότερα αρχεία.');
            }

            $rows[] = ['line' => $line, 'cells' => $cells];
        }

        fclose($handle);

        if ($headers === null || implode('', $headers) === '') {
            throw new RuntimeException('Λείπει η γραμμή επικεφαλίδων (ονόματα στηλών) στην αρχή του αρχείου.');
        }

        return new self($headers, $rows);
    }

    /** The candidate that splits the header line into the most columns. */
    private static function detectDelimiter(string $line): string
    {
        $best = ',';
        $bestCount = 1;
        foreach ([',', ';', "\t"] as $candidate) {
            $count = count(str_getcsv($line, $candidate, '"', ''));
            if ($count > $bestCount) {
                $best = $candidate;
                $bestCount = $count;
            }
        }

        return $best;
    }
}
