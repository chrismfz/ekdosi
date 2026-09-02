<?php

namespace App\Services\Portability;

use App\Models\Company;
use App\Services\TenantRoleProvisioner;
use App\Support\Afm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Phase 1 (import half) — restore a per-company SETTINGS + SETUP bundle produced
 * by CompanyExporter, on the same or another VM. Idempotent: setup rows are
 * matched by a per-table natural key (insert if missing, update in place if
 * found) so a re-import converges instead of duplicating, and matched rows keep
 * their id so transactional data that references them never dangles.
 *
 * The company's secrets are opened with the passphrase and written back through
 * Eloquent, so they are stored in the target VM's at-rest form on save —
 * encrypted under its APP_KEY, or plaintext, per ekdosi.secrets.encrypt_at_rest.
 *
 * Known v1 limitations:
 *  - servers/server_groups `secret_encrypted` is REDACTED on export
 *    (CompanyExporter::REDACTED_COLUMNS — a secret must not ride in a bundle),
 *    so server creds are re-entered on the target VM after import.
 *  - A `--full` bundle targets a FRESH company (`--new`). Re-importing one INTO a
 *    company that already holds its data converges only for legacy_id-bearing
 *    rows (the ETL'd majority); Filament-created rows (legacy_id null) fall to a
 *    content signature whose FK columns still hold the OLD ids, so they may
 *    re-insert (over-create, never wrong-merge). Invariant: the customer set is
 *    complete (payments.customer_id is NOT NULL — a payment to an absent customer
 *    would fail the insert rather than silently null).
 */
class CompanyImporter
{
    /** Per-table natural key (within a company) for idempotent matching. */
    private const KEYS = [
        'distribution_aims' => ['description'],
        'delivery_methods' => ['description'],
        'vat_categories' => ['description'],
        'payment_methods' => ['description'],
        'bank_accounts' => ['iban', 'account_name'],
        'product_categories' => ['description'],
        'metric_units' => ['name'],
        'tags' => ['name'],
        'server_groups' => ['name'],
        // Composite: a tenant may have TWO connections of the same source (two
        // shops) disambiguated by label — keying on source alone wrong-merges them.
        'billing_connections' => ['source', 'label'],
        'invoice_types' => ['code'],
        'servers' => ['name'],
        // Transactional: legacy_id where present (unique per company) for
        // idempotent re-import; the rest fall back to a content signature.
        'customers' => ['legacy_id'],
        'products' => ['legacy_id'],
        'product_price_tiers' => ['legacy_id'],
        'invoices' => ['legacy_id'],
        'invoice_lines' => ['legacy_id'],
        'mydata_marks' => ['legacy_id'],
        'payments' => ['legacy_id'],
        'quotes' => ['legacy_id'],
    ];

    /** Import order for setup (bucket B): independent first, then intra-setup FKs. */
    private const ORDER = [
        'distribution_aims', 'delivery_methods', 'vat_categories', 'payment_methods',
        'bank_accounts', 'product_categories', 'metric_units', 'tags',
        'server_groups', 'billing_connections', 'invoice_types', 'servers',
        'expense_classification_rules',
    ];

    /** Import order for transactional (bucket C, --full): parents before children. */
    private const ORDER_TRANSACTIONAL = [
        'customers', 'customer_contacts', 'suppliers',
        'leads', 'lead_activities',
        'products', 'product_price_tiers', 'product_billing_prices',
        'invoices', 'invoice_lines', 'mydata_marks', 'return_invoice_extras', 'invoice_mail_log',
        'payments',
        'quotes', 'quote_lines', 'quote_mail_logs',
        'expenses', 'expense_lines', 'expense_marks',
        'cmr_notes', 'cmr_lines',
    ];

    /** Invoice self-reference columns — nulled on insert, patched after the pass. */
    private const INVOICE_SELF_REFS = ['credited_invoice_id', 'conv_invoice_id'];

    /** invoice_lines self-reference columns (MON-1: a credit line → the original
     *  line it credits). Same problem as INVOICE_SELF_REFS — the target line may
     *  be imported later in the same pass, so the generic FK_REWIRES nulls it on
     *  insert and patchInvoiceLineSelfRefs resolves it against the complete map. */
    private const INVOICE_LINE_SELF_REFS = ['original_line_id'];

    /**
     * FK columns to rewire via the imported old-id → new-id maps. A source of
     * 'users' has no map (users are panel-global, not in the bundle) → the
     * column is nulled, dropping the cross-tenant reference.
     */
    private const FK_REWIRES = [
        'invoice_types' => [
            'distribution_aim_id' => 'distribution_aims', 'delivery_method_id' => 'delivery_methods',
            'payment_method_id' => 'payment_methods',
            // customers are imported AFTER setup → nulled here, patched by
            // patchInvoiceTypeDefaults() once the customers map exists.
            'default_customer_id' => 'customers',
        ],
        'servers' => ['server_group_id' => 'server_groups'],
        'customers' => ['payment_method_id' => 'payment_methods'],
        'customer_contacts' => ['customer_id' => 'customers'],
        // Leads (mini-CRM): both customer links rewire; the operator link is a
        // panel-global user → nulled (same rule as *_by_user_id elsewhere).
        'leads' => ['referred_by_customer_id' => 'customers', 'converted_customer_id' => 'customers', 'assigned_user_id' => 'users'],
        'lead_activities' => ['lead_id' => 'leads', 'user_id' => 'users'],
        'products' => ['product_category_id' => 'product_categories', 'vat_category_id' => 'vat_categories', 'metric_unit_id' => 'metric_units'],
        'product_price_tiers' => ['product_id' => 'products'],
        'product_billing_prices' => ['product_id' => 'products'],
        'invoices' => [
            'invoice_type_id' => 'invoice_types', 'customer_id' => 'customers',
            'distribution_aim_id' => 'distribution_aims', 'delivery_method_id' => 'delivery_methods',
            'payment_method_id' => 'payment_methods', 'bank_account_id' => 'bank_accounts',
            'credited_invoice_id' => 'invoices', 'conv_invoice_id' => 'invoices',
            // FKs to DEFERRED tables (not in the bundle) → nulled via the
            // never-populated map, else the source id would violate the FK.
            'whmcs_pending_id' => 'pending_whmcs_invoices', 'service_contract_id' => 'service_contracts',
        ],
        'invoice_lines' => ['invoice_id' => 'invoices', 'product_id' => 'products', 'original_line_id' => 'invoice_lines'],
        'mydata_marks' => ['invoice_id' => 'invoices'],
        'return_invoice_extras' => ['invoice_line_id' => 'invoice_lines'],
        'invoice_mail_log' => ['invoice_id' => 'invoices', 'triggered_by_user_id' => 'users'],
        'payments' => ['customer_id' => 'customers', 'invoice_id' => 'invoices', 'payment_method_id' => 'payment_methods', 'bank_account_id' => 'bank_accounts'],
        'quotes' => [
            'customer_id' => 'customers', 'lead_id' => 'leads', 'converted_invoice_id' => 'invoices',
            'converted_service_contract_id' => 'service_contracts', // deferred → nulled
        ],
        'quote_lines' => ['quote_id' => 'quotes', 'product_id' => 'products'],
        'quote_mail_logs' => ['quote_id' => 'quotes', 'triggered_by_user_id' => 'users'],
        'expenses' => ['supplier_id' => 'suppliers'],
        'expense_lines' => ['expense_id' => 'expenses'],
        'expense_marks' => ['expense_id' => 'expenses'],
        'cmr_notes' => [
            'customer_id' => 'customers',
            // Polymorphic source (Invoice | DeliveryNote) can't be conditionally
            // rewired by a single column→table map, and delivery_notes aren't in
            // the bundle anyway → drop the back-link (map to a never-populated
            // table nulls it). The CMR keeps all its own snapshot data.
            'source_id' => 'delivery_notes',
        ],
        'cmr_lines' => ['cmr_note_id' => 'cmr_notes'],
    ];

    /**
     * Surrogate/lifecycle columns regenerated on import. `deleted_at` is
     * deliberately KEPT: the exporter dumps soft-deleted rows (raw table read),
     * and a restore must not resurrect a customer/lead/product that was
     * deleted — e.g. a lead kept only so «μην ξαναενοχλήσετε» still matches.
     */
    private const DROP_COLUMNS = ['id', 'company_id', 'created_at', 'updated_at'];

    public function __construct(
        private readonly SecretsCodec $codec,
        private readonly TenantRoleProvisioner $provisioner,
    ) {}

    /**
     * @param  array<string,mixed>  $bundle  CompanyExporter::build() shape
     * @param  array{into?:?string, new?:bool, execute?:bool, passphrase?:?string}  $opts
     * @return array<string,mixed> summary
     */
    public function run(array $bundle, array $opts): array
    {
        $slug = (string) ($bundle['manifest']['company']['slug'] ?? '');
        $new = (bool) ($opts['new'] ?? false);
        $execute = (bool) ($opts['execute'] ?? false);

        $existing = Company::query()->where('slug', $new ? $slug : ($opts['into'] ?? $slug))->first();

        if ($new && $existing !== null) {
            throw new RuntimeException("Υπάρχει ήδη εταιρία με slug «{$slug}». Χρησιμοποίησε --into={$slug}.");
        }
        if (! $new && $existing === null) {
            throw new RuntimeException('Δεν βρέθηκε εταιρία για εισαγωγή (--into). Δώσε --new για δημιουργία.');
        }

        // Open secrets up-front so a wrong/missing passphrase fails before writes.
        $secrets = $this->codec->open($bundle['secrets'], $opts['passphrase'] ?? null);

        $summary = [
            'company' => $new ? 'create' : 'update',
            'slug' => $new ? $slug : $existing->slug,
            'dry_run' => ! $execute,
            'tables' => $this->plan($bundle, $existing),
        ];

        if (! $execute) {
            return $summary;
        }

        $company = DB::transaction(function () use ($bundle, $secrets, $new, $existing): Company {
            $attrs = $this->companyAttributes($bundle['company'], $secrets);
            $whmcsTypeOld = $attrs['whmcs_default_invoice_type_id'] ?? null;
            $attrs['whmcs_default_invoice_type_id'] = null; // rewired after invoice_types

            $company = $new ? new Company : $existing;
            // Suppress the created-observer's role provisioning INSIDE this big
            // transaction. On MariaDB its role INSERT can collide with a row the
            // transaction's snapshot can't re-read ("could not be re-read"), and a
            // rolled-back import would orphan roles. Roles are provisioned AFTER
            // commit (below) — visible, idempotent, race-safe.
            Company::withoutEvents(fn () => $company->forceFill($attrs)->save());

            $this->restoreLogo($company, $bundle);

            $maps = [];
            foreach (self::ORDER as $table) {
                $maps[$table] = $this->importTable($table, $bundle['setup'][$table] ?? [], $company->id, $maps);
            }

            if ($whmcsTypeOld !== null && isset($maps['invoice_types'][$whmcsTypeOld])) {
                Company::withoutEvents(fn () => $company->forceFill(['whmcs_default_invoice_type_id' => $maps['invoice_types'][$whmcsTypeOld]])->save());
            }

            // Bucket C (full bundle): transactional, parents before children.
            foreach (self::ORDER_TRANSACTIONAL as $table) {
                $maps[$table] = $this->importTable($table, $bundle['data'][$table] ?? [], $company->id, $maps);
            }
            $this->patchInvoiceSelfRefs($bundle['data']['invoices'] ?? [], $maps);
            // MON-1: resolve invoice_lines.original_line_id against the complete
            // invoice_lines map (the credited line may import before its original).
            $this->patchInvoiceLineSelfRefs($bundle['data']['invoice_lines'] ?? [], $maps);
            // invoice_types.default_customer_id → customers (imported just now).
            $this->patchInvoiceTypeDefaults($bundle['setup']['invoice_types'] ?? [], $maps);

            return $company;
        });

        // Provision the tenant's roles now that the company is COMMITTED + visible
        // (the observer was suppressed above). Idempotent + outside the import
        // transaction, so it can't hit an unreadable-snapshot collision and a
        // failed import never leaves orphan roles.
        try {
            if ($new) {
                // Fresh company: full provision (rows + permission maps).
                $this->provisioner->ensureSuperAdminRole($company);
                $this->provisioner->ensureStandardRoles($company);
            } else {
                // --into existing: ensure the role ROWS exist (heal a roles-less
                // company) but DON'T re-sync permission maps — that would clobber
                // any manual per-tenant role customization.
                $this->provisioner->ensureManagedRolesExist($company);
            }
        } catch (\Throwable $e) {
            // The data is already committed — surface a clear, recoverable
            // message instead of a raw error implying nothing happened.
            Log::error('CompanyImporter: company imported but role provisioning failed.', [
                'company_id' => $company->id,
                'slug' => $company->slug,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException(
                'Η εταιρία «'.$company->slug.'» ΕΙΣΗΧΘΗ, αλλά απέτυχε η δημιουργία ρόλων: '
                .$e->getMessage().' — τρέξε «php artisan shield:sync-super-admin» για να ολοκληρωθεί.',
                previous: $e,
            );
        }

        return $summary;
    }

    /**
     * Patch invoice self-references after the whole invoices pass: a credit note
     * (or ΣΔΕΠ conversion) may point at an original imported later in iteration,
     * so they're nulled on insert (FK_REWIRES → 'invoices' isn't yet mapped
     * mid-pass) and resolved here against the complete invoices map.
     *
     * @param  list<array<string,mixed>>  $invoiceRows
     * @param  array<string, array<int|string,int>>  $maps
     */
    private function patchInvoiceSelfRefs(array $invoiceRows, array $maps): void
    {
        $invoiceMap = $maps['invoices'] ?? [];
        foreach ($invoiceRows as $row) {
            $oldId = $row['id'] ?? null;
            if ($oldId === null || ! isset($invoiceMap[$oldId])) {
                continue;
            }
            $patch = [];
            foreach (self::INVOICE_SELF_REFS as $col) {
                $oldRef = $row[$col] ?? null;
                if ($oldRef !== null && isset($invoiceMap[$oldRef])) {
                    $patch[$col] = $invoiceMap[$oldRef];
                }
            }
            if ($patch !== []) {
                DB::table('invoices')->where('id', $invoiceMap[$oldId])->update($patch);
            }
        }
    }

    /**
     * MON-1: patch invoice_lines.original_line_id after the whole invoice_lines
     * pass — a credit-note line may point at an original line imported later in
     * iteration, so it's nulled on insert (FK_REWIRES → 'invoice_lines' isn't
     * fully mapped mid-pass) and resolved here against the complete map. Without
     * this, export/import silently loses the credit↔original link and a cancelled
     * credit note in the imported company couldn't free its returned qty.
     *
     * @param  list<array<string,mixed>>  $invoiceLineRows
     * @param  array<string, array<int|string,int>>  $maps
     */
    private function patchInvoiceLineSelfRefs(array $invoiceLineRows, array $maps): void
    {
        $lineMap = $maps['invoice_lines'] ?? [];
        foreach ($invoiceLineRows as $row) {
            $oldId = $row['id'] ?? null;
            if ($oldId === null || ! isset($lineMap[$oldId])) {
                continue;
            }
            $patch = [];
            foreach (self::INVOICE_LINE_SELF_REFS as $col) {
                $oldRef = $row[$col] ?? null;
                if ($oldRef !== null && isset($lineMap[$oldRef])) {
                    $patch[$col] = $lineMap[$oldRef];
                }
            }
            if ($patch !== []) {
                DB::table('invoice_lines')->where('id', $lineMap[$oldId])->update($patch);
            }
        }
    }

    /**
     * Restore invoice_types.default_customer_id (a setup→transactional FK that
     * couldn't be rewired at setup time, since customers import later). No-op for
     * a settings-only bundle (no customers map → the column stays null).
     *
     * @param  list<array<string,mixed>>  $invoiceTypeRows
     * @param  array<string, array<int|string,int>>  $maps
     */
    private function patchInvoiceTypeDefaults(array $invoiceTypeRows, array $maps): void
    {
        $typeMap = $maps['invoice_types'] ?? [];
        $custMap = $maps['customers'] ?? [];
        if ($custMap === []) {
            return;
        }
        foreach ($invoiceTypeRows as $row) {
            $oldId = $row['id'] ?? null;
            $oldCust = $row['default_customer_id'] ?? null;
            if ($oldId === null || $oldCust === null || ! isset($typeMap[$oldId], $custMap[$oldCust])) {
                continue;
            }
            DB::table('invoice_types')->where('id', $typeMap[$oldId])->update(['default_customer_id' => $custMap[$oldCust]]);
        }
    }

    /**
     * Per-table insert/update counts (no writes) for the dry-run plan.
     *
     * @return array<string, array{insert:int, update:int}>
     */
    private function plan(array $bundle, ?Company $existing): array
    {
        $plan = [];
        $sections = [];
        foreach (self::ORDER as $t) {
            $sections[$t] = $bundle['setup'][$t] ?? [];
        }
        foreach (self::ORDER_TRANSACTIONAL as $t) {
            $sections[$t] = $bundle['data'][$t] ?? [];
        }

        foreach ($sections as $table => $rows) {
            if ($rows === [] || ! Schema::hasTable($table)) {
                continue;
            }
            $index = $existing ? $this->existingIndex($table, $existing->id) : [];
            // customers also merge by ΑΦΜ identity (see importTable) — the dry-run
            // must say so, or the operator approves inserts that become overwrites.
            $afmIndex = ($existing && $table === 'customers') ? $this->afmKeyIndex($existing->id) : [];
            $insert = 0;
            $update = 0;
            foreach ($rows as $row) {
                $hit = isset($index[$this->naturalKey($table, $row)])
                    || ($afmIndex !== [] && isset($afmIndex[Afm::uniqueKey($row['afm'] ?? null) ?? '']));
                $hit ? $update++ : $insert++;
            }
            $plan[$table] = ['insert' => $insert, 'update' => $update];
        }

        return $plan;
    }

    /**
     * @return array<int|string, int> old exported id → resulting id
     */
    private function importTable(string $table, array $rows, int $companyId, array $maps): array
    {
        $map = [];
        if ($rows === [] || ! Schema::hasTable($table)) {
            return $map;
        }

        $index = $this->existingIndex($table, $companyId);
        // customers: ΑΦΜ identity → local id, built once and kept current so a
        // 10k-row import costs one scan, not one SELECT per row.
        $afmIndex = $table === 'customers' ? $this->afmKeyIndex($companyId) : [];

        foreach ($rows as $row) {
            $oldId = $row['id'] ?? null;
            $data = $this->rowData($table, $row, $companyId, $maps);
            $key = $this->naturalKey($table, $row);
            $afmKey = $table === 'customers' ? ($data['afm_key'] ?? null) : null;

            $existingId = $index[$key] ?? null;
            $mergedByAfm = false;

            if ($table === 'customers' && $afmKey !== null) {
                $afmOwner = $afmIndex[$afmKey] ?? null;

                if ($existingId === null && $afmOwner !== null) {
                    // No natural-key twin, but a local row owns this ΑΦΜ: that IS
                    // the customer (the unique index would reject an insert).
                    $existingId = $afmOwner;
                    $mergedByAfm = true;
                } elseif ($existingId !== null && $afmOwner !== null && $afmOwner !== $existingId) {
                    // The natural-key twin would take an ΑΦΜ another local row
                    // already owns → fail closed with guidance, never a raw
                    // unique error mid-transaction.
                    throw new RuntimeException(
                        'Σύγκρουση ΑΦΜ στην εισαγωγή πελατών: η γραμμή του bundle «'.($row['name'] ?? '?').'» '
                        ."(ΑΦΜ {$afmKey}) αντιστοιχεί στον τοπικό πελάτη #{$existingId}, αλλά το ΑΦΜ το έχει ήδη ο #{$afmOwner}. "
                        .'Διόρθωσε/συγχώνευσε τους δύο τοπικούς πελάτες (php artisan customers:afm-duplicates) και ξαναπροσπάθησε.'
                    );
                }
            }

            if ($existingId !== null) {
                $id = (int) $existingId;
                // Merge keeps the LOCAL soft-delete state: a row deleted here
                // after the export must not be resurrected by its bundle twin.
                unset($data['deleted_at']);
                // An ΑΦΜ-merge never blanks the local legacy_id (the ETL's
                // re-run key) with a bundle row that has none.
                if ($mergedByAfm && ($data['legacy_id'] ?? null) === null) {
                    unset($data['legacy_id']);
                }
                DB::table($table)->where('id', $id)->update($data);
            } else {
                $id = DB::table($table)->insertGetId($data);
            }

            if ($afmKey !== null) {
                $afmIndex[$afmKey] = $id;
            }

            if ($oldId !== null) {
                $map[$oldId] = $id;
            }
        }

        return $map;
    }

    /**
     * Strip surrogate/lifecycle columns, stamp the target company, rewire FKs.
     *
     * @return array<string,mixed>
     */
    private function rowData(string $table, array $row, int $companyId, array $maps): array
    {
        foreach (self::DROP_COLUMNS as $col) {
            unset($row[$col]);
        }
        $row['company_id'] = $companyId;

        foreach (self::FK_REWIRES[$table] ?? [] as $col => $sourceTable) {
            $old = $row[$col] ?? null;
            $row[$col] = ($old !== null && isset($maps[$sourceTable][$old])) ? $maps[$sourceTable][$old] : null;
        }

        // Keep a polymorphic pair consistent: cmr_notes.source is dropped on
        // import (the id is nulled above via FK_REWIRES), so null its *_type too —
        // otherwise the row keeps a stale source_type with a null source_id.
        if ($table === 'cmr_notes' && ($row['source_id'] ?? null) === null) {
            $row['source_type'] = null;
        }

        // customers.afm_key is derived, never trusted from a bundle (older
        // bundles lack the column entirely).
        if ($table === 'customers') {
            $row['afm_key'] = Afm::uniqueKey($row['afm'] ?? null);
        }

        $row['created_at'] = now();
        $row['updated_at'] = now();

        return $row;
    }

    /**
     * @return array<string, int> customers.afm_key => id (soft-deleted included — the unique index covers them)
     */
    private function afmKeyIndex(int $companyId): array
    {
        return DB::table('customers')
            ->where('company_id', $companyId)
            ->whereNotNull('afm_key')
            ->pluck('id', 'afm_key')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return array<string, int> natural-key => existing id
     */
    private function existingIndex(string $table, int $companyId): array
    {
        $index = [];
        foreach (DB::table($table)->where('company_id', $companyId)->get() as $row) {
            $index[$this->naturalKey($table, (array) $row)] = $row->id;
        }

        return $index;
    }

    /**
     * Natural key for idempotent matching: the COMPOSITE of every non-empty
     * key column (so two billing_connections of the same source disambiguate by
     * label, and bank_accounts still effectively fall back iban→account_name
     * since an empty column is skipped). When no key column is populated, fall
     * back to a content signature so even a key-less lookup row converges on
     * re-import instead of duplicating.
     */
    private function naturalKey(string $table, array $row): string
    {
        $parts = [];
        foreach (self::KEYS[$table] ?? [] as $col) {
            $value = $row[$col] ?? null;
            if ($value !== null && $value !== '') {
                $parts[] = $col.'='.$value;
            }
        }

        if ($parts !== []) {
            return implode('|', $parts);
        }

        return 'sig='.$this->rowSignature($row);
    }

    /**
     * Stable hash of a row's content columns (id/company_id/timestamps/legacy_id
     * stripped) — the fallback identity for rows with no populated natural key.
     */
    private function rowSignature(array $row): string
    {
        // deleted_at is lifecycle, not identity: a locally soft-deleted row must
        // still match its bundle twin (else merge inserts a live duplicate).
        foreach ([...self::DROP_COLUMNS, 'legacy_id', 'deleted_at'] as $col) {
            unset($row[$col]);
        }
        ksort($row);

        return md5((string) json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $companyData
     * @param  array<string,mixed>  $secrets
     * @return array<string,mixed>
     */
    private function companyAttributes(array $companyData, array $secrets): array
    {
        unset($companyData['logo_export_name']); // export-only meta, not a column

        // Opened (plaintext) secrets → the MaybeEncrypted cast stores them in the
        // target VM's at-rest form on save (encrypted under its APP_KEY, or plain).
        foreach ($secrets as $col => $value) {
            $companyData[$col] = $value;
        }

        // Keep only real `companies` columns: the exported row came from
        // attributesToArray(), which can carry non-column aggregates/appends
        // (e.g. users_count from a withCount() list query) that would break the
        // INSERT with "Unknown column". Defends older bundles too.
        //
        // (b) Import assumes a SAME-OR-NEWER target schema. If a bundle from a
        // newer schema carries a `companies` column this target lacks, it is
        // dropped here (and logged) rather than failing — a deliberate trade-off
        // so a stray attribute can't wedge the restore. Cross-version bundles
        // aren't a supported path: deploy the code first, then import.
        $filtered = array_intersect_key($companyData, array_flip(Schema::getColumnListing('companies')));

        // (a) Surface what was dropped — usually a harmless aggregate, but a
        // real column here means a schema skew worth a second look.
        $dropped = array_keys(array_diff_key($companyData, $filtered));
        if ($dropped !== []) {
            Log::warning('CompanyImporter: dropped non-column attributes from the company payload.', [
                'dropped' => $dropped,
            ]);
        }

        return $filtered;
    }

    private function restoreLogo(Company $company, array $bundle): void
    {
        $name = $bundle['company']['logo_export_name'] ?? null;
        $path = $company->logo_path;
        if (! is_string($name) || ! is_string($path) || $path === '') {
            return;
        }

        $bytes = $bundle['files']['files/'.$name] ?? null;
        if (! is_string($bytes) || $bytes === '') {
            return;
        }

        try {
            Storage::disk('public')->put($path, $bytes);
        } catch (\Throwable) {
            // A logo write failure must not abort a settings restore.
        }
    }
}
