<?php

namespace App\Services\Portability;

use App\Casts\MaybeEncrypted;
use App\Models\Company;
use App\Models\PaymentGatewayConnection;
use App\Models\Scopes\CompanyScope;
use App\Services\TenantRoleProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * the per-company portability feature (FEATURES.md) — builds a per-company
 * SETTINGS + SETUP bundle (buckets A + B): the `companies` row (secrets sealed
 * via SecretsCodec, logo bundled) plus the operator-curated lookup tables, and
 * the sealed payment-method connections (SEALED_TABLES — their encrypted config
 * re-sealed under the passphrase so it survives a new APP_KEY).
 *
 * Transactional data (bucket C) rides only in a `--full` bundle. The bundle is
 * an in-memory structure; the command serialises it to a .zip (BundleArchive).
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
        'expense_classification_rules',
        'whmcs_income_maps',
        'whmcs_payment_maps',
    ];

    /**
     * Bucket C — transactional data (only in a `--full` bundle). Dumped as-is;
     * the importer rewires FKs. Deferred (v1): delivery notes, service contracts,
     * stock movements, pending WHMCS inbox, activity log, notes, attachments,
     * tag pivots — polymorphic / re-derivable, see FEATURES.md.
     *
     * @var list<string>
     */
    public const TRANSACTIONAL_TABLES = [
        'customers', 'customer_contacts', 'suppliers',
        'leads', 'lead_activities',
        'products', 'product_price_tiers', 'product_billing_prices',
        'invoices', 'invoice_lines', 'mydata_marks', 'return_invoice_extras', 'invoice_mail_log',
        'payments',
        'quotes', 'quote_lines', 'quote_mail_logs',
        'expenses', 'expense_lines', 'expense_marks',
        'cmr_notes', 'cmr_lines',
    ];

    /**
     * Tenant-owned tables (BelongsToCompany) DELIBERATELY left out of the bundle —
     * each with a reason. `CompanyExportCoverageTest` enforces that EVERY
     * BelongsToCompany model's table is in SETUP_TABLES ∪ TRANSACTIONAL_TABLES ∪
     * this list, so a NEW tenant table added later cannot silently fall out of
     * backup/export: the test fails until it is classified here or in a bucket.
     *
     * @var list<string>
     */
    public const INTENTIONALLY_EXCLUDED = [
        // Polymorphic / re-attachable on the OTHER subject — exported with their
        // owner when full-bundle attachment support lands (Phase 2 follow-up).
        'attachments',
        'notes',
        // Ψηφιακό ΔΑ (delivery) — its own re-issuable lifecycle; not part of the
        // accounting dataset a tenant carries across VMs (deferred bucket-C set).
        'delivery_notes', 'delivery_note_lines', 'delivery_note_events', 'delivery_marks',
        // Re-derivable / operational, not source-of-truth tenant data.
        'pending_whmcs_invoices',  // WHMCS inbox — re-fetched from the bridge.
        'stock_movements',         // re-derived from invoices/delivery notes.
        'service_contracts',       // deferred bucket-C (recurring-billing layer).
        'firebird_import_runs',    // ETL run log — operational, not portable data.
        // Backup config + run log are VM-specific (destinations/paths/passphrase
        // are reconfigured on the target VM) — never travel inside a bundle.
        'company_backup_settings',
        'company_backup_runs',
        // Payment intents are operational/transient — a «customer started to pay»
        // record. The actual money lives in `payments` (exported); a settled intent
        // is re-derivable from it, a pending one is ephemeral. Not source-of-truth.
        'payment_intents',
        // «Log πύλης» — a diagnostic/audit trail of inbound gateway returns. Runtime
        // history bound to THIS deployment's traffic, not config or accounting data;
        // it re-accrues on the target as returns arrive. Never travels in the bundle.
        'payment_gateway_events',
        // AI «Βοηθός» operational state — metering/billing log + the transient
        // confirm queue & reminders. Not part of the accounting dataset a tenant
        // carries across VMs (re-accrues per usage; pending actions are ephemeral).
        'ai_usage_log',
        'ai_pending_actions',
        // Support/Ticket pillar (Πυλώνας E) — NEW and default-off. Wiring its
        // export/import (FK-rewiring ticket→customer/department/assignee and
        // message→ticket, plus the encrypted per-mailbox creds) into the per-tenant
        // portability bundle is a dedicated later slice; until then the support
        // dataset stays out of the bundle (whole-DB backups still cover it).
        // Tracked in docs/BACKLOG.md «Πυλώνας E».
        'ticket_departments',
        'tickets',
        'ticket_messages',
        'canned_reply_categories',
        'canned_replies',
        'ticket_poll_runs', // IMAP poll health log — runtime, re-accrues per poll.
        'ticket_watchers', // per-ticket watchers/CC — travels with tickets once FK-rewiring is written.
        'ticket_blocked_senders', // per-tenant spam blocklist — operational, re-created as needed.
        // Domains pillar (Πυλώνας A) — NEW and default-off. A0 rows are account
        // shells (registrar/label/mode); the per-registrar creds land at A2, and
        // THAT slice adds these to the passphrase-sealed machinery next to
        // payment_gateway_connections (raw APP_KEY ciphertext must never travel).
        // Tracked in docs/BACKLOG.md «Πυλώνας A».
        'domain_registrar_connections',
    ];

    /**
     * Secret columns on DUMPED setup/transactional tables that must NOT travel in
     * a bundle. The company row's own secrets are passphrase-sealed separately
     * (see secretColumns()), but these rows are dumped raw — and with at-rest
     * encryption optional (config ekdosi.secrets.encrypt_at_rest) they could be
     * plaintext, so we redact them to null. The operator re-enters server creds on
     * the target VM (infra credentials, not part of the accounting dataset).
     *
     * @var array<string, list<string>>
     */
    public const REDACTED_COLUMNS = [
        'servers' => ['secret_encrypted'],
        'server_groups' => ['secret_encrypted'],
    ];

    /**
     * Tenant tables carried via the SEALED, model-based path (not the raw
     * setup/transactional dump): their `config` is encrypted-at-rest, so a raw dump
     * would ship APP_KEY ciphertext that can't be opened on the target VM. Instead
     * we load them through Eloquent (config decrypted), seal the config under the
     * passphrase (like the company's own secrets), and the importer re-encrypts it
     * under the target's APP_KEY. `CompanyExportCoverageTest` counts these as
     * classified. Always carried (SETTINGS, not gated on --full).
     *
     * @var list<string>
     */
    public const SEALED_TABLES = [
        // Payment methods incl. the Eurobank mid + Shared Secret (config encrypted).
        // Devbox → production: the operator re-adds NOTHING — the methods land
        // configured, secrets and all (portal logins stay OUT, by design).
        'payment_gateway_connections',
    ];

    public function __construct(
        private readonly SecretsCodec $codec,
        private readonly TenantRoleProvisioner $roles,
    ) {}

    /**
     * @return array{
     *     manifest: array<string,mixed>,
     *     company: array<string,mixed>,
     *     secrets: array{mode:string, salt?:string, values:array<string,?string>},
     *     setup: array<string, list<array<string,mixed>>>,
     *     data: array<string, list<array<string,mixed>>>,
     *     users: list<array{email:string, name:string, role:?string}>,
     *     connections: array{rows: list<array<string,mixed>>, secrets: array<string,mixed>},
     *     files: array<string,string>
     * }
     */
    public function build(Company $company, string $secretsMode, ?string $passphrase, bool $full = false): array
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

        // attributesToArray() can include non-column aggregates/appends (e.g.
        // users_count from a withCount() list query) — keep only real columns so
        // the bundle stays clean and the import INSERT can't hit "Unknown column".
        // (logo_export_name is added AFTER this, deliberately.)
        $companyData = array_intersect_key($companyData, array_flip(Schema::getColumnListing('companies')));

        $sealed = $this->codec->seal($secrets, $secretsMode, $passphrase);

        $counts = [];
        $setup = $this->dump(self::SETUP_TABLES, $company->id, $counts);
        $data = $full ? $this->dump(self::TRANSACTIONAL_TABLES, $company->id, $counts) : [];

        // Assigned operators — part of SETTINGS (who can log in), always carried
        // (not gated on --full). No passwords/ids travel (see exportUsers()).
        $users = $this->exportUsers($company);
        $counts['users'] = count($users);

        // Sealed connections (payment methods incl. their encrypted config) — the
        // config secret rides passphrase-sealed, NOT as raw APP_KEY ciphertext.
        $connections = $this->buildConnections($company, $secretsMode, $passphrase);
        $counts['payment_gateway_connections'] = count($connections['rows']);

        $files = [];
        if (($logo = $this->logo($company)) !== null) {
            $files['files/'.$logo['name']] = $logo['bytes'];
            $companyData['logo_export_name'] = $logo['name'];
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'app' => 'ekdosi',
            'kind' => $full ? 'company-full' : 'company-settings-setup',
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
            'data' => $data,
            'users' => $users,
            'connections' => $connections,
            'files' => $files,
        ];
    }

    /**
     * Payment-method connections (SEALED_TABLES), loaded through Eloquent so the
     * encrypted `config` decrypts, then re-sealed under the passphrase. Clear
     * metadata (gateway/label/is_active/sort) rides in `rows`; the per-connection
     * `config` (mid + Shared Secret + settings) rides in `secrets`, index-aligned,
     * sealed exactly like the company's own secrets — never raw APP_KEY ciphertext.
     *
     * @return array{rows: list<array<string,mixed>>, secrets: array{mode:string, salt?:string, values:array<string,?string>}}
     */
    private function buildConnections(Company $company, string $secretsMode, ?string $passphrase): array
    {
        $rows = [];
        $configs = [];
        if (Schema::hasTable('payment_gateway_connections')) {
            $connections = PaymentGatewayConnection::query()
                ->withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $company->id)
                ->orderBy('sort')
                ->orderBy('id')
                ->get();

            foreach ($connections as $i => $conn) {
                // A stable per-connection ref ties a row to its sealed config, so the
                // two never depend on positional array index (a diverged/edited
                // bundle can't attach one connection's secret to another's row).
                $ref = 'c'.$i;
                $rows[] = [
                    'ref' => $ref,
                    'gateway' => (string) $conn->gateway,
                    'label' => $conn->label,
                    'is_active' => (bool) $conn->is_active,
                    'sort' => (int) $conn->sort,
                    // The channel's default myDATA method (a payment_methods FK) —
                    // rewired to the target's imported payment_methods on import.
                    'payment_method_id' => $conn->payment_method_id,
                ];
                // `config` is the decrypted array (encrypted:array cast); seal it.
                $configs[$ref] = $conn->config ?? [];
            }
        }

        return [
            'rows' => $rows,
            'secrets' => $this->codec->seal($configs, $secretsMode, $passphrase),
        ];
    }

    /**
     * The operators assigned to this company — email + name + their single
     * managed role (super_admin|company_admin|operator|null), so a restore can
     * re-attach the team on a fresh VM. Deliberately NO passwords, ids or other
     * credentials: users are panel-global, so only the MEMBERSHIP + role are
     * portable. On import an existing user is matched by email; a missing one is
     * created with a random password (login only via password-reset). Always in
     * the bundle (SETTINGS, not gated on --full) — «who can log in» is config.
     *
     * @return list<array{email:string, name:string, role:?string}>
     */
    private function exportUsers(Company $company): array
    {
        $out = [];
        foreach ($company->users()->get(['users.id', 'users.name', 'users.email']) as $user) {
            $out[] = [
                'email' => (string) $user->email,
                'name' => (string) $user->name,
                'role' => $this->roles->roleInCompany($user, $company),
            ];
        }

        return $out;
    }

    /**
     * Dump the given company-scoped tables to row arrays, accumulating counts.
     *
     * @param  list<string>  $tables
     * @param  array<string,int>  $counts
     * @return array<string, list<array<string,mixed>>>
     */
    private function dump(array $tables, int $companyId, array &$counts): array
    {
        $out = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $redact = self::REDACTED_COLUMNS[$table] ?? [];
            $rows = DB::table($table)
                ->where('company_id', $companyId)
                ->get()
                ->map(function ($row) use ($redact): array {
                    $arr = (array) $row;
                    foreach ($redact as $col) {
                        if (array_key_exists($col, $arr)) {
                            $arr[$col] = null;   // never carry a secret in a bundle
                        }
                    }

                    return $arr;
                })
                ->all();
            $out[$table] = $rows;
            $counts[$table] = count($rows);
        }

        return $out;
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
            // Secret columns are sealed regardless of whether they're stored
            // encrypted or plaintext at rest (the cast may be MaybeEncrypted now).
            if (MaybeEncrypted::isSecretCast((string) $cast)) {
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
