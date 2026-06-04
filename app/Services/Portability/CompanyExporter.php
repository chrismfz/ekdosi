<?php

namespace App\Services\Portability;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1 of docs/company-portability-plan.md — builds a per-company
 * SETTINGS + SETUP bundle (buckets A + B): the `companies` row (secrets sealed
 * via SecretsCodec, logo bundled) plus the operator-curated lookup tables.
 *
 * Transactional data (bucket C) is Phase 2 and not touched here. The bundle is
 * an in-memory structure; the command serialises it to a .zip.
 */
class CompanyExporter
{
    public const SCHEMA_VERSION = 1;

    /**
     * Bucket B — setup/lookup tables kept on a rebuild (the operator-curated
     * config), incl. the 3 ekdosi-native config tables that aren't in Firebird.
     *
     * @var list<string>
     */
    public const SETUP_TABLES = [
        'invoice_types',
        'payment_methods',
        'bank_accounts',
        'delivery_methods',
        'distribution_aims',
        'product_categories',
        'vat_categories',
        'metric_units',
        'tags',
        'servers',
        'server_groups',
        'billing_connections',
    ];

    public function __construct(private readonly SecretsCodec $codec) {}

    /**
     * @return array{
     *     manifest: array<string,mixed>,
     *     company: array<string,mixed>,
     *     secrets: array{mode:string, salt?:string, values:array<string,?string>},
     *     setup: array<string, list<array<string,mixed>>>,
     *     files: array<string,string>
     * }
     */
    public function build(Company $company, string $secretsMode, ?string $passphrase): array
    {
        // attributesToArray() applies casts → the encrypted columns decrypt to
        // plaintext; we pull those into the sealed secrets and strip them from
        // the company payload so no secret is ever written in the clear there.
        // The secret set is DERIVED from the model's `encrypted*` casts (not a
        // hand-kept list) so a future encrypted column can't silently leak.
        $companyData = $company->attributesToArray();

        $secrets = [];
        foreach ($this->secretColumns($company) as $col) {
            $secrets[$col] = $company->{$col};
            unset($companyData[$col]);
        }

        // Surrogate id + lifecycle timestamps are re-assigned on import.
        unset($companyData['id'], $companyData['created_at'], $companyData['updated_at'], $companyData['deleted_at']);

        $sealed = $this->codec->seal($secrets, $secretsMode, $passphrase);

        $setup = [];
        $counts = [];
        foreach (self::SETUP_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)
                ->where('company_id', $company->id)
                ->get()
                ->map(fn ($row): array => (array) $row)
                ->all();
            $setup[$table] = $rows;
            $counts[$table] = count($rows);
        }

        $files = [];
        if (($logo = $this->logo($company)) !== null) {
            $files['files/'.$logo['name']] = $logo['bytes'];
            $companyData['logo_export_name'] = $logo['name'];
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => 'ekdosi',
            'kind' => 'company-settings-setup',
            'exported_at' => now()->toIso8601String(),
            'company' => [
                'slug' => $company->slug,
                'afm' => $company->afm,
                'name' => $company->name,
            ],
            'secrets_mode' => $sealed['mode'],
            'counts' => $counts,
        ];

        return [
            'manifest' => $manifest,
            'company' => $companyData,
            'secrets' => $sealed,
            'setup' => $setup,
            'files' => $files,
        ];
    }

    /**
     * Columns the model encrypts at rest (`encrypted` / `encrypted:array` …) —
     * the exact set to seal under the passphrase instead of writing in the clear.
     *
     * @return list<string>
     */
    private function secretColumns(Company $company): array
    {
        $cols = [];
        foreach ($company->getCasts() as $column => $cast) {
            if ($cast === 'encrypted' || str_starts_with((string) $cast, 'encrypted:')) {
                $cols[] = $column;
            }
        }

        return $cols;
    }

    /**
     * Tenant logo bytes + a safe export filename, or null when absent/missing.
     *
     * @return array{name:string, bytes:string}|null
     */
    private function logo(Company $company): ?array
    {
        if (empty($company->logo_path)) {
            return null;
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($company->logo_path)) {
                return null;
            }
            $bytes = $disk->get($company->logo_path);
        } catch (\Throwable) {
            return null;
        }

        if ($bytes === null || $bytes === '') {
            return null;
        }

        // basename() strips any path; the import restores under a fresh path.
        return ['name' => basename($company->logo_path), 'bytes' => $bytes];
    }
}
