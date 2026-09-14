<?php

namespace Tests\Feature\Schema;

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

    /** The sqlite baseline is non-destructive for the same reason — keep it that way. */
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
