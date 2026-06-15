<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * `ekdosi:db-snapshot` — dump the primary MariaDB/MySQL database to a single
 * gzipped .sql file. This is the **rollback point** taken automatically before
 * an update (deploy/update.sh) and runnable on demand for a restore drill.
 *
 *   php artisan ekdosi:db-snapshot                 # → storage/app/db-snapshots/ekdosi-<ts>.sql.gz
 *   php artisan ekdosi:db-snapshot --out=/path.sql.gz
 *   php artisan ekdosi:db-snapshot --keep=10       # prune to the 10 newest in the default dir
 *
 * Credentials come from the active DB connection (config/.env) — never typed by
 * hand. The password is passed via the MYSQL_PWD env var (not argv), so it
 * doesn't leak into the process list. Distinct from spatie/laravel-backup (the
 * off-site, scheduled, per-company backups): this is a fast, local,
 * whole-DB snapshot purpose-built for update rollback.
 */
class DbSnapshot extends Command
{
    protected $signature = 'ekdosi:db-snapshot
        {--out= : Output file path (default: storage/app/db-snapshots/ekdosi-<ts>.sql.gz)}
        {--keep=0 : Keep only the N most recent snapshots in the default dir (0 = keep all)}';

    protected $description = 'Dump the primary MariaDB/MySQL database to a gzipped .sql snapshot (pre-update rollback point).';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        /** @var array<string, mixed> $cfg */
        $cfg = config("database.connections.{$connection}", []);

        if (! in_array($cfg['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->error('Υποστηρίζεται μόνο MariaDB/MySQL (τρέχων driver: '.($cfg['driver'] ?? '?').').');

            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: $this->defaultPath());
        File::ensureDirectoryExists(dirname($out));

        // mysqldump → a plaintext temp .sql (via --result-file, no shell
        // redirection), then gzip-stream it and drop the plaintext.
        $tmpSql = $out.'.tmp.sql';

        $process = new Process(
            self::dumpCommand($cfg, $tmpSql),
            timeout: null,
            env: ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')],
        );

        $this->info('Δημιουργία στιγμιότυπου ΒΔ…');
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            @unlink($tmpSql);
            $this->error('Το mysqldump απέτυχε: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->gzipFile($tmpSql, $out);
        @unlink($tmpSql);

        $size = number_format(((int) (@filesize($out) ?: 0)) / 1048576, 2);
        $this->info("✓ Στιγμιότυπο: {$out} ({$size} MB)");

        $this->prune();

        return self::SUCCESS;
    }

    private function defaultPath(): string
    {
        return storage_path('app/db-snapshots/ekdosi-'.now()->format('Ymd-His').'.sql.gz');
    }

    /**
     * The mysqldump argv (no shell). Public + static so it's unit-testable
     * without a live database.
     *
     * @param  array<string, mixed>  $cfg  a database.connections.* config array
     * @return list<string>
     */
    public static function dumpCommand(array $cfg, string $resultFile): array
    {
        return [
            'mysqldump',
            '--host='.($cfg['host'] ?? '127.0.0.1'),
            '--port='.($cfg['port'] ?? 3306),
            '--user='.($cfg['username'] ?? 'root'),
            '--default-character-set='.($cfg['charset'] ?? 'utf8mb4'),
            '--single-transaction',   // consistent dump without locking InnoDB
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--result-file='.$resultFile,
            (string) ($cfg['database'] ?? ''),
        ];
    }

    /** Stream-gzip $src → $dst (constant memory, handles large dumps). */
    private function gzipFile(string $src, string $dst): void
    {
        $in = fopen($src, 'rb');
        $gz = gzopen($dst, 'wb9');
        if ($in === false || $gz === false) {
            $this->error('Αδυναμία συμπίεσης του στιγμιότυπου.');

            return;
        }
        while (! feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false) {
                break;
            }
            gzwrite($gz, $chunk);
        }
        fclose($in);
        gzclose($gz);
    }

    /** Keep only the N newest snapshots in the default dir (--keep). */
    private function prune(): void
    {
        $keep = (int) $this->option('keep');
        if ($keep <= 0) {
            return;
        }
        $dir = storage_path('app/db-snapshots');
        collect(File::glob($dir.'/ekdosi-*.sql.gz'))
            ->sortByDesc(fn (string $f): int => (int) (@filemtime($f) ?: 0))
            ->values()
            ->slice($keep)
            ->each(function (string $f): void {
                @unlink($f);
                $this->line('  · καθαρίστηκε παλιό: '.basename($f));
            });
    }
}
