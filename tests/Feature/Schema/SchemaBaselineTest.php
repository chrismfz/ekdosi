<?php

namespace Tests\Feature\Schema;

use Illuminate\Foundation\Testing\WithConsoleEvents;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards the squashed schema baseline (`database/schema/*-schema.sql`, v2.0.2).
 *
 * These assert on the COMMITTED dump files, not on a live database: the whole
 * point is to catch a future `php artisan schema:dump` whose raw output would
 * silently reintroduce a footgun the squash review already removed.
 */
class SchemaBaselineTest extends TestCase
{
    // Laravel skips re-routing the Symfony console events while
    // runningUnitTests() (Kernel::__construct), so CommandStarting — and with
    // it the AppServiceProvider guard below — never fires in the suite unless
    // we opt back in. Without this the guard test would pass vacuously.
    use WithConsoleEvents;

    /** Throwaway migration written to disk by the guard test below; never committed. */
    private const PROBE_MIGRATION = '9999_12_31_000000_schema_baseline_guard_probe';

    private function baseline(string $connection): string
    {
        $path = database_path("schema/{$connection}-schema.sql");
        $this->assertFileExists($path, "the {$connection} schema baseline must be committed");

        return (string) file_get_contents($path);
    }

    /**
     * THE data-loss guard. `MigrateCommand::prepareDatabase()` loads the
     * baseline whenever `hasRunAnyMigrations()` is false — i.e. whenever the
     * `migrations` table is missing or empty, REGARDLESS of whether the data
     * tables exist. A DB left half-restored by a failed `ekdosi:db-restore`
     * (data tables recovered, `migrations` not yet) therefore meets that
     * condition, and `deploy/update.sh` runs `php artisan migrate --force` on
     * every deploy.
     *
     * With `DROP TABLE IF EXISTS` in the dump that combination silently drops
     * every table and reports success. Without it the first `CREATE TABLE`
     * fails with «Table ... already exists», migrate aborts non-zero and the
     * data survives — verified against a real MariaDB before this was written.
     *
     * `mysqldump`/`mariadb-dump` emit those DROPs by default, so a plain
     * regeneration WILL put them back: strip them again before committing.
     */
    public function test_the_mariadb_baseline_never_drops_tables(): void
    {
        $sql = $this->baseline('mariadb');

        $this->assertStringNotContainsStringIgnoringCase(
            'DROP TABLE',
            $sql,
            'database/schema/mariadb-schema.sql must NOT contain DROP TABLE: on a database that has '
            .'data tables but an empty/missing `migrations` table (a half-finished restore), '
            .'`php artisan migrate --force` would load this dump and silently destroy it. '
            .'Re-strip them after any `schema:dump` (see docs/BACKLOG.md).'
        );

        // Sanity: the file is still a real schema, not an empty/truncated one.
        $this->assertGreaterThan(100, substr_count($sql, 'CREATE TABLE'));
    }

    /**
     * The sqlite baseline is non-destructive for the same reason — keep it that
     * way.
     *
     * NOTE the guarantee here is WEAKER than the MariaDB one above, and
     * deliberately so. `schema:dump` emits sqlite's 105 statements as
     * `CREATE TABLE IF NOT EXISTS`, so on a sqlite DB with data tables but an
     * empty `migrations` table every CREATE silently no-ops, the migration rows
     * are inserted anyway and `migrate` reports success — the DB gets MARKED
     * fully migrated while its schema may be stale. Where MariaDB fails
     * non-zero, sqlite records a lie. We accept that: sqlite is the test/CI
     * connection only (prod is MariaDB — see CLAUDE.md «Stack»), it is always
     * built fresh, and stripping the IF NOT EXISTS would be re-added by the
     * next `schema:dump` for no real-world gain. So this test asserts only «no
     * DROP TABLE», NOT «aborts loudly on a populated DB».
     */
    public function test_the_sqlite_baseline_never_drops_tables(): void
    {
        $this->assertStringNotContainsStringIgnoringCase('DROP TABLE', $this->baseline('sqlite'));
    }

