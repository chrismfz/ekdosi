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
     * Connected → decide whether it's safe to install into. We probe the one
     * table every ekdosi install has: `users`.
     *  - query throws  → no `users` table → empty/fresh DB → safe.
     *  - 0 rows        → migrated but no admin → still safe (idempotent retry).
     *  - ≥1 row        → a finished install → REFUSE (won't overwrite).
     *
     * A plain `SELECT COUNT(*) FROM users` (not information_schema) keeps the
     * probe portable to the sqlite stand-in used in tests.
     */
    private function probe(PDO $pdo): MariaDbProbeResult
    {
        try {
            $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        } catch (Throwable) {
            return MariaDbProbeResult::success(hasSchema: false);
        }

        return $count > 0
            ? MariaDbProbeResult::alreadyInstalled()
            : MariaDbProbeResult::success(hasSchema: true);
    }

    private function classify(Throwable $e): string
    {
        $m = strtolower($e->getMessage());
        $code = $e->getCode();

        return match (true) {
            str_contains($m, 'could not find driver') => 'driver_missing',
            // MySQL 1045 = access denied (bad user/password).
            $code === 1045 || str_contains($m, 'access denied') => 'auth',
            // 1049 = unknown database.
            $code === 1049 || str_contains($m, 'unknown database') => 'unknown_database',
            // 2002/2003 = can't connect / host unreachable.
            $code === 2002 || $code === 2003
                || str_contains($m, "can't connect") || str_contains($m, 'connection refused')
                || str_contains($m, 'timed out') || str_contains($m, 'no such host')
                || str_contains($m, 'unknown mysql server host') => 'unreachable',
            default => 'error',
        };
    }
}
