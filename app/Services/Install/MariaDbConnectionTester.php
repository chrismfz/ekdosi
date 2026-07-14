<?php

namespace App\Services\Install;

use App\Services\Etl\FirebirdConnectionTester;
use Closure;
use PDO;
use Throwable;

/**
 * READ-ONLY connectivity + safety probe for the target MariaDB, behind the web
 * installer's «Δοκιμή σύνδεσης» button (and re-used server-side at the migrate
 * step as the final guard). Mirrors {@see FirebirdConnectionTester}:
 * a plain PDO connect with an INJECTABLE factory so the probe + error-mapping
 * are unit-testable without a live server.
 *
 * It never writes. Beyond «did we connect», it answers the one question the
 * installer must not get wrong: is this database SAFE to install into, or does
 * it already hold a finished ekdosi install we'd clobber? «Finished» = a
 * super-admin user exists; a merely-migrated DB (tables but no admin) is still
 * safe because both `migrate` and `ekdosi:install` are idempotent.
 */
class MariaDbConnectionTester
{
    /** @var (Closure(string, string, string): PDO)|null test seam: (dsn, user, password) → PDO */
    private $connectionFactory;

    /**
     * @param  (Closure(string, string, string): PDO)|null  $connectionFactory
     */
    public function __construct(?Closure $connectionFactory = null)
    {
        $this->connectionFactory = $connectionFactory;
    }

    public function test(string $host, int $port, string $database, string $user, string $password): MariaDbProbeResult
    {
        if ($this->connectionFactory === null && ! extension_loaded('pdo_mysql')) {
            return MariaDbProbeResult::failure('driver_missing',
                'Το pdo_mysql δεν είναι εγκατεστημένο σε αυτόν τον διακομιστή. Χρειάζεται για σύνδεση σε MariaDB/MySQL.');
        }

        try {
            $pdo = $this->connect($this->dsn($host, $port, $database), $user, $password);
        } catch (Throwable $e) {
            return MariaDbProbeResult::failure($this->classify($e), $e->getMessage());
        }

        return $this->probe($pdo);
    }

    /** `mysql:host=HOST;port=PORT;dbname=DB;charset=utf8mb4`. */
    public function dsn(string $host, int $port, string $database): string
    {
        $port = $port > 0 ? $port : 3306;

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);
    }

    private function connect(string $dsn, string $user, string $password): PDO
    {
        if ($this->connectionFactory !== null) {
            return ($this->connectionFactory)($dsn, $user, $password);
        }

        return new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
    }

    /**
     * Connected → decide whether it's safe to install into.
     *  - 0 tables     → EMPTY → safe to auto-proceed.
     *  - ≥1 table     → NON-EMPTY → require the operator's override. This catches
     *                   a foreign DB (WHMCS, another app) — NOT just another
     *                   Laravel install — which the old `users`-only check
     *                   reported as «empty» and would have migrated into.
     *  - ≥1 table AND ≥1 `users` row → an existing ekdosi admin → distinct
     *                   «already installed» message (still overridable to finish
     *                   a partial attempt).
     */
    private function probe(PDO $pdo): MariaDbProbeResult
    {
        $tableCount = $this->countTables($pdo);

        if ($tableCount === 0) {
            return MariaDbProbeResult::emptyDatabase();
        }

        // Non-empty. Does it already carry an ekdosi admin?
        try {
            $userRows = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (Throwable) {
            $userRows = 0;   // no `users` table → a foreign, non-ekdosi schema
        }

        return $userRows > 0
            ? MariaDbProbeResult::alreadyInstalled($tableCount)
            : MariaDbProbeResult::nonEmpty($tableCount);
    }

    /**
     * Count tables in the CURRENT database. MariaDB/MySQL via information_schema;
     * falls back to sqlite_master so the probe stays portable to the sqlite
     * stand-in used in tests. 0 = a truly empty database.
     */
    private function countTables(PDO $pdo): int
    {
        $queries = [
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()',
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        ];

        foreach ($queries as $sql) {
            try {
                return (int) $pdo->query($sql)->fetchColumn();
            } catch (Throwable) {
                continue;
            }
        }

        return 0;
    }

    private function classify(Throwable $e): string
    {
        $m = strtolower($e->getMessage());
        // The real MySQL driver code lives in errorInfo[1] (getCode() returns the
        // SQLSTATE string, so numeric comparisons on it never match). Fall back to
        // message substrings when errorInfo is absent (e.g. a hand-built exception).
        $driverCode = ($e instanceof \PDOException && is_array($e->errorInfo ?? null))
            ? ($e->errorInfo[1] ?? null)
            : null;

        return match (true) {
            str_contains($m, 'could not find driver') => 'driver_missing',
            $driverCode === 1045 || str_contains($m, 'access denied') => 'auth',
            $driverCode === 1049 || str_contains($m, 'unknown database') => 'unknown_database',
            in_array($driverCode, [2002, 2003], true)
                || str_contains($m, "can't connect") || str_contains($m, 'connection refused')
                || str_contains($m, 'timed out') || str_contains($m, 'no such host')
                || str_contains($m, 'unknown mysql server host') => 'unreachable',
            default => 'error',
        };
    }
}
