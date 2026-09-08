<?php

namespace App\Services\Etl;

use Closure;
use PDO;
use Throwable;

/**
 * READ-ONLY «Έλεγχος σύνδεσης» for a LIVE Firebird database — the diagnostic
 * behind the import form's «Ζωντανή σύνδεση» tab. Connects with the SAME DSN
 * shape the ETL uses (`firebird:dbname=HOST[/PORT]:PATH;charset=UTF8`) and probes
 * a handful of core legacy tables so the operator sees «✅ 1.240 πελάτες, 8.900
 * τιμολόγια» (or a precise error) BEFORE committing to an import.
 *
 * Never writes — only SELECT COUNT(*). The PDO factory is injectable so the
 * probe/error-mapping is unit-testable without a real Firebird server.
 */
class FirebirdConnectionTester
{
    /** Core legacy tables the ETL reads — presence + count confirms it's the right DB. */
    public const PROBE_TABLES = ['CUSTOMER', 'INVTYPE', 'INVOICE', 'PRODUCT'];

    /** @var (Closure(string): PDO)|null */
    private $connectionFactory;

    /**
     * @param  (Closure(string): PDO)|null  $connectionFactory  test seam: given the DSN, return a PDO
     */
    public function __construct(?Closure $connectionFactory = null)
    {
        $this->connectionFactory = $connectionFactory;
    }

    public function test(string $host, int $port, string $database, string $user, string $password): FirebirdProbeResult
    {
        if ($this->connectionFactory === null && ! extension_loaded('pdo_firebird')) {
            return FirebirdProbeResult::failure('driver_missing',
                'Το pdo_firebird δεν είναι εγκατεστημένο σε αυτόν τον διακομιστή. Χρειάζεται για σύνδεση σε Firebird (δες INSTALL.md / CLAUDE.md env-prep).');
        }

        try {
            $pdo = $this->connect($this->dsn($host, $port, $database), $user, $password);
        } catch (Throwable $e) {
            return FirebirdProbeResult::failure($this->classify($e), $e->getMessage());
        }

        return $this->probe($pdo);
    }

    /** `firebird:dbname=HOST:PATH` (default port) or `HOST/PORT:PATH`. */
    public function dsn(string $host, int $port, string $database): string
    {
        $server = ($port > 0 && $port !== 3050) ? "{$host}/{$port}" : $host;

        return sprintf('firebird:dbname=%s:%s;charset=UTF8', $server, $database);
    }

    private function connect(string $dsn, string $user, string $password): PDO
    {
        if ($this->connectionFactory !== null) {
            return ($this->connectionFactory)($dsn);
        }

        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    private function probe(PDO $pdo): FirebirdProbeResult
    {
        $counts = [];
        $missing = [];

        foreach (self::PROBE_TABLES as $table) {
            try {
                $n = $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
                $counts[$table] = (int) $n;
            } catch (Throwable) {
                // Table absent (or no read rights) — record it, keep probing the rest.
                $missing[] = $table;
            }
        }

        if ($counts === []) {
            return FirebirdProbeResult::failure('no_tables',
                'Συνδέθηκε, αλλά δεν διαβάστηκε κανένας από τους αναμενόμενους legacy πίνακες ('
                .implode(', ', self::PROBE_TABLES).'). Πιθανή αιτία: λάθος διαδρομή .fdb, κενή/άσχετη βάση, '
                .'ή ο χρήστης δεν έχει δικαίωμα ανάγνωσης σε αυτούς τους πίνακες.');
        }

        return FirebirdProbeResult::success($counts, $missing);
    }

    private function classify(Throwable $e): string
    {
        $m = strtolower($e->getMessage());

        return match (true) {
            str_contains($m, 'could not find driver') => 'driver_missing',
            str_contains($m, 'password') || str_contains($m, 'login') || str_contains($m, 'user name') => 'auth',
            str_contains($m, 'unavailable') || str_contains($m, 'network')
                || str_contains($m, 'connection') || str_contains($m, 'refused')
                || str_contains($m, 'unknown') || str_contains($m, 'no route') => 'unreachable',
            default => 'error',
        };
    }
}
