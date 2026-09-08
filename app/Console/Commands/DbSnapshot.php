<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BuildsDbClientArgs;
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
    use BuildsDbClientArgs;

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

        // gzip the dump. A failure here MUST fail the command — the snapshot is
        // the update rollback point; a swallowed gzip error would let
        // deploy/update.sh proceed with no valid backup.
        $gzipped = $this->gzipFile($tmpSql, $out);
        @unlink($tmpSql);

        $bytes = (int) (@filesize($out) ?: 0);
        if (! $gzipped || $bytes <= 0) {
            @unlink($out);
            $this->error('Η συμπίεση του στιγμιότυπου απέτυχε — δεν δημιουργήθηκε έγκυρο snapshot.');

            return self::FAILURE;
        }

        $size = number_format($bytes / 1048576, 2);
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
            ...self::dbConnectionArgs($cfg),
            '--single-transaction',   // consistent dump without locking InnoDB
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--result-file='.$resultFile,
            // OPS-7: DB-agnostic dump (no CREATE/USE DATABASE). The clean-slate
            // DROP+CREATE lives in `db-restore` instead, so it always targets the
            // RESTORE connection's db (not a name baked into the snapshot) and can
            // recreate a db that doesn't exist. See DbRestore.
            (string) ($cfg['database'] ?? ''),
        ];
    }

    /**
     * Stream-gzip $src → $dst (constant memory, handles large dumps). Returns
     * false on any I/O failure so the caller can fail the command rather than
     * report a missing/truncated snapshot as success.
     */
    private function gzipFile(string $src, string $dst): bool
    {
        $in = fopen($src, 'rb');
        $gz = gzopen($dst, 'wb9');
        if ($in === false || $gz === false) {
            if ($in !== false) {
                fclose($in);
            }
            if ($gz !== false) {
                gzclose($gz);
            }

            return false;
        }

        $ok = true;
        while (! feof($in)) {
            $chunk = fread($in, 1 << 20);
            if ($chunk === false) {
                $ok = false;
                break;
            }
            if ($chunk !== '' && gzwrite($gz, $chunk) === false) {
                $ok = false;
                break;
            }
        }
        fclose($in);
        gzclose($gz);

        return $ok;
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
