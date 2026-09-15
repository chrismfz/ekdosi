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

        $pwd = ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')];

        // Open the (optionally gz) snapshot FIRST — BEFORE the destructive DROP —
        // so an unreadable/missing dump can never leave the database dropped-and-
        // empty. compress.zlib:// transparently decompresses a .gz with constant memory.
        $isGz = str_ends_with(strtolower($file), '.gz');
        $input = fopen($isGz ? 'compress.zlib://'.$file : $file, 'rb');
        if ($input === false) {
            $this->error('Αδυναμία ανάγνωσης του στιγμιότυπου — δεν έγινε καμία αλλαγή.');

            return self::FAILURE;
        }

        // OPS-7 (step 1): clean slate. DROP + CREATE the TARGET database before
        // loading, so a table left behind by a half-applied migration (whose
        // migrations row this restore rolls back) can't survive to break the next
        // deploy with "table already exists". Doing it here (not baked into the
        // dump via --databases) means it always hits the RESTORE connection's db —
        // matching the confirmation above — and recreates a db that doesn't exist
        // (self-heals an interrupted restore). The dump is DB-agnostic.
        $recreate = new Process(self::recreateCommand($cfg), timeout: null, env: $pwd);
        $recreate->setInput(self::recreateDatabaseSql($cfg));
        $this->warn('Καθαρισμός σχήματος (DROP + CREATE DATABASE)…');
        $recreate->run(fn ($type, $buffer) => $this->output->write($buffer));
        if (! $recreate->isSuccessful()) {
            fclose($input);
            $this->error('Ο καθαρισμός σχήματος απέτυχε: '.trim($recreate->getErrorOutput()));

            return self::FAILURE;
        }

        // Step 2: stream the snapshot into the (now freshly-created) database.
        $process = new Process(self::restoreCommand($cfg), timeout: null, env: $pwd);
        $process->setInput($input);

        $this->warn('Επαναφορά ΒΔ… (μην διακόψεις τη διαδικασία)');
        $process->run(fn ($type, $buffer) => $this->output->write($buffer));

        if (! $process->isSuccessful()) {
            $this->error('Η επαναφορά απέτυχε: '.trim($process->getErrorOutput()));
            // Since the v2.0.2 squash this matters, and it cuts BOTH ways —
            // `mariadb-dump` restores tables ALPHABETICALLY and `migrations`
            // sits mid-alphabet:
            //   • abort BEFORE it  → no migrations table → `migrate` treats the
            //     DB as «never migrated» and re-loads the baseline over the
            //     tables already restored: it aborts loudly («Table ... already
            //     exists») instead of wiping them, but can never succeed.
            //   • abort AFTER it   → `migrations` is fully populated, so
            //     `migrate` prints «Nothing to migrate» and exits 0 on a
            //     database still MISSING every table from `mydata_*` to
            //     `whmcs_*`. The dangerous half: green output, half a schema.
            // Neither is repairable by `migrate`. Only re-running the restore is.
            $this->warn('⚠ Η βάση είναι ΗΜΙΤΕΛΗΣ. ΜΗΝ τρέξεις «php artisan migrate» για να το «φτιάξεις»: ανάλογα '
                .'με το πού κόπηκε η επαναφορά, είτε θα αποτύχει σταθερά, είτε —χειρότερα— θα πει «Nothing to '
                .'migrate» και θα βγει ΕΠΙΤΥΧΩΣ πάνω σε βάση που λείπουν πίνακες. ΠΡΑΣΙΝΟ migrate ΔΕΝ σημαίνει '
                .'ότι η επαναφορά ολοκληρώθηκε. Ξανατρέξε την ΕΠΑΝΑΦΟΡΑ από το ίδιο (ή προηγούμενο) snapshot.');

            return self::FAILURE;
        }

        $this->info('✓ Η ΒΔ επαναφέρθηκε ΠΛΗΡΩΣ. Αν το snapshot είναι παλιότερου schema τρέξε «php artisan migrate --force» (ασφαλές μόνο μετά από ΕΠΙΤΥΧΗ επαναφορά), και «php artisan up».');

        return self::SUCCESS;
    }

    /**
     * `DROP DATABASE IF EXISTS` + `CREATE DATABASE` for the connection's db, so
     * the restore starts from an empty schema. Charset/collation come from the
     * connection config. Public + static so it's unit-testable.
     *
     * @param  array<string, mixed>  $cfg
     */
    public static function recreateDatabaseSql(array $cfg): string
    {
        $db = str_replace('`', '``', (string) ($cfg['database'] ?? ''));
        // Falsy-guard (not ??): env('DB_CHARSET') returns '' when the var is
        // present-but-empty, which would emit a malformed `CHARACTER SET ;`.
        $charset = (string) ($cfg['charset'] ?? '') ?: 'utf8mb4';
        $collation = (string) ($cfg['collation'] ?? '');

        $create = "CREATE DATABASE `{$db}` CHARACTER SET {$charset}";
        if ($collation !== '') {
            $create .= " COLLATE {$collation}";
        }

        return "DROP DATABASE IF EXISTS `{$db}`;\n{$create};\n";
    }

    /**
     * The mysql client argv for step 1 — NO positional db (the DROP/CREATE runs
     * at server scope, and the target db may not exist yet).
     *
     * @param  array<string, mixed>  $cfg
     * @return list<string>
     */
    public static function recreateCommand(array $cfg): array
    {
        return ['mysql', ...self::dbConnectionArgs($cfg)];
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