    /**
     * Both engines must record the SAME migration set, or a fresh install on
     * one of them would later re-run (or skip) migrations the other already
     * has. Guards a half-refreshed regeneration (dumping only one engine).
     */
    public function test_both_baselines_record_the_same_migrations(): void
    {
        preg_match_all(
            "/INSERT INTO `migrations`[^;]*VALUES \(\d+,'([^']+)',\d+\);/",
            $this->baseline('mariadb'),
            $mariadb
        );
        preg_match_all(
            "/INSERT INTO migrations VALUES\(\d+,'([^']+)',\d+\);/",
            $this->baseline('sqlite'),
            $sqlite
        );

        $this->assertNotEmpty($mariadb[1], 'the mariadb baseline must carry its migration rows');
        $this->assertNotEmpty($sqlite[1], 'the sqlite baseline must carry its migration rows');

        sort($mariadb[1]);
        sort($sqlite[1]);
        $this->assertSame($mariadb[1], $sqlite[1], 'the two baselines record different migrations — regenerate BOTH');
    }

    /**
     * Laravel resolves the baseline by CONNECTION NAME, not driver, and
     * `loadSchemaState()` returns SILENTLY when no file matches. On a
     * connection we ship no baseline for, `migrate` would print «Nothing to
     * migrate» (or apply only the post-squash deltas), exit 0 and leave the
     * database EMPTY — a broken install that looks healthy. AppServiceProvider
     * refuses instead.
     */
    public function test_migrate_refuses_a_connection_with_no_baseline(): void
    {
        $this->assertFileDoesNotExist(database_path('schema/pgsql-schema.sql'));

        $this->expectException(RuntimeException::class);
        // Asserting the MESSAGE matters: without the guard this same call still
        // throws — but a «could not find driver» PDO error, which would let a
        // regressed guard pass a bare expectException.
        $this->expectExceptionMessageMatches('/Καμία πηγή schema/u');

        Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
    }

    /**
     * The regression this guard is one condition away from: keying it on «and
     * database/migrations/ is empty too» makes the FIRST new migration on top
     * of the baseline silence it permanently — the operator then gets an
     * exit-0 database holding that one delta and nothing else. The repo's
     * whole stated workflow is «new migrations on top of the baseline», so
     * that day is coming. Pin the behaviour with a real migration file on
     * disk.
     */
    public function test_the_no_baseline_guard_survives_a_post_squash_migration(): void
    {
        $file = database_path('migrations/'.self::PROBE_MIGRATION.'.php');

        // BEFORE writing: a probe file already sitting here leaked from a
        // crashed run (`finally` covers a throw, not a SIGKILL/fatal) and may
        // even have been committed — on a fresh CI checkout this is where that
        // shows up. Asserting AFTER, or in a later test, cannot catch it: this
        // test unlinks the file, and PHPUnit runs in declaration order.
        $this->assertFileDoesNotExist($file, 'a guard-probe migration leaked from an earlier run — delete it');

        file_put_contents($file, "<?php\n\nreturn new class extends \\Illuminate\\Database\\Migrations\\Migration {};\n");

        // NOTE: catch ONLY around the Artisan call, and assert afterwards.
        // Wrapping the assertions too would swallow them — PHPUnit's
        // AssertionFailedError IS a RuntimeException, so a `fail()` inside the
        // try lands in our own catch and gets re-reported as a bogus message
        // mismatch instead of the real diagnostic.
        $thrown = null;

        try {
            $this->assertNotEmpty(glob(database_path('migrations/*.php')), 'the probe migration must be on disk');

            Artisan::call('migrate', ['--database' => 'pgsql', '--force' => true]);
        } catch (RuntimeException $e) {
            $thrown = $e;
        } finally {
            @unlink($file);
        }

        $this->assertNotNull($thrown, 'migrate on a baseline-less connection must still be refused once migrations exist');
        $this->assertMatchesRegularExpression('/Καμία πηγή schema/u', $thrown->getMessage());
    }

    /** The connections we DO ship a baseline for must not trip that guard. */
    public function test_the_shipped_connections_have_a_baseline(): void
    {
        foreach (['sqlite', 'mariadb'] as $connection) {
            $this->assertFileExists(database_path("schema/{$connection}-schema.sql"));
        }
    }

    /**
     * Anything still living in `database/migrations/` must be NEWER than the
     * baseline, otherwise it is already inside the dump and would either be
     * skipped (harmless) or re-applied to a fresh install (not harmless).
     */
    public function test_no_committed_migration_is_already_inside_the_baseline(): void
    {
        preg_match_all(
            "/INSERT INTO `migrations`[^;]*VALUES \(\d+,'([^']+)',\d+\);/",
            $this->baseline('mariadb'),
            $matches
        );
        $inBaseline = array_flip($matches[1]);

        foreach (glob(database_path('migrations/*.php')) as $file) {
            $name = basename($file, '.php');
            $this->assertArrayNotHasKey(
                $name,
                $inBaseline,
                "{$name} is already recorded in the schema baseline — it must not also exist as a migration file"
            );
        }
    }
}
