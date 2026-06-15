<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\BuildsDbClientArgs;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * `ekdosi:db-restore` — restore the primary MariaDB/MySQL database from a
 * snapshot produced by `ekdosi:db-snapshot` (.sql or .sql.gz). DESTRUCTIVE:
 * it overwrites the current data, so put the app in maintenance mode first
 * (deploy/rollback.sh does this for you).
 *
 *   php artisan ekdosi:db-restore --file=storage/app/db-snapshots/ekdosi-….sql.gz
 *   php artisan ekdosi:db-restore --file=… --force      # skip confirmation / allow in production
 *
 * The real "rollback" of an update: code can be reverted with git, but a
 * forward migration may be irreversible — restoring the pre-update snapshot is
 * the safe undo. Credentials come from the active connection; the password
 * goes via MYSQL_PWD (not argv).
 */
class DbRestore extends Command
{
    use BuildsDbClientArgs;

    protected $signature = 'ekdosi:db-restore
        {--file= : Path to a .sql or .sql.gz snapshot (from ekdosi:db-snapshot)}
        {--force : Required in production; also skips the interactive confirmation}';

    protected $description = 'Restore the primary MariaDB/MySQL database from a snapshot. DESTRUCTIVE — overwrites current data.';

    public function handle(): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            $this->error('Δώσε υπαρκτό --file=<snapshot.sql[.gz]>.');

            return self::INVALID;
        }

        $connection = (string) config('database.default');
        /** @var array<string, mixed> $cfg */
        $cfg = config("database.connections.{$connection}", []);
        if (! in_array($cfg['driver'] ?? '', ['mysql', 'mariadb'], true)) {
            $this->error('Υποστηρίζεται μόνο MariaDB/MySQL (τρέχων driver: '.($cfg['driver'] ?? '?').').');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $database = (string) ($cfg['database'] ?? '');

        // Production needs an explicit --force: this drops/overwrites live data.
        if (app()->environment('production') && ! $force) {
            $this->error('Σε production η επαναφορά χρειάζεται --force (DESTRUCTIVE — διαγράφει τα τρέχοντα δεδομένα).');

            return self::FAILURE;
        }
        if (! $force && ! $this->confirm("ΕΠΑΝΑΦΟΡΑ της ΒΔ «{$database}» από {$file}; Θα ΔΙΑΓΡΑΦΟΥΝ τα τρέχοντα δεδομένα. Σίγουρα;")) {
            $this->info('Ακυρώθηκε.');

            return self::SUCCESS;
        }

        // Stream the (optionally gz) snapshot straight into the mysql client.
        // compress.zlib:// transparently decompresses a .gz with constant memory.
        $isGz = str_ends_with(strtolower($file), '.gz');
        $input = fopen($isGz ? 'compress.zlib://'.$file : $file, 'rb');
        if ($input === false) {
            $this->error('Αδυναμία ανάγνωσης του στιγμιότυπου.');

            return self::FAILURE;
        }

        $process = new Process(
            self::restoreCommand($cfg),
            timeout: null,
            env: ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')],
        );
        $process->setInput($input);

        $this->warn('Επαναφορά ΒΔ… (μην διακόψεις τη διαδικασία)');
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->error('Η επαναφορά απέτυχε: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->info('✓ Η ΒΔ επαναφέρθηκε. Αν το snapshot είναι παλιότερου schema τρέξε «php artisan migrate --force», και «php artisan up».');

        return self::SUCCESS;
    }

    /**
     * The mysql client argv (no shell). Public + static so it's unit-testable
     * without a live database.
     *
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    public static function restoreCommand(array $cfg): array
    {
        return [
            'mysql',
            ...self::dbConnectionArgs($cfg),
            (string) ($cfg['database'] ?? ''),
        ];
    }
}
