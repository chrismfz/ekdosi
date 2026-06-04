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
 * Known v1 limitation: servers/server_groups carry their own APP_KEY-encrypted
 * `secret_encrypted`, exported as raw ciphertext — portable only within the
 * same APP_KEY. Cross-VM those server secrets must be re-entered. (Follow-up:
 * passphrase-seal them too.)
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
        'billing_connections' => ['source'],
        'invoice_types' => ['code'],
        'servers' => ['name'],
    ];

    /** Import order: independent tables first, then ones with intra-setup FKs. */
    private const ORDER = [
        'distribution_aims', 'delivery_methods', 'vat_categories', 'payment_methods',
        'bank_accounts', 'product_categories', 'metric_units', 'tags',
        'server_groups', 'billing_connections', 'invoice_types', 'servers',
    ];

    /** FK columns to rewire via the imported old-id → new-id maps. */
    private const FK_REWIRES = [
        'invoice_types' => ['distribution_aim_id' => 'distribution_aims', 'delivery_method_id' => 'delivery_methods'],
        'servers' => ['server_group_id' => 'server_groups'],
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
        });

        return $summary;
    }

    /**
     * Per-table insert/update counts (no writes) for the dry-run plan.
     *
     * @return array<string, array{insert:int, update:int}>
     */
    private function plan(array $bundle, ?Company $existing): array
    {
        $plan = [];
        foreach (self::ORDER as $table) {
            $rows = $bundle['setup'][$table] ?? [];
            if ($rows === [] || ! Schema::hasTable($table)) {
                continue;
            }
            $index = $existing ? $this->existingIndex($table, $existing->id) : [];
            $insert = 0;
            $update = 0;
            foreach ($rows as $row) {
                $key = $this->naturalKey($table, $row);
                ($key !== null && isset($index[$key])) ? $update++ : $insert++;
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

            if ($key !== null && isset($index[$key])) {
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
            $key = $this->naturalKey($table, (array) $row);
            if ($key !== null) {
                $index[$key] = $row->id;
            }
        }

        return $index;
    }

    /**
     * First non-empty natural-key column value (lets bank_accounts fall back
     * from iban → account_name). Null when none present → always inserts.
     */
    private function naturalKey(string $table, array $row): ?string
    {
        foreach (self::KEYS[$table] ?? [] as $col) {
            $value = $row[$col] ?? null;
            if ($value !== null && $value !== '') {
                return $col.'='.$value;
            }
        }

        return null;
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
