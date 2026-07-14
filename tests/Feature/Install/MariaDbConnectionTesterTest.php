<?php

namespace Tests\Feature\Install;

use App\Services\Install\MariaDbConnectionTester;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * The installer's DB probe: connect, then classify the target database as
 * empty (safe) / migrated-but-no-admin (safe) / already-installed (refuse), or
 * a classified connection failure. Driven by an injected PDO factory (sqlite
 * stand-in) so the probe + error mapping run without a real MariaDB server.
 */
class MariaDbConnectionTesterTest extends TestCase
{
    /** A sqlite PDO standing in for MariaDB. Pass a `users` row count, or null for «no users table». */
    private function fakePdo(?int $userRows): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        if ($userRows !== null) {
            $pdo->exec('CREATE TABLE users (id INTEGER)');
            for ($i = 0; $i < $userRows; $i++) {
                $pdo->exec("INSERT INTO users (id) VALUES ({$i})");
            }
        }

        return $pdo;
    }

    private function tester(PDO|PDOException $result): MariaDbConnectionTester
    {
        return new MariaDbConnectionTester(function (string $dsn, string $user, string $pw) use ($result): PDO {
            if ($result instanceof PDOException) {
                throw $result;
            }

            return $result;
        });
    }

    public function test_empty_database_is_ok_and_not_flagged_as_schema(): void
    {
        $result = $this->tester($this->fakePdo(null))->test('127.0.0.1', 3306, 'ekdosi', 'u', 'p');

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
        $this->assertFalse($result->hasSchema);
        $this->assertFalse($result->alreadyInstalled);
    }

    public function test_migrated_but_no_admin_is_safe_but_flagged_as_schema(): void
    {
        $result = $this->tester($this->fakePdo(0))->test('127.0.0.1', 3306, 'ekdosi', 'u', 'p');

        $this->assertTrue($result->ok);
        $this->assertTrue($result->hasSchema);
        $this->assertFalse($result->alreadyInstalled);
    }

    public function test_existing_admin_is_refused(): void
    {
        $result = $this->tester($this->fakePdo(1))->test('127.0.0.1', 3306, 'ekdosi', 'u', 'p');

        $this->assertFalse($result->ok);
        $this->assertSame('already_installed', $result->reason);
        $this->assertTrue($result->alreadyInstalled);
    }

    public function test_classifies_auth_failure(): void
    {
        // Classified by the message (access denied) — MySQL's 1045.
        $result = $this->tester(new PDOException('SQLSTATE[HY000] [1045] Access denied for user'))
            ->test('h', 3306, 'db', 'u', 'bad');

        $this->assertFalse($result->ok);
        $this->assertSame('auth', $result->reason);
    }

    public function test_classifies_unknown_database(): void
    {
        $result = $this->tester(new PDOException("SQLSTATE[HY000] [1049] Unknown database 'nope'"))
            ->test('h', 3306, 'nope', 'u', 'p');

        $this->assertSame('unknown_database', $result->reason);
    }

    public function test_classifies_unreachable(): void
    {
        $result = $this->tester(new PDOException("SQLSTATE[HY000] [2002] Can't connect to MySQL server"))
            ->test('h', 3306, 'db', 'u', 'p');

        $this->assertSame('unreachable', $result->reason);
    }

    public function test_classifies_missing_driver(): void
    {
        $result = $this->tester(new PDOException('could not find driver'))
            ->test('h', 3306, 'db', 'u', 'p');

        $this->assertSame('driver_missing', $result->reason);
    }

    public function test_dsn_shape(): void
    {
        $t = new MariaDbConnectionTester;

        $this->assertSame('mysql:host=127.0.0.1;port=3306;dbname=ekdosi;charset=utf8mb4', $t->dsn('127.0.0.1', 3306, 'ekdosi'));
        $this->assertSame('mysql:host=db.host;port=3307;dbname=x;charset=utf8mb4', $t->dsn('db.host', 3307, 'x'));
        // Zero/invalid port folds to the default.
        $this->assertSame('mysql:host=h;port=3306;dbname=x;charset=utf8mb4', $t->dsn('h', 0, 'x'));
    }
}
