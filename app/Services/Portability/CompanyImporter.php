<?php

namespace App\Services\Portability;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
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
 * Secrets are opened with the passphrase and written back through Eloquent, so
 * they are re-encrypted under the TARGET VM's APP_KEY on save.
 *
 * Known v1 limitations:
 *  - servers/server_groups carry their own APP_KEY-encrypted `secret_encrypted`,
 *    exported as raw ciphertext — portable only within the same APP_KEY.
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
    ];

    /** Import order for transactional (bucket C, --full): parents before children. */
    private const ORDER_TRANSACTIONAL = [
        'customers', 'customer_contacts', 'suppliers',
        'products', 'product_price_tiers', 'product_billing_prices',
        'invoices', 'invoice_lines', 'mydata_marks', 'return_invoice_extras', 'invoice_mail_log',
        'payments',
        'quotes', 'quote_lines', 'quote_mail_logs',
        'expenses', 'expense_lines', 'expense_marks',
    ];

    /** Invoice self-reference columns — nulled on insert, patched after the pass. */
    private const INVOICE_SELF_REFS = ['credited_invoice_id', 'conv_invoice_id'];

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
        'invoice_lines' => ['invoice_id' => 'invoices', 'product_id' => 'products'],
        'mydata_marks' => ['invoice_id' => 'invoices'],
        'return_invoice_extras' => ['invoice_line_id' => 'invoice_lines'],
        'invoice_mail_log' => ['invoice_id' => 'invoices', 'triggered_by_user_id' => 'users'],
        'payments' => ['customer_id' => 'customers', 'invoice_id' => 'invoices', 'payment_method_id' => 'payment_methods', 'bank_account_id' => 'bank_accounts'],
        'quotes' => [
            'customer_id' => 'customers', 'converted_invoice_id' => 'invoices',
            'converted_service_contract_id' => 'service_contracts', // deferred → nulled
        ],
        'quote_lines' => ['quote_id' => 'quotes', 'product_id' => 'products'],
        'quote_mail_logs' => ['quote_id' => 'quotes', 'triggered_by_user_id' => 'users'],
        'expenses' => ['supplier_id' => 'suppliers'],
        'expense_lines' => ['expense_id' => 'expenses'],
        'expense_marks' => ['expense_id' => 'expenses'],
    ];

    private const DROP_COLUMNS = ['id', 'company_id', 'created_at', 'updated_at', 'deleted_at'];

    public function __construct(private readonly SecretsCodec $codec) {}

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

        DB::transaction(function () use ($bundle, $secrets, $new, $existing): void {
            $attrs = $this->companyAttributes($bundle['company'], $secrets);
            $whmcsTypeOld = $attrs['whmcs_default_invoice_type_id'] ?? null;
            $attrs['whmcs_default_invoice_type_id'] = null; // rewired after invoice_types

            $company = $new ? new Company : $existing;
            $company->forceFill($attrs)->save();

            $this->restoreLogo($company, $bundle);

            $maps = [];
            foreach (self::ORDER as $table) {
                $maps[$table] = $this->importTable($table, $bundle['setup'][$table] ?? [], $company->id, $maps);
            }

            if ($whmcsTypeOld !== null && isset($maps['invoice_types'][$whmcsTypeOld])) {
                $company->forceFill(['whmcs_default_invoice_type_id' => $maps['invoice_types'][$whmcsTypeOld]])->save();
            }

            // Bucket C (full bundle): transactional, parents before children.
            foreach (self::ORDER_TRANSACTIONAL as $table) {
                $maps[$table] = $this->importTable($table, $bundle['data'][$table] ?? [], $company->id, $maps);
            }
            $this->patchInvoiceSelfRefs($bundle['data']['invoices'] ?? [], $maps);
            // invoice_types.default_customer_id → customers (imported just now).
            $this->patchInvoiceTypeDefaults($bundle['setup']['invoice_types'] ?? [], $maps);
        });

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
            $insert = 0;
            $update = 0;
            foreach ($rows as $row) {
                isset($index[$this->naturalKey($table, $row)]) ? $update++ : $insert++;
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

        foreach ($rows as $row) {
            $oldId = $row['id'] ?? null;
            $data = $this->rowData($table, $row, $companyId, $maps);
            $key = $this->naturalKey($table, $row);

            if (isset($index[$key])) {
                $id = $index[$key];
                DB::table($table)->where('id', $id)->update($data);
            } else {
                $id = DB::table($table)->insertGetId($data);
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

        $row['created_at'] = now();
        $row['updated_at'] = now();

        return $row;
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
        foreach ([...self::DROP_COLUMNS, 'legacy_id'] as $col) {
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

        // Plaintext secrets → Eloquent re-encrypts under the target APP_KEY on save.
        foreach ($secrets as $col => $value) {
            $companyData[$col] = $value;
        }

        return $companyData;
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
