<?php

namespace Tests\Feature\Etl;

use App\Models\Company;
use App\Models\Customer;
use App\Services\Etl\FirebirdConnectionTester;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

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

    // ------------------------------------------------------- the ΑΦΜ preflight

    /** A stand-in CUSTOMER table shaped like the legacy one. */
    private function fakePdoWithCustomers(array $customers): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE CUSTOMER (CUST_ID INTEGER, AFM TEXT, NAME TEXT)');
        foreach ($customers as [$id, $afm, $name]) {
            $stmt = $pdo->prepare('INSERT INTO CUSTOMER (CUST_ID, AFM, NAME) VALUES (?, ?, ?)');
            $stmt->execute([$id, $afm, $name]);
        }

        return $pdo;
    }

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'probe-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off', 'afm' => '800561849',
        ]);
    }

    public function test_the_probe_warns_about_a_duplicate_afm_inside_the_legacy_source(): void
    {
        $pdo = $this->fakePdoWithCustomers([
            [41, '123456789', 'ΕΤΑΙΡΕΙΑ ΑΕ'],
            [87, 'EL 123 456 789', 'ΥΠΟΚΑΤΑΣΤΗΜΑ'],
            [90, '094123456', 'ΑΛΛΟΣ'],
        ]);

        $result = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p');

        // Connecting is still a success — the ΑΦΜ report rides along with it.
        $this->assertTrue($result->ok);
        $this->assertTrue($result->afmBlocks());
        $this->assertStringContainsString('1 διπλά ΑΦΜ', (string) $result->afmSummary());
        $this->assertStringContainsString('--afm-keep=41', $result->afm->describe());
    }

    public function test_the_probe_is_clean_when_the_source_has_no_duplicate_afm(): void
    {
        $pdo = $this->fakePdoWithCustomers([[1, '123456789', 'A'], [2, '094123456', 'B'], [3, '000000000', 'ΛΙΑΝΙΚΗ']]);

        $result = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertFalse($result->afmBlocks());
        $this->assertNull($result->afmSummary());
        $this->assertNotNull($result->afm, 'the check ran — it just found nothing');
    }

    public function test_the_probe_sees_an_afm_already_held_in_the_target_tenant(): void
    {
        $company = $this->tenant();
        Customer::create(['company_id' => $company->id, 'name' => 'ΧΕΙΡΟΚΙΝΗΤΟΣ', 'afm' => '123456789']);

        $pdo = $this->fakePdoWithCustomers([[55, '123456789', 'ΠΗΓΗ']]);

        $result = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p', (int) $company->id);

        $this->assertTrue($result->afmBlocks());
        $this->assertStringContainsString('κρατά ήδη άλλος πελάτης', (string) $result->afmSummary());
    }

    public function test_the_probe_judges_the_source_under_the_keeper_the_operator_typed(): void
    {
        $pdo = $this->fakePdoWithCustomers([[41, '123456789', 'ΕΤΑΙΡΕΙΑ ΑΕ'], [87, '123456789', 'ΥΠΟΚΑΤΑΣΤΗΜΑ']]);

        $this->assertTrue($this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p')->afmBlocks());

        // …and once «41» is in the form field, a re-test agrees with what the
        // import will actually do instead of repeating the same refusal.
        $resolved = $this->tester($pdo)->test('h', 3050, '/db.fdb', 'u', 'p', null, [41]);

        $this->assertFalse($resolved->afmBlocks());
        $this->assertStringContainsString('επιλυμένα', (string) $resolved->afmSummary());
    }

    public function test_the_probe_stays_silent_when_the_afm_check_cannot_run(): void
    {
        // An older snapshot without the columns must not turn a working
        // connection test into a failure — nor claim a clean bill of health.
        $result = $this->tester($this->fakePdo(['CUSTOMER' => 2, 'INVOICE' => 1]))
            ->test('h', 3050, '/db.fdb', 'u', 'p');

        $this->assertTrue($result->ok);
        $this->assertNull($result->afm);
        $this->assertFalse($result->afmBlocks());
        $this->assertNull($result->afmSummary());
    }
}
