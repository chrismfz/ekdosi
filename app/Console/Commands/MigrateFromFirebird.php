<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\Etl\BackupNoteSync;
use App\Services\Etl\LegacyAfmConflictReport;
use App\Services\Etl\LegacyAfmConflicts;
use App\Services\Etl\TenantRowUpserter;
use App\Services\TenantRoleProvisioner;
use App\Support\Afm;
use App\Support\DocumentSeries;
use App\Support\FiledSeriesBackfill;
use App\Support\IsoCountry;
use App\Support\MyData\Codes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Re-runnable ETL: legacy Firebird .fdb  ->  multi-tenant MariaDB.
 *
 * Usage:
 *   php artisan migrate:firebird \
 *       --company="MyIP" --slug=myip \
 *       --fdb="/opt/Data/ekdosi-myip.fdb" \
 *       --host=10.23.22.5 --fbuser=EKDOSI --fbpass=<FB_PASSWORD>
 *
 * Re-run-safe (PR #29): the new design upserts on (company_id, legacy_id).
 * Rows the operator created entirely in Filament (no legacy_id) are never
 * touched. Legacy-imported rows keep their surrogate id across runs;
 * legacy-sourced columns refresh every run; Filament-managed columns
 * (is_active, whmcs_client_id, peppol_endpoint, ...) survive untouched.
 *
 * Charset: the source DB is declared WIN1253. We connect with charset=UTF8 so the
 * Firebird client transliterates on read (UTF8 is a superset -> no transliteration
 * errors). clean() is a final safety net that drops any stray invalid byte.
 */
class MigrateFromFirebird extends Command
{
    protected $signature = 'migrate:firebird
        {--company= : Display name of the company/tenant (ignored if --company-id is set)}
        {--slug= : URL slug for the tenant (ignored if --company-id is set)}
        {--company-id= : Existing tenant id to import INTO. UI-driven imports use this — no implicit create.}
        {--fdb= : Absolute path to the .fdb on the Firebird host}
        {--host=127.0.0.1 : Firebird host}
        {--fbuser=EKDOSI : Firebird user}
        {--fbpass= : Firebird password}
        {--counts-out= : Optional path. If set, the per-table row-count summary is written here as JSON on success.}
        {--afm-keep=* : CUST_ID που κρατά το ΑΦΜ όταν δύο legacy πελάτες το μοιράζονται (π.χ. έδρα vs υποκατάστημα). Επαναλαμβανόμενο, ένα ανά διπλό ΑΦΜ. Οι υπόλοιποι μπαίνουν χωρίς ταυτότητα ΑΦΜ.}
        {--dry-run : READ-ONLY preflight: ελέγχει μόνο τις συγκρούσεις ΑΦΜ πελατών και βγαίνει. Δεν γράφει τίποτα και δεν δημιουργεί εταιρεία.}';

    protected $description = 'Import a legacy Firebird ekdosi database into the multi-tenant MariaDB schema (re-run-safe)';

    private PDO $fb;

    private int $companyId;

    private TenantRowUpserter $upserter;

    /** legacy_id => new_id maps, per table, for FK remapping */
    private array $map = [
        'payment_methods' => [],
        'delivery_methods' => [],
        'distribution_aims' => [],
        'metric_units' => [],
        'vat_categories' => [],
        'product_categories' => [],
        'customers' => [],
        'invoice_types' => [],
        'products' => [],
        'invoices' => [],
        'invoice_lines' => [],
    ];

    public function handle(): int
    {
        // Two entry shapes:
        //   - Operator-typed CLI (legacy):   --company= --slug= (creates if missing)
        //   - UI-driven (PR #30 job):        --company-id= (must already exist)
        // --fdb and --fbpass are always required.
        $useCompanyId = (bool) $this->option('company-id');
        // A dry run creates nothing, so it needs no tenant identity at all — just
        // the source. (`--slug`, if given, adds the ekdosi-side half of the check.)
        $required = ($useCompanyId || $this->option('dry-run'))
            ? ['fdb', 'fbpass']
            : ['company', 'slug', 'fdb', 'fbpass'];
        foreach ($required as $req) {
            if (! $this->option($req)) {
                $this->error("Missing required --{$req}");

                return self::FAILURE;
            }
        }

        $this->connectFirebird();

        // READ-ONLY preflight — before anything is created or written. Deliberately
        // BEFORE resolveCompany(): a dry run must not conjure a tenant as a side
        // effect (and an unknown slug simply means «nothing local to collide with»).
        if ($this->option('dry-run')) {
            return $this->dryRunAfmCheck($useCompanyId);
        }

        $this->companyId = $useCompanyId
            ? $this->resolveCompanyById((int) $this->option('company-id'))
            : $this->resolveCompany();
        $this->upserter = TenantRowUpserter::default();

        $this->info("Importing into company_id={$this->companyId} ({$this->option('slug')})");

        DB::transaction(function () {
            // PR #29: re-run safety via upsert-on-(company_id, legacy_id).
            //
            //   - Rows matched by legacy_id keep their surrogate id
            //     across runs (FKs from ekdosi-only rows stay valid).
            //   - Legacy-sourced columns refresh on every run.
            //   - Filament-managed columns (is_active, whmcs_client_id,
            //     etc.) are written ONLY on first insert; re-runs leave
            //     them alone so operator customisation survives.
            //   - Rows the operator created entirely in Filament (no
            //     legacy_id) are never touched — no Firebird row matches
            //     them.
            //   - Rows deleted from the legacy source on day N+1 are
            //     LEFT ALONE per the locked-in deletion policy
            //     (CLAUDE.md PR #29). A future cleanup command can
            //     offer to drop them.
            //
            // This replaces the previous wipeCompany() + snapshotManualEdits()
            // dance which only preserved a few customer columns and
            // destroyed every Filament-only row.

            // --- lookups (no inter-dependencies among these) ---
            $this->copyLookup('PAYMENT_METHOD', 'payment_methods', 'METHOD_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
                'due_days' => $r['DUE_DAYS'],
            ], naturalKey: 'description');
            $this->copyLookup('DELIVERY_METHOD', 'delivery_methods', 'METHOD_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
            ], naturalKey: 'description');
            $this->copyLookup('DISTRIBUTION_AIM', 'distribution_aims', 'DISTAIM_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
            ], naturalKey: 'description');
            $this->copyLookup('METRIC_UNITS', 'metric_units', 'METRIC_ID', fn ($r) => [
                'name' => $this->fld($r, 'NAME'),
                'notes' => $this->fld($r, 'NOTES'),
            ], naturalKey: 'name');
            $this->copyLookup('VAT_CATEGORY', 'vat_categories', 'VATCAT_ID', fn ($r) => [
                'rate' => $r['VALUE'],
                'description' => $this->fld($r, 'DESCRIPTION'),
                'long_description' => $this->fld($r, 'LONG_DESCRIPTION'),
                'is_default' => (bool) $r['DEFAULT_CAT'],
            ], naturalKey: 'rate');

            // Legacy schema enforced single-default VAT via the VAT_CATEGORY_AU0
            // trigger (Firebird AFTER UPDATE: demoted every other row when one
            // became default). MariaDB has no equivalent enforcement yet — the
            // proper VatCategory model observer ships with the Filament lookup
            // resources (see CLAUDE.md "Audit findings — 2026-05-26"). Until
            // then, demote duplicate defaults at import time so the freshly-
            // populated table doesn't violate the invariant on day one.
            $this->demoteDuplicateVatDefaults();

            $this->copyLookup('PRODUCT_CATEGORIES', 'product_categories', 'CAT_ID', fn ($r) => [
                'description_short' => $this->fld($r, 'DESCRIPTION_SHORT'),
                'description' => $this->fld($r, 'DESCRIPTION'),
                'markup' => $r['MARKUP'],
            ], naturalKey: 'description_short');

            // --- customers (FK: payment_method) ---
            $this->copyCustomers();

            // --- invoice types (FK: aim/delivery/payment/customer) + carries the counter ---
            $this->copyInvoiceTypes();

            // --- products (FK: category/vat/metric) ---
            $this->copyProducts();
            $this->copyPriceTiers();

            // --- documents ---
            $this->copyInvoices();
            $this->copyInvoiceLines();
            $this->copyReturnExtras();
            $this->copyPayments();
            $this->copyMarks();
            // AFTER copyMarks(): the legacy MARK table carries the REQUEST XML the
            // legacy app submitted, which is the authoritative series — but it only
            // exists locally once the marks are imported, so this cannot run inside
            // copyInvoices().
            $this->upgradeSeriesFromFiledMarks();

            // --- config / whmcs bridge ---
            $this->copyConfParams();
            $this->copyWhmcsLog();
        });

        // Surface legacy lookup data that AADE would REJECT at filing time.
        // The ETL copies VAT rates + invoice mydata_type VERBATIM from the
        // legacy DB (which had no AADE validation — VAT_CATEGORY.VALUE was a
        // free 0–100 numeric, INVTYPE.MYDATA_TYPE a nullable varchar). So a
        // legacy typo (e.g. a "9%" category stored as 10%) imports as-is. We
        // do NOT silently rewrite it (it's accounting data the operator may
        // have relied on); we WARN so they fix it via the lookup resources
        // (Setup → VAT Categories / Invoice Types, «Εισαγωγή τυπικών» seed).
        //
        // Wrapped: this runs AFTER the import transaction committed, so a
        // failure here (a flaky read on the freshly-populated tables) must not
        // turn a successful import into a command failure. Report and move on.
        try {
            $this->warnInvalidLookups();
        } catch (\Throwable $e) {
            $this->warn('  (post-import lookup check skipped: '.$e->getMessage().')');
        }

        $this->newLine();
        $this->info('Done. Run the golden-test comparison next (see README).');

        // PR #30 — optional per-table count emission for the UI job.
        // The job reads this JSON to surface "1240 customers, 8500
        // invoices imported" in the import-history UI. Counts are
        // total per-tenant table sizes AFTER the import (not deltas)
        // — simplest robust signal; deltas would require pre-snapshot
        // which doubles the work for marginal UX gain.
        if ($path = $this->option('counts-out')) {
            $counts = [];
            $tables = [
                'payment_methods', 'delivery_methods', 'distribution_aims',
                'metric_units', 'vat_categories', 'product_categories',
                'customers', 'invoice_types', 'products', 'product_price_tiers',
                'invoices', 'invoice_lines', 'return_invoice_extras',
                'payments', 'mydata_marks', 'conf_params', 'whmcs_invoice_log',
            ];
            foreach ($tables as $t) {
                $counts[$t] = DB::table($t)->where('company_id', $this->companyId)->count();
            }
            file_put_contents($path, json_encode($counts, JSON_PRETTY_PRINT));
        }

        return self::SUCCESS;
    }

    /**
     * `--dry-run`: the ΑΦΜ guard on its own, read-only on BOTH sides — the legacy
     * database is only SELECTed (it stays a pristine archive) and nothing is
     * written to MariaDB. Run it before a cutover import (or before a `.fbk`
     * upload, which pays for a gbak restore first) to learn about conflicts in
     * seconds instead of minutes.
     *
     * Exit code: 0 = clean, 1 = the real import would refuse.
     */
    private function dryRunAfmCheck(bool $useCompanyId): int
    {
        // Never CREATE a tenant on a dry run. With --company-id the tenant must
        // exist (same strictness as a real UI import); with --slug we look it up
        // and, if it is not there yet, skip the ekdosi-side half and say so.
        $companyId = null;
        if ($useCompanyId) {
            $companyId = $this->resolveCompanyById((int) $this->option('company-id'));
        } elseif ($slug = $this->option('slug')) {
            $companyId = Company::query()->where('slug', $slug)->value('id');
            $companyId = $companyId !== null ? (int) $companyId : null;
        }

        $this->info('Dry run — έλεγχος ΑΦΜ πελατών μόνο. Δεν γράφεται τίποτα.');
        if ($companyId === null) {
            $this->line('  (η εταιρεία δεν υπάρχει ακόμη — ελέγχονται μόνο τα διπλά ΜΕΣΑ στη legacy βάση)');
        }

        $report = $this->afmConflicts($this->fbAll('SELECT * FROM CUSTOMER'), $companyId);

        // An --afm-keep that matched nothing is part of the answer: the preflight
        // must not report a clean source while quietly dropping half the input.
        if ($report->isEmpty() && $report->unusedKeepers === []) {
            $this->info('✅ '.$report->summary());

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line($report->describe());
        $this->newLine();

        if ($report->hasBlockers()) {
            $this->error('Το import ΘΑ ΑΡΝΗΘΕΙ: '.$report->summary());
            $this->line($report->howTo());

            return self::FAILURE;
        }

        $this->info($report->parked() === []
            ? '✅ Το import θα προχωρήσει.'
            : '✅ Το import θα προχωρήσει — '.count($report->parked()).' πελάτης/ες θα μπουν χωρίς ταυτότητα ΑΦΜ.');

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- infra

    private function connectFirebird(): void
    {
        $dsn = sprintf(
            'firebird:dbname=%s:%s;charset=UTF8',
            $this->option('host'),
            $this->option('fdb')
        );
        $this->fb = new PDO($dsn, $this->option('fbuser'), $this->option('fbpass'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function resolveCompany(): int
    {
        $existing = Company::query()->where('slug', $this->option('slug'))->first();
        if ($existing) {
            return $existing->getKey();
        }

        // Eloquent create (NOT a raw insert) so the CompanyObserver fires and
        // the per-team super_admin role is provisioned for this new tenant.
        // The old raw insert bypassed the observer, leaving every ETL-created
        // tenant without a super_admin role — an admin switching into it saw a
        // stripped menu until a manual `shield:sync-super-admin`.
        $company = Company::create([
            'name' => $this->option('company'),
            'slug' => $this->option('slug'),
        ]);

        $this->provisionSuperAdmins($company);

        return $company->getKey();
    }

    /**
     * Make a freshly-imported tenant immediately usable: attach it to — and
     * grant its super_admin role within — every user who is already a
     * super_admin somewhere. Mirrors `shield:sync-super-admin`'s default, so a
     * new ETL tenant needs no manual repair step. Per-tenant operators are
     * untouched (we only lift existing super-admins).
     */
    private function provisionSuperAdmins(Company $company): void
    {
        $provisioner = app(TenantRoleProvisioner::class);

        foreach (User::all() as $user) {
            if (! $provisioner->isSuperAdminAnywhere($user)) {
                continue;
            }
            $user->companies()->syncWithoutDetaching([$company->getKey()]);
            $provisioner->assignSuperAdmin($user, $company);
            $this->line("  → super_admin provisioned for {$user->email} in {$company->slug}");
        }
    }

    /**
     * Strict company lookup for UI-driven imports — the operator selected
     * an existing tenant from the panel, so we must NOT auto-create a row
     * on typo. Throws if the id doesn't exist.
     */
    private function resolveCompanyById(int $id): int
    {
        $exists = DB::table('companies')->where('id', $id)->exists();
        if (! $exists) {
            throw new RuntimeException("Tenant with id={$id} does not exist. Cannot import into a non-existent tenant.");
        }

        return $id;
    }

    /**
     * Upsert helper. See App\Services\Etl\TenantRowUpserter for the
     * full docblock — short version: match by (company_id, legacy_id)
     * (or another natural key), refresh legacy-sourced columns from
     * $values on every run, write $insertOnlyDefaults exactly once
     * on first insertion (preserves Filament edits across re-runs).
     *
     * @param  array<string, mixed>  $matchKeys
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $insertOnlyDefaults
     */
    private function upsertGetId(
        string $table,
        array $matchKeys,
        array $values,
        array $insertOnlyDefaults = [],
    ): int {
        return $this->upserter->upsertGetId($table, $matchKeys, $values, $insertOnlyDefaults);
    }

    /**
     * Upsert without needing the resulting id (for child tables
     * whose surrogate ids are never referenced downstream).
     *
     * @param  array<string, mixed>  $matchKeys
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $insertOnlyDefaults
     */
    private function upsert(string $table, array $matchKeys, array $values, array $insertOnlyDefaults = []): void
    {
        $this->upserter->upsert($table, $matchKeys, $values, $insertOnlyDefaults);
    }

    private function fbAll(string $sql): array
    {
        return $this->fb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * The fail-closed ΑΦΜ guard, run BEFORE a single customer row is written (and
     * the whole import is one transaction anyway, so a refusal writes nothing).
     *
     * Delegates to App\Services\Etl\LegacyAfmConflicts — the same check the
     * read-only preflight (`--dry-run`, «Έλεγχος σύνδεσης») runs, so the operator
     * never gets a different answer from the probe than from the real import.
     *
     * @param  list<array<string, mixed>>  $rows  legacy CUSTOMER rows
     * @return array<int, array{key:string, keeper:int, name:?string}> CUST_IDs importing WITHOUT the ΑΦΜ identity
     */
    private function assertNoDuplicateLegacyAfm(array $rows): array
    {
        $report = $this->afmConflicts($rows, $this->companyId);

        if ($report->hasBlockers()) {
            throw new RuntimeException(
                "Σύγκρουση ΑΦΜ πελατών — τίποτα δεν γράφτηκε:\n"
                .$report->describe()."\n".$report->howTo()
            );
        }

        foreach ($report->unusedKeepers as $id) {
            $this->warn("  ⚠ το --afm-keep={$id} δεν αντιστοιχεί σε κανένα διπλό ΑΦΜ — αγνοήθηκε");
        }

        // A parked row imports whole (ΑΦΜ text, documents, history) — it just does
        // not hold the identity. Say so loudly: it is a legally significant choice
        // the operator made on the command line, not a detail to bury.
        // --slug is absent on the UI-driven (--company-id) path, so read the
        // tenant back rather than printing an audit command scoped to nothing.
        $slug = $this->option('slug') ?: Company::query()->whereKey($this->companyId)->value('slug');
        $audit = 'php artisan customers:afm-duplicates'.($slug ? " --tenant={$slug}" : '');

        foreach ($report->parked() as $custId => $parked) {
            $this->warn(sprintf(
                '  ⚠ ΑΦΜ %s: το CUST_ID %d «%s» μπαίνει ΧΩΡΙΣ ταυτότητα ΑΦΜ (την κρατά το %d) — δες «%s»',
                $parked['key'],
                $custId,
                $parked['name'] ?? '',
                $parked['keeper'],
                $audit,
            ));
        }

        return $report->parked();
    }

    /**
     * Build the ΑΦΜ conflict report for a set of legacy CUSTOMER rows.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function afmConflicts(array $rows, ?int $companyId): LegacyAfmConflictReport
    {
        $conflicts = app(LegacyAfmConflicts::class);

        return $conflicts->find(
            $conflicts->mapLegacyRows($rows, fn (array $r, string $k): ?string => $this->fld($r, $k)),
            $companyId,
            array_map('intval', (array) $this->option('afm-keep')),
        );
    }

    /**
     * True iff the named table exists in the Firebird source. Older `.fbk`
     * snapshots predate post-myDATA additions (MARK, AUTO_INVOICE_LOG,
     * CONF_PARAMS rows on later builds, etc); callers use this to skip
     * gracefully instead of crashing the whole import.
     */
    private function fbTableExists(string $name): bool
    {
        $stmt = $this->fb->prepare(
            'SELECT 1 FROM RDB$RELATIONS WHERE RDB$RELATION_NAME = ? AND RDB$SYSTEM_FLAG = 0'
        );
        $stmt->execute([strtoupper($name)]);

        return (bool) $stmt->fetchColumn();
    }

    /** Drop any byte that is not valid UTF-8 (belt-and-suspenders after FB transliteration). */
    private function clean(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $v = iconv('UTF-8', 'UTF-8//IGNORE', $v);

        return trim($v) === '' ? null : trim($v);
    }

    /**
     * Field accessor that tolerates columns missing from older legacy
     * `.fbk` snapshots — the canonical schema dump in /legacy/ is
     * post-myDATA, but earlier production gbaks predate columns like
     * CUSTOMER.TYPE, INVOICE.MYDATA_STATE, etc. Read those as NULL
     * instead of throwing "Undefined array key" mid-import.
     */
    private function fld(array $r, string $key): ?string
    {
        return $this->clean($r[$key] ?? null);
    }

    private function legacyId(string $table, $legacy): ?int
    {
        if ($legacy === null) {
            return null;
        }

        return $this->map[$table][(int) $legacy] ?? null;
    }

    /**
     * Replacement for the legacy VAT_CATEGORY_AU0 trigger at import time.
     * The trigger only fired on UPDATE, so legacy production data can
     * still contain >1 default per company (e.g. operator marked a second
     * row default via INSERT path, or two ETL runs from different sources
     * stacked defaults). Pick the lowest surrogate id as the canonical
     * default, demote the rest, and tell the operator we did so — silent
     * fix-ups during import are the kind of thing that surfaces months
     * later as "why does the form pre-select the wrong VAT rate?".
     */
    private function demoteDuplicateVatDefaults(): void
    {
        $defaults = DB::table('vat_categories')
            ->where('company_id', $this->companyId)
            ->where('is_default', true)
            ->orderBy('id')
            ->pluck('id');

        if ($defaults->count() <= 1) {
            return;
        }

        DB::table('vat_categories')
            ->where('company_id', $this->companyId)
            ->where('is_default', true)
            ->where('id', '!=', $defaults->first())
            ->update(['is_default' => false]);

        $this->warn(sprintf(
            'vat_categories: legacy data had %d rows with is_default=1 for this tenant; '
            .'kept id=%d, demoted the rest. (Single-default invariant restored.)',
            $defaults->count(),
            $defaults->first(),
        ));
    }

    /**
     * Generic copy for simple lookup tables. Re-run-safe upsert on
     * (company_id, legacy_id).
     *
     * $naturalKey, when given, makes the import ADOPT a pre-seeded row (one
     * created by MyDataLookupSeeder, which has legacy_id = NULL and is matched
     * by its description/name/rate) instead of inserting a duplicate: before
     * the legacy_id upsert we stamp the matching seeded row's legacy_id, so the
     * upsert then UPDATES it. Without this, a fresh install that pre-seeds these
     * lookups would end up with two "Μετρητά" / "24%" / "ΤΕΜ" rows — one seeded,
     * one imported. Mirrors how copyInvoiceTypes() already converges by `code`.
     */
    private function copyLookup(string $fbTable, string $target, string $pk, callable $row, ?string $naturalKey = null): void
    {
        $this->line("  {$fbTable} -> {$target}");
        foreach ($this->fbAll("SELECT * FROM {$fbTable}") as $r) {
            $data = $row($r);

            if ($naturalKey !== null) {
                self::adoptSeededLookupRow($target, $this->companyId, $naturalKey, $data[$naturalKey] ?? null, (int) $r[$pk]);
            }

            $id = $this->upsertGetId(
                $target,
                ['company_id' => $this->companyId, 'legacy_id' => $r[$pk]],
                array_merge($data, ['updated_at' => now()]),
                ['created_at' => now()],
            );
            $this->map[$target][(int) $r[$pk]] = $id;
        }
    }

    /**
     * Stamp a pre-seeded lookup row (legacy_id = NULL, matched by its natural
     * key) with the legacy id, so the subsequent upsert-by-legacy_id UPDATES it
     * instead of inserting a duplicate. No-op when:
     *   - the value is null (nothing to match on),
     *   - this legacy_id is already imported (re-run — a seeded same-key row is
     *     then a genuine extra; stamping it would break unique(company_id,legacy_id)),
     *   - no unclaimed seeded row matches (genuine new legacy value → plain insert).
     *
     * Static + DB-agnostic so it's unit-testable without a Firebird source.
     */
    public static function adoptSeededLookupRow(string $target, int $companyId, string $naturalKey, mixed $value, int $legacyId): void
    {
        if ($value === null) {
            return;
        }

        $alreadyImported = DB::table($target)
            ->where('company_id', $companyId)
            ->where('legacy_id', $legacyId)
            ->exists();
        if ($alreadyImported) {
            return;
        }

        $seededId = DB::table($target)
            ->where('company_id', $companyId)
            ->whereNull('legacy_id')
            ->where($naturalKey, $value)
            ->value('id');

        if ($seededId !== null) {
            DB::table($target)->where('id', $seededId)->update(['legacy_id' => $legacyId]);
        }
    }

    // ---------------------------------------------------------------- entities

    private function copyCustomers(): void
    {
        $this->line('  CUSTOMER -> customers');
        $rows = $this->fbAll('SELECT * FROM CUSTOMER');

        // UNIQUE(company_id, afm_key) on the target: two legacy customers with
        // the same real ΑΦΜ would make the second upsert fail mid-run. Stop
        // BEFORE writing, with the list — unless the operator named the keeper
        // with --afm-keep, in which case the others import PARKED (keyless).
        // Placeholders like 000000000 are not identities and never collide.
        $parked = $this->assertNoDuplicateLegacyAfm($rows);

        // Release every ΑΦΜ held by a row this run will rewrite (inside the
        // import transaction): an ΑΦΜ that moved between CUST_IDs — or swapped —
        // can then be re-claimed in any order without hitting the unique index.
        DB::table('customers')
            ->where('company_id', $this->companyId)
            ->whereIn('legacy_id', array_map(fn (array $r): int => (int) $r['CUST_ID'], $rows))
            ->update(['afm_key' => null]);

        foreach ($rows as $r) {
            // Filament-managed columns (is_active, needs_immediate_invoice,
            // peppol_endpoint, whmcs_client_id) are written ONLY on first
            // insert. On re-runs they stay untouched so operator
            // customisation in the panel survives.
            $id = $this->upsertGetId(
                'customers',
                ['company_id' => $this->companyId, 'legacy_id' => $r['CUST_ID']],
                [
                    'type' => $this->fld($r, 'TYPE'),
                    'afm' => $this->fld($r, 'AFM'),
                    // Query-builder write → the model hook doesn't run; derive here.
                    // A parked twin (--afm-keep chose the other row) keeps its ΑΦΜ
                    // TEXT and everything hanging off it, but holds no identity.
                    'afm_key' => isset($parked[(int) $r['CUST_ID']])
                        ? null
                        : Afm::uniqueKey($this->fld($r, 'AFM')),
                    'afm_key_parked' => isset($parked[(int) $r['CUST_ID']]),
                    'name' => $this->fld($r, 'NAME') ?? '(no name)',
                    'address1' => $this->fld($r, 'ADDRESS1'),
                    'address2' => $this->fld($r, 'ADDRESS2'),
                    'city' => $this->fld($r, 'CITY'),
                    'postcode' => $this->fld($r, 'POSTCODE'),
                    'phone1' => $this->fld($r, 'PHONE1'),
                    'phone2' => $this->fld($r, 'PHONE2'),
                    'fax' => $this->fld($r, 'FAX'),
                    'occupation' => $this->fld($r, 'OCCUPATION'),
                    'tax_office' => $this->fld($r, 'TAXOFFICE'),
                    'discount' => $r['DISCOUNT'] ?? 0,
                    'email' => $this->fld($r, 'EMAIL'),
                    'secondary_email' => $this->fld($r, 'SECONDARY_EMAIL'),
                    'country' => $this->fld($r, 'COUNTRY'),
                    // MYD-011: normalised ISO cache (query-builder write bypasses the
                    // model's saving() hook, so set it here alongside the raw label).
                    'country_code' => IsoCountry::tryNormalise($this->fld($r, 'COUNTRY')),
                    'vat_vies' => $this->fld($r, 'VAT_VIES'),
                    'withhold_tax' => $r['WITHHOLD_TAX'] ?? null,
                    'sort_order' => $r['ORDER'] ?? null,
                    'alt_customer_legacy_id' => $r['ALT_CUSTID'] ?? null,
                    'payment_method_id' => $this->legacyId('payment_methods', $r['PAYMETH_ID'] ?? null),
                    'updated_at' => now(),
                ],
                [
                    // Filament-managed columns — defaults on first
                    // insert, never updated on re-runs.
                    'is_active' => true,
                    'needs_immediate_invoice' => false,
                    'created_at' => now(),
                ],
            );
            $this->map['customers'][(int) $r['CUST_ID']] = $id;

            // Legacy DETAILS → a 'backup' internal note (replaces customers.details).
            BackupNoteSync::sync($this->companyId, $id, $this->fld($r, 'DETAILS'));
        }
    }

    private function copyInvoiceTypes(): void
    {
        $this->line('  INVTYPE -> invoice_types  (carries the ΑΑ counter)');
        foreach ($this->fbAll('SELECT * FROM INVTYPE') as $r) {
            // invoice_types has no legacy_id column; (company_id, code)
            // is the natural key.
            //
            // IMPORTANT — invcount preservation across runs: if the
            // operator issued new invoices through ekdosi between
            // imports, the local invcount has advanced past the
            // legacy value. Overwriting it here would cause the next
            // ekdosi issue to collide on (company_id, invcode) with
            // an existing row. So on re-imports we take MAX(legacy,
            // current) and never roll back. Day-0 (no existing row)
            // uses legacy as the seed.
            //
            // PARALLEL-RUN CAVEAT — MAX preservation works ONLY when
            // exactly one system is issuing at a time. If both
            // legacy AND ekdosi issued invoices between imports
            // (e.g. parallel-run with both apps live to users), they
            // would have produced overlapping invcodes like TPY6,
            // TPY7, TPY8 in both systems independently. The day-N
            // re-import surfaces the collision via the
            // (company_id, invcode) unique constraint on `invoices`
            // — loud failure, not silent corruption. Parallel-run
            // policy MUST be: one system writing at a time. See
            // CLAUDE.md "Cutover sequence" for the runbook.
            $code = $this->fld($r, 'INVTYPE_ID');
            $existing = DB::table('invoice_types')
                ->where(['company_id' => $this->companyId, 'code' => $code])
                ->first(['invcount', 'mydata_type', 'mydata_income_class', 'mydata_income_class_category']);
            $invcount = max((int) ($r['INVCOUNT'] ?? 1), (int) ($existing?->invcount ?? 0));

            // myDATA classification: COALESCE(legacy, existing). The legacy DB
            // mostly has these NULL (its app never classified by-the-book), while
            // a fresh install SEEDS them correctly (MyDataLookupSeeder). Plain
            // overwrite would wipe that seed on import — so keep the existing
            // (seeded / operator-set) value whenever legacy has nothing. Legacy
            // wins only when it actually carries a value.
            $mydataType = $this->fld($r, 'MYDATA_TYPE') ?? $existing?->mydata_type ?? null;
            $incomeClass = $this->fld($r, 'MYDATA_INCOME_CLASS') ?? $existing?->mydata_income_class ?? null;
            $incomeCat = $this->fld($r, 'MYDATA_INCOME_CLASS_CATEGORY') ?? $existing?->mydata_income_class_category ?? null;

            $id = $this->upsertGetId(
                'invoice_types',
                ['company_id' => $this->companyId, 'code' => $code],
                [
                    'name' => $this->fld($r, 'NAME') ?? $r['INVTYPE_ID'],
                    'invcount' => $invcount,
                    'is_credit' => (bool) ($r['CREDITINVOICE'] ?? 0),
                    'is_return' => (bool) ($r['RETURNINVOICE'] ?? 0),
                    'mydata_type' => $mydataType,
                    'mydata_income_class' => $incomeClass,
                    'mydata_income_class_category' => $incomeCat,
                    'distribution_aim_id' => $this->legacyId('distribution_aims', $r['DISTAIM_ID']),
                    'delivery_method_id' => $this->legacyId('delivery_methods', $r['DELIVERYMETHOD_ID']),
                    'payment_method_id' => $this->legacyId('payment_methods', $r['PAYMETH_ID']),
                    'default_customer_id' => $this->legacyId('customers', $r['CUST_ID']),
                    'updated_at' => now(),
                ],
                // show_on_menu is a UI-only convenience flag (NOT legal data), so
                // ignore the legacy SHOW_ON_MENU and default every imported type to
                // on-menu — the operator hides what they don't want. Insert-only,
                // so a re-import never clobbers that operator choice.
                ['created_at' => now(), 'show_on_menu' => true],
            );
            // invoice_types keyed by its string code, not an int PK
            $this->map['invoice_types'][$code] = $id;
        }
    }

    /**
     * Post-import sanity report (warn-only): flag imported lookup rows that
     * AADE would reject at filing time, so the operator fixes them via the
     * Filament lookup resources rather than discovering it on first submit.
     *
     *  - VAT categories whose rate is NOT an AADE §8.2 value
     *    (0/4/6/9/13/17/24 — see Codes::VAT_CATEGORY_RATES). A legacy typo
     *    like a "9%" category stored as 10% lands here.
     *  - Invoice types with an empty or unknown mydata_type (Codes::INVOICE_TYPES).
     *    Empty = the legacy row never got an AADE classification; unknown =
     *    a code not in the §8.1 catalogue.
     *
     * Never mutates — this is the "warn-only" half of the import-data fix; the
     * "correct it" half is the in-app seed / edit on the lookup resources.
     */
    private function warnInvalidLookups(): void
    {
        $badVat = DB::table('vat_categories')
            ->where('company_id', $this->companyId)
            ->get(['description', 'rate', 'mydata_vat_category'])
            // MYD-8: a 3%/4% row IS fileable via a §8.2 override — don't false-warn.
            ->filter(fn ($v) => ! Codes::vatRateFileable($v->rate, $v->mydata_vat_category));

        if ($badVat->isNotEmpty()) {
            $this->newLine();
            $this->warn('⚠ VAT CATEGORIES (templates for NEW invoices) with a non-AADE rate (§8.2):');
            foreach ($badVat as $v) {
                $this->warn(sprintf('    • %s = %s%%', $v->description ?? '(no description)', rtrim(rtrim((string) $v->rate, '0'), '.')));
            }
            $this->warn('    These would be rejected if used to issue a NEW invoice. Fix/seed in');
            $this->warn('    Setup → VAT Categories (valid: 0/4/6/9/13/17/24%).');
            // Historical invoices are intentionally NOT touched: an imported
            // invoice line keeps its original rate (e.g. an old 23% from before
            // the 24% change) — that's a correct historical record, never
            // re-filed, and is NOT what this warning is about.
            $this->line('    (Imported invoices keep their original rates — historical lines like 23% are fine.)');
        }

        $knownTypes = Codes::INVOICE_TYPES;
        $badTypes = DB::table('invoice_types')
            ->where('company_id', $this->companyId)
            ->get(['code', 'name', 'mydata_type'])
            ->filter(fn ($t) => empty($t->mydata_type) || ! isset($knownTypes[$t->mydata_type]));

        if ($badTypes->isNotEmpty()) {
            $this->newLine();
            $this->warn('⚠ Invoice types with a missing/unknown myDATA type (§8.1) — cannot be filed until set:');
            foreach ($badTypes as $t) {
                $this->warn(sprintf('    • %s (%s) → myDATA type: %s',
                    $t->code, $t->name ?? '?', $t->mydata_type ? '«'.$t->mydata_type.'» (unknown)' : '(empty)'));
            }
            $this->warn('    Fix in Setup → Invoice Types (or «Εισαγωγή τυπικών» to add the standard set).');
        }
    }

    private function copyProducts(): void
    {
        $this->line('  PRODUCT -> products');
        foreach ($this->fbAll('SELECT * FROM PRODUCT') as $r) {
            // Filament-managed product columns (PR #20 deferred items
            // in CLAUDE.md: is_active, internal_notes, sku,
            // whmcs_product_id, supplier) get defaults on insert only.
            // Re-runs never write these — operator customisation
            // in the panel survives.
            $id = $this->upsertGetId(
                'products',
                ['company_id' => $this->companyId, 'legacy_id' => $r['PRODUCT_ID']],
                [
                    'barcode' => $this->fld($r, 'BARCODE'),
                    'description_short' => $this->fld($r, 'DESCRIPTION_SHORT') ?? '(no description)',
                    'description' => $this->fld($r, 'DESCRIPTION'),
                    'product_category_id' => $this->legacyId('product_categories', $r['CAT_ID']),
                    'vat_category_id' => $this->legacyId('vat_categories', $r['VATCAT_ID']),
                    'metric_unit_id' => $this->legacyId('metric_units', $r['METRIC_ID']),
                    'buy_price' => $r['BUY_PRICE'] ?? 0,
                    'sell_price' => $r['SELL_PRICE'] ?? 0,
                    'price_wvat' => $r['PRICE_WVAT'] ?? 0,
                    'reserve' => $r['RESERVE'] ?? 0,
                    'reserve_secure' => $r['RESERVE_SECURE'] ?? 0,
                    'date_inserted' => $r['DATE_INSERTED'],
                    'updated_at' => $r['LAST_UPDATE'] ?? now(),
                ],
                [
                    'is_active' => true,
                    'created_at' => now(),
                ],
            );
            $this->map['products'][(int) $r['PRODUCT_ID']] = $id;
        }
    }

    private function copyPriceTiers(): void
    {
        $this->line('  PROD_PRICE_QTY -> product_price_tiers');
        foreach ($this->fbAll('SELECT * FROM PROD_PRICE_QTY') as $r) {
            $product = $this->legacyId('products', $r['PRODUCT_ID']);
            if (! $product) {
                continue;
            }
            $this->upsert(
                'product_price_tiers',
                ['company_id' => $this->companyId, 'legacy_id' => $r['PROD_PRICE_ID']],
                [
                    'product_id' => $product,
                    'value' => $r['VAL'],
                    'discount_percent' => $r['DISCOUNT_PERCENT'],
                    'qty' => $r['QTY'],
                    'updated_at' => now(),
                ],
                ['created_at' => now()],
            );
        }
    }

    private function copyInvoices(): void
    {
        $this->line('  INVOICE -> invoices');

        // Pre-fetch the legacy_id → conv_invoice_id map. We can't
        // resolve conv_invoice_id in pass 1 directly because the
        // target legacy id may not have been imported yet (legacy
        // doesn't guarantee CONV_INVOICE_ID points BACKWARDS in time
        // — circular or forward references happen). So pass 1 walks
        // every invoice, captures the raw legacy conv pointer, then
        // pass 2 resolves the int→int map.
        //
        // Why this matters for re-run safety: the old pass-2
        // implementation used `WHERE CONV_INVOICE_ID IS NOT NULL` —
        // so if legacy CLEARED a conv link between imports, the
        // ekdosi row kept the stale pointer. The new shape walks
        // EVERY row in pass 2, setting conv_invoice_id to NULL when
        // legacy says NULL. Source of truth wins.
        $convPointers = [];  // ekdosi-side surrogate id => legacy CONV_INVOICE_ID
        foreach ($this->fbAll('SELECT * FROM INVOICE') as $r) {
            $issuedAt = $this->mergeDateTime($r['INVDATE'], $r['INVTIME']);
            // Derive local_status from the legacy myDATA state. Legacy ekdosi
            // had NO "draft" concept for posted παραστατικά — every INVOICE row
            // is an issued document — so a fresh import must land them as
            // 'active' (or 'cancelled' if AADE-cancelled), NEVER the column
            // default 'draft'. Without this, freshly-imported invoices show as
            // «Πρόχειρο» in the panel even though they carry a VALID MARK (the
            // migration's one-time backfill only touched rows present when it
            // ran, not later imports). Legacy-sourced → refreshes every run.
            $mydataState = $this->fld($r, 'MYDATA_STATE');
            $localStatus = ($mydataState === 'CANCELLED') ? 'cancelled' : 'active';
            $id = $this->upsertGetId(
                'invoices',
                ['company_id' => $this->companyId, 'legacy_id' => $r['INVOICE_ID']],
                [
                    'invcode' => $this->fld($r, 'INVCODE'),
                    'code' => $r['CODE'] ?? 0,
                    'invoice_type_id' => $this->map['invoice_types'][$this->fld($r, 'INVTYPE')] ?? null,
                    'customer_id' => $this->legacyId('customers', $r['CUST_ID']),
                    'issued_at' => $issuedAt,
                    'distribution_aim_id' => $this->legacyId('distribution_aims', $r['DISTRAIM_ID']),
                    'delivery_method_id' => $this->legacyId('delivery_methods', $r['DELMETHOD_ID']),
                    'payment_method_id' => $this->legacyId('payment_methods', $r['PAYMETH_ID']),
                    'delivery_date' => $r['DELIVERYDATE'] ?? null,
                    'header_discount_percent' => $r['DISCOUNT'] ?? 0,
                    'net_total' => $r['PRICE'] ?? 0,
                    'gross_total' => $r['PRICEWVAT'] ?? 0,
                    'withhold_amount' => $r['WITHHOLD_AMOUNT'] ?? null,
                    // Legacy MAILED/PRINTED/EMAIL_SENT deliberately NOT imported:
                    // the old paper-print + mail flags are inert in the new app
                    // (email tracking is the mail-log; no print workflow). The
                    // columns were dropped, so writing them would error — ignore.
                    'address1' => $this->fld($r, 'ADDRESS1'),
                    'address2' => $this->fld($r, 'ADDRESS2'),
                    'city' => $this->fld($r, 'CITY'),
                    'postcode' => $this->fld($r, 'POSTCODE'),
                    'country' => $this->fld($r, 'COUNTRY'),
                    'company_name' => $this->fld($r, 'COMPANY_NAME'),
                    'vat_no' => $this->fld($r, 'VAT_NO'),
                    'vies_vat' => $this->fld($r, 'VIES_VAT'),
                    'occupation' => $this->fld($r, 'OCCUPATION'),
                    'notes' => $this->fld($r, 'NOTES'),
                    'mydata_sent' => isset($r['MYDATA_SENT']) ? (bool) $r['MYDATA_SENT'] : null,
                    'mydata_state' => $mydataState,
                    'mydata_mark' => $this->fld($r, 'MYDATA_MARK'),
                    'mydata_url' => $this->fld($r, 'MYDATA_URL'),
                    'local_status' => $localStatus,
                    'updated_at' => now(),
                ],
                [
                    'created_at' => $issuedAt ?? now(),
                    // MYD-018: freeze the series the legacy document was ISSUED
                    // under. This is a query-builder upsert, so the model's
                    // creating hook never fires — derive it from the same helper
                    // so an imported row and an app-created one can't disagree.
                    //
                    // INSERT-ONLY, deliberately. On a re-run the migration's value
                    // is already there and may have come from a better source (the
                    // request XML of the MARK we actually filed), so refreshing it
                    // from `invcode` every run would either downgrade it or — when
                    // fromInvcode() cannot parse the pair — write NULL and un-freeze
                    // the row. The series never changes for a given document, so
                    // there is nothing legitimate to refresh.
                    'series' => DocumentSeries::fromInvcode($this->fld($r, 'INVCODE'), $r['CODE'] ?? 0),
                ],
            );
            $this->map['invoices'][(int) $r['INVOICE_ID']] = $id;
            // Defensive `?? null` for older `.fbk` snapshots that
            // predate the CONV_INVOICE_ID column. Matches the
            // tolerance pattern used by fld() elsewhere in this file
            // (and by the `fbTableExists()` skip-guards on MARK /
            // CONF_PARAMS / AUTO_INVOICE_LOG). Without it, a raw
            // array access on the missing key triggers
            // "Undefined array key" under PHP 8+.
            $convPointers[$id] = $r['CONV_INVOICE_ID'] ?? null;
        }

        // Pass 2: refresh EVERY row's conv_invoice_id from the legacy
        // source — including rows where legacy says NULL. This is the
        // fix vs. the previous shape that only walked rows where
        // legacy had a non-NULL conv pointer, so cleared-in-source
        // links survived as stale data in ekdosi.
        //
        // PERF NOTE — N individual UPDATEs even when value unchanged.
        // For a 10K-invoice tenant on a re-run, this is 10K round
        // trips (mostly NULL → NULL). Acceptable today (seconds, not
        // minutes); will degrade as a tenant's invoice count grows
        // 10×+ OR when activitylog wraps `invoices` (each UPDATE
        // would write an activity row even though nothing changed).
        // Future optimisation: skip the UPDATE when the existing
        // value matches; OR batch into a single CASE WHEN UPDATE.
        foreach ($convPointers as $selfId => $legacyConvId) {
            $convSurrogate = $legacyConvId !== null
                ? $this->legacyId('invoices', $legacyConvId)
                : null;
            DB::table('invoices')
                ->where('id', $selfId)
                ->update(['conv_invoice_id' => $convSurrogate]);
        }
    }

    private function copyInvoiceLines(): void
    {
        $this->line('  INVLINES -> invoice_lines');
        foreach ($this->fbAll('SELECT * FROM INVLINES WHERE INVOICE_ID IS NOT NULL') as $r) {
            $invoice = $this->legacyId('invoices', $r['INVOICE_ID']);
            if (! $invoice) {
                continue; // legacy "basket" rows with NULL invoice are scratch state, skip
            }
            $id = $this->upsertGetId(
                'invoice_lines',
                ['company_id' => $this->companyId, 'legacy_id' => $r['INVLINE_ID']],
                [
                    'invoice_id' => $invoice,
                    'product_id' => $this->legacyId('products', $r['PRODUCT_ID']),
                    'qty' => $r['QTY'] ?? 1,
                    'price_per_item' => $r['PRICE_PER_ITEM'],
                    'discount' => $r['DISCOUNT'] ?? 0,
                    'vat_percent' => $r['VATPERCENT'],
                    'net_price' => $r['PRICE'],
                    'gross_price' => $r['PRICEWVAT'],
                    'product_descr' => $this->fld($r, 'PRODUCT_DESCR'),
                    'metric_unit' => $this->fld($r, 'METRIC_UNIT'),
                    'notes' => $this->fld($r, 'NOTES'),
                    'updated_at' => now(),
                ],
                ['created_at' => now()],
            );
            $this->map['invoice_lines'][(int) $r['INVLINE_ID']] = $id;
        }
    }

    private function copyReturnExtras(): void
    {
        $this->line('  RETURN_INVOICE_EXTRAS -> return_invoice_extras');
        foreach ($this->fbAll('SELECT * FROM RETURN_INVOICE_EXTRAS') as $r) {
            $line = $this->legacyId('invoice_lines', $r['INVLINE_ID']);
            if (! $line) {
                continue;
            }
            // return_invoice_extras has unique(invoice_line_id) — one
            // extras row per line. Upsert by that key.
            $this->upsert(
                'return_invoice_extras',
                ['invoice_line_id' => $line],
                [
                    'company_id' => $this->companyId,
                    'qty_given' => $r['QTY_GIVEN'],
                    'qty_returned' => $r['QTY_RETURNED'],
                    'qty_sent' => $r['QTY_SENT'],
                    'updated_at' => now(),
                ],
                ['created_at' => now()],
            );
        }
    }

    private function copyPayments(): void
    {
        $this->line('  PAYMENT -> payments');
        foreach ($this->fbAll('SELECT * FROM PAYMENT') as $r) {
            $customer = $this->legacyId('customers', $r['CUST_ID']);
            if (! $customer) {
                continue;
            }
            $this->upsert(
                'payments',
                ['company_id' => $this->companyId, 'legacy_id' => $r['PAYMENT_ID']],
                [
                    'customer_id' => $customer,
                    'pay_date' => $r['PAY_DATE'],
                    'amount' => $r['VALUE'],
                    'notes' => $this->fld($r, 'NOTES'),
                    'updated_at' => now(),
                ],
                ['created_at' => now()],
            );
        }
    }

    /**
     * MYD-018: upgrade each imported invoice's frozen series to what it was
     * ACTUALLY FILED as, now that the legacy MARK rows (and their REQUEST XML)
     * are local.
     *
     * copyInvoices() freezes the series from `invcode`, which is right for a
     * legacy row — the trigger built INVCODE as `INVTYPE_ID || INVCOUNT`, and
     * INVTYPE_ID is the legacy PK, so a rename between numbering and filing is
     * not really reachable there. But the claim the migration makes must hold
     * here too: a stored value and a recovered one cannot disagree. Shared
     * definition, so the two can never drift apart.
     */
    private function upgradeSeriesFromFiledMarks(): void
    {
        $corrected = FiledSeriesBackfill::apply(
            'invoices',
            $this->companyId,
            // Legacy-imported rows ONLY. The ETL's locked contract is that a
            // Filament-created row (legacy_id null) is never touched — the
            // migration's own unscoped pass already covers those.
            fn ($query) => $query->whereNotNull('invoices.legacy_id'),
        );

        if ($corrected > 0) {
            $this->line("  series -> corrected from the filed MARK XML on {$corrected} invoice(s)");
        }
    }

    private function copyMarks(): void
    {
        if (! $this->fbTableExists('MARK')) {
            $this->line('  MARK -> mydata_marks  (skipped: table absent in this .fbk — pre-myDATA snapshot)');

            return;
        }
        $this->line('  MARK -> mydata_marks  (full audit trail)');
        foreach ($this->fbAll('SELECT * FROM MARK') as $r) {
            // CRITICAL — mydata_marks is the LEGAL AUDIT TRAIL per
            // CLAUDE.md. created_at on these rows must NOT be NULL
            // (auditor-visible). Use the legacy MARK insert time when
            // available; fall back to now() for pre-PR-24 rows.
            $this->upsert(
                'mydata_marks',
                ['company_id' => $this->companyId, 'legacy_id' => $r['ID']],
                [
                    'invoice_id' => $this->legacyId('invoices', $r['INVOICE_ID']),
                    // After PR #24 the column is nullable. Legacy rows
                    // with no MARK (rare — pre-myDATA staging) now land
                    // as NULL instead of empty-string sentinels.
                    'mark' => $this->fld($r, 'MARK'),
                    'mydata_action' => $this->fld($r, 'MYDATA_ACTION'),
                    'invoice_url' => $this->fld($r, 'INVOICE_URL'),
                    'request' => $this->fld($r, 'REQUEST'),
                    'response' => $this->fld($r, 'RESPONSE'),
                    'mark_date' => $r['DATE'],
                    // pdo_firebird may return TIME as either a pure
                    // "HH:MM:SS" or a full "Y-m-d H:i:s" string depending
                    // on driver build; extractTime() normalises both.
                    'mark_time' => $this->extractTime($r['TIME']),
                    'updated_at' => now(),
                ],
                ['created_at' => $this->mergeDateTime($r['DATE'], $r['TIME']) ?? now()],
            );
        }
    }

    private function copyConfParams(): void
    {
        if (! $this->fbTableExists('CONF_PARAMS')) {
            $this->line('  CONF_PARAMS -> conf_params (skipped: table absent in this .fbk)');

            return;
        }
        $this->line('  CONF_PARAMS -> conf_params');
        foreach ($this->fbAll('SELECT * FROM CONF_PARAMS') as $r) {
            // conf_params natural key is (company_id, varname).
            $this->upsert(
                'conf_params',
                ['company_id' => $this->companyId, 'varname' => $this->fld($r, 'VARNAME') ?? ''],
                [
                    'data_int' => $r['DATA_INT'],
                    'data_string' => $this->fld($r, 'DATA_STRING'),
                    'data_timestamp' => $r['DATA_TIMESTAMP'],
                    'data_float' => $r['DATA_FLOAT'],
                    'data_numeric' => $r['DATA_NUMERIC'],
                    'updated_at' => now(),
                ],
                ['created_at' => now()],
            );
        }
    }

    private function copyWhmcsLog(): void
    {
        if (! $this->fbTableExists('AUTO_INVOICE_LOG')) {
            $this->line('  AUTO_INVOICE_LOG -> whmcs_invoice_log (skipped: table absent in this .fbk — pre-WHMCS-bridge snapshot)');

            return;
        }
        $this->line('  AUTO_INVOICE_LOG -> whmcs_invoice_log');
        foreach ($this->fbAll('SELECT * FROM AUTO_INVOICE_LOG') as $r) {
            // invoice_id stays NULL: AUTO_INVOICE_LOG has NO ekdosi-invoice
            // link. Its only columns are LOG_ID / LOG_TIMESTAMP / CS_INVID /
            // LOG_MESSAGE — and CS_INVID is the WHMCS invoice id, not an
            // ekdosi one. The previous code resolved invoice_id via
            // legacyId('invoices', CS_INVID), feeding a WHMCS id into the
            // ekdosi-invoice id map — wrong keyspace, yielding either NULL or
            // a coincidental WRONG invoice. The real WHMCS↔ekdosi link was
            // never persisted in legacy (CUSTOMER has no WHMCS column either),
            // so it's structurally unrecoverable; the live bridge matches on
            // ΑΦΜ instead (WhmcsInvoicesByAfmController). We still import the
            // row for its LOG_MESSAGE audit trail + CS_INVID.
            $this->upsert(
                'whmcs_invoice_log',
                ['company_id' => $this->companyId, 'legacy_id' => $r['LOG_ID']],
                [
                    'whmcs_invoice_id' => $r['CS_INVID'],
                    'invoice_id' => null,
                    'message' => $this->fld($r, 'LOG_MESSAGE'),
                    'updated_at' => now(),
                ],
                ['created_at' => $r['LOG_TIMESTAMP'] ?? now()],
            );
        }
    }

    private function mergeDateTime($date, $time): ?string
    {
        if (! $date) {
            return null;
        }
        $d = substr((string) $date, 0, 10);
        $t = $this->extractTime($time);

        return "{$d} {$t}";
    }

    /**
     * Extract HH:MM:SS from a value that pdo_firebird may return as either
     * a pure TIME string ("15:50:42") or a full datetime string
     * ("2021-05-31 15:50:42") depending on the driver build. Anything that
     * doesnt contain an HH:MM:SS token degrades to "00:00:00".
     */
    private function extractTime($time): string
    {
        if ($time === null || $time === '') {
            return '00:00:00';
        }
        if (preg_match('/(\d{2}:\d{2}:\d{2})/', (string) $time, $m)) {
            return $m[1];
        }

        return '00:00:00';
    }
}
