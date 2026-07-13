<?php

namespace Tests\Feature\Etl;

use App\Services\Etl\FirebirdConnectionTester;
use PDO;
use PDOException;
use Tests\TestCase;

/**
 * The «Έλεγχος σύνδεσης» diagnostic: connect + count core legacy tables, or a
 * classified failure. Exercised with an injected PDO factory (sqlite stand-in)
 * so the probe + error mapping run without a real Firebird server.
 */
class FirebirdConnectionTesterTest extends TestCase
{
    /** A sqlite PDO standing in for the Firebird connection, with the given legacy tables. */
    private function fakePdo(array $tablesWithRows): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($tablesWithRows as $table => $rows) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER)");
            for ($i = 0; $i < $rows; $i++) {
                $pdo->exec("INSERT INTO {$table} (id) VALUES ({$i})");
            }
        }

        return $pdo;
    }

    private function tester(PDO|PDOException $result): FirebirdConnectionTester
    {
        return new FirebirdConnectionTester(function (string $dsn) use ($result): PDO {
            if ($result instanceof PDOException) {
                throw $result;
            }

            return $result;
        });
    }

    public function test_connects_and_counts_core_tables(): void
    {
        $pdo = $this->fakePdo(['CUSTOMER' => 3, 'INVTYPE' => 2, 'INVOICE' => 5, 'PRODUCT' => 4]);

        $result = $this->tester($pdo)->test('10.0.0.5', 3050, '/db/ekdosi.fdb', 'EKDOSI', 'pw');

        $this->assertTrue($result->ok);
        $this->assertSame('ok', $result->reason);
        $this->assertSame(3, $result->counts['CUSTOMER']);
        $this->assertSame(5, $result->counts['INVOICE']);
        $this->assertSame([], $result->missing);
    }

    public function test_reports_missing_tables_but_still_ok_if_some_exist(): void
    {
        $pdo = $this->fakePdo(['CUSTOMER' => 1, 'INVTYPE' => 1]);   // no INVOICE/PRODUCT

        $result = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertTrue($result->ok);
        $this->assertEqualsCanonicalizing(['INVOICE', 'PRODUCT'], $result->missing);
    }

    public function test_no_tables_is_a_failure(): void
    {
        $pdo = $this->fakePdo(['SOMETHING_ELSE' => 1]);   // none of the expected tables

        $result = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertFalse($result->ok);
        $this->assertSame('no_tables', $result->reason);
    }

    public function test_classifies_auth_failure(): void
    {
        $result = $this->tester(new PDOException('Your user name and password are not defined'))
            ->test('h', 3050, '/db.fdb', 'u', 'bad');

        $this->assertFalse($result->ok);
        $this->assertSame('auth', $result->reason);
    }

    public function test_classifies_unreachable(): void
    {
        $result = $this->tester(new PDOException('Unable to complete network request to host. Connection refused'))
            ->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertSame('unreachable', $result->reason);
    }

    public function test_classifies_missing_driver(): void
    {
        $result = $this->tester(new PDOException('could not find driver'))
            ->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertSame('driver_missing', $result->reason);
    }

    public function test_dsn_folds_non_default_port(): void
    {
        $t = new FirebirdConnectionTester;

        $this->assertSame('firebird:dbname=10.0.0.5:/db.fdb;charset=UTF8', $t->dsn('10.0.0.5', 3050, '/db.fdb'));
        $this->assertSame('firebird:dbname=10.0.0.5/3051:/db.fdb;charset=UTF8', $t->dsn('10.0.0.5', 3051, '/db.fdb'));
    }
}
