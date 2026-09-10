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
 * It also runs the ΑΦΜ preflight (LegacyAfmConflicts) when the CUSTOMER table is
 * readable, so «δύο πελάτες με το ίδιο ΑΦΜ» surfaces HERE — before a gbak restore
 * and an import that would refuse — instead of minutes later. Same rule, same
 * answer as the import itself.
 *
 * Never writes — only SELECTs (the legacy database stays a pristine archive). The
 * PDO factory is injectable so the probe/error-mapping is unit-testable without a
 * real Firebird server.
 */
class FirebirdConnectionTester
{
    /** Core legacy tables the ETL reads — presence + count confirms it's the right DB. */
    public const PROBE_TABLES = ['CUSTOMER', 'INVTYPE', 'INVOICE', 'PRODUCT'];

    /**
     * Above this many customers the ΑΦΜ probe is skipped (see afmReport). The
     * real tenants are in the low thousands, so the cap is never reached in
     * practice — it exists so an unexpectedly huge/remote source degrades to
     * «unknown» instead of hanging the panel.
     */
    public const AFM_PROBE_MAX_ROWS = 50000;

    /** @var (Closure(string): PDO)|null */
    private $connectionFactory;

    /**
     * @param  (Closure(string): PDO)|null  $connectionFactory  test seam: given the DSN, return a PDO
     */
    public function __construct(?Closure $connectionFactory = null)
    {
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * @param  int|null  $companyId  target tenant — enables the «already held in
     *                               ekdosi» half of the ΑΦΜ check. Null checks only
     *                               the duplicates INSIDE the legacy source.
     * @param  list<int>  $afmKeep  the CUST_IDs the operator already decided keep
     *                              their ΑΦΜ, so a probe re-run after filling the
     *                              field agrees with what the import will do.
     */
    public function test(string $host, int $port, string $database, string $user, string $password, ?int $companyId = null, array $afmKeep = []): FirebirdProbeResult
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

        return $this->probe($pdo, $companyId, $afmKeep);
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

    private function probe(PDO $pdo, ?int $companyId = null, array $afmKeep = []): FirebirdProbeResult
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

        return FirebirdProbeResult::success($counts, $missing, $this->afmReport($pdo, $companyId, $counts['CUSTOMER'] ?? null, $afmKeep));
    }

    /**
     * The ΑΦΜ conflict report, or null when it could not be established — an older
     * `.fbk` without one of the columns, or no read rights. Never turns a working
     * connection test into a failure: the authoritative check is the import's own
     * guard, this is the early warning.
     */
    private function afmReport(PDO $pdo, ?int $companyId, ?int $customerCount, array $afmKeep = []): ?LegacyAfmConflictReport
    {
        // This runs inside a synchronous Livewire request, so it reads the whole
        // CUSTOMER table exactly once and only while that is cheap. Past the cap
        // the answer is «not established» (the import's own guard still runs on
        // every row) rather than a probe that times out and reports a healthy
        // connection as broken.
        if ($customerCount === null || $customerCount > self::AFM_PROBE_MAX_ROWS) {
            return null;
        }

        try {
            $rows = $pdo->query('SELECT CUST_ID, AFM, NAME FROM CUSTOMER')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return null;
        }

        $conflicts = app(LegacyAfmConflicts::class);

        return $conflicts->find(
            $conflicts->mapLegacyRows($rows, fn (array $r, string $k): ?string => $this->clean($r[$k] ?? null)),
            $companyId,
            $afmKeep,
        );
    }

    /** Drop any stray non-UTF-8 byte (the WIN1253 source is transliterated on read). */
    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) iconv('UTF-8', 'UTF-8//IGNORE', $value));

        return $value === '' ? null : $value;
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
