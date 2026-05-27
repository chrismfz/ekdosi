<?php

namespace App\Console\Commands;

use App\Services\Etl\TenantRowUpserter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Re-runnable ETL: legacy Firebird .fdb  ->  multi-tenant MariaDB.
 *
 * Usage:
 *   php artisan migrate:firebird \
 *       --company="MyIP" --slug=myip \
 *       --fdb="/opt/Data/ekdosi-myip.fdb" \
 *       --host=10.23.22.5 --fbuser=EKDOSI --fbpass=ekdosi1234
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
        {--company= : Display name of the company/tenant}
        {--slug= : URL slug for the tenant (Filament)}
        {--fdb= : Absolute path to the .fdb on the Firebird host}
        {--host=127.0.0.1 : Firebird host}
        {--fbuser=EKDOSI : Firebird user}
        {--fbpass= : Firebird password}';

    protected $description = 'Import a legacy Firebird ekdosi database into the multi-tenant MariaDB schema (re-run-safe)';

    private PDO $fb;
    private int $companyId;
    private TenantRowUpserter $upserter;

    /** legacy_id => new_id maps, per table, for FK remapping */
    private array $map = [
        'payment_methods'    => [],
        'delivery_methods'   => [],
        'distribution_aims'  => [],
        'metric_units'       => [],
        'vat_categories'     => [],
        'product_categories' => [],
        'customers'          => [],
        'invoice_types'      => [],
        'products'           => [],
        'invoices'           => [],
        'invoice_lines'      => [],
    ];

    public function handle(): int
    {
        foreach (['company', 'slug', 'fdb', 'fbpass'] as $req) {
            if (! $this->option($req)) {
                $this->error("Missing required --{$req}");
                return self::FAILURE;
            }
        }

        $this->connectFirebird();
        $this->companyId = $this->resolveCompany();
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
            $this->copyLookup('PAYMENT_METHOD',    'payment_methods',    'METHOD_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
                'due_days'    => $r['DUE_DAYS'],
            ]);
            $this->copyLookup('DELIVERY_METHOD',   'delivery_methods',   'METHOD_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
            ]);
            $this->copyLookup('DISTRIBUTION_AIM',  'distribution_aims',  'DISTAIM_ID', fn ($r) => [
                'description' => $this->fld($r, 'DESCRIPTION'),
            ]);
            $this->copyLookup('METRIC_UNITS',      'metric_units',       'METRIC_ID', fn ($r) => [
                'name'  => $this->fld($r, 'NAME'),
                'notes' => $this->fld($r, 'NOTES'),
            ]);
            $this->copyLookup('VAT_CATEGORY',      'vat_categories',     'VATCAT_ID', fn ($r) => [
                'rate'             => $r['VALUE'],
                'description'      => $this->fld($r, 'DESCRIPTION'),
                'long_description' => $this->fld($r, 'LONG_DESCRIPTION'),
                'is_default'       => (bool) $r['DEFAULT_CAT'],
            ]);

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
                'description'       => $this->fld($r, 'DESCRIPTION'),
                'markup'            => $r['MARKUP'],
            ]);

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

            // --- config / whmcs bridge ---
            $this->copyConfParams();
            $this->copyWhmcsLog();
        });

        $this->newLine();
        $this->info('Done. Run the golden-test comparison next (see README).');
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
        $existing = DB::table('companies')->where('slug', $this->option('slug'))->first();
        if ($existing) {
            return $existing->id;
        }
        return DB::table('companies')->insertGetId([
            'name'       => $this->option('company'),
            'slug'       => $this->option('slug'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
     */
    private function upsert(string $table, array $matchKeys, array $values): void
    {
        $this->upserter->upsert($table, $matchKeys, $values);
    }

    private function fbAll(string $sql): array
    {
        return $this->fb->query($sql)->fetchAll(PDO::FETCH_ASSOC);
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
            . 'kept id=%d, demoted the rest. (Single-default invariant restored.)',
            $defaults->count(),
            $defaults->first(),
        ));
    }

    /** Generic copy for simple lookup tables. Re-run-safe upsert. */
    private function copyLookup(string $fbTable, string $target, string $pk, callable $row): void
    {
        $this->line("  {$fbTable} -> {$target}");
        foreach ($this->fbAll("SELECT * FROM {$fbTable}") as $r) {
            $id = $this->upsertGetId(
                $target,
                ['company_id' => $this->companyId, 'legacy_id' => $r[$pk]],
                array_merge($row($r), ['updated_at' => now()]),
                ['created_at' => now()],
            );
            $this->map[$target][(int) $r[$pk]] = $id;
        }
    }

    // ---------------------------------------------------------------- entities

    private function copyCustomers(): void
    {
        $this->line('  CUSTOMER -> customers');
        foreach ($this->fbAll('SELECT * FROM CUSTOMER') as $r) {
            // Filament-managed columns (is_active, needs_immediate_invoice,
            // peppol_endpoint, whmcs_client_id) are written ONLY on first
            // insert. On re-runs they stay untouched so operator
            // customisation in the panel survives.
            $id = $this->upsertGetId(
                'customers',
                ['company_id' => $this->companyId, 'legacy_id' => $r['CUST_ID']],
                [
                    'type'                   => $this->fld($r, 'TYPE'),
                    'afm'                    => $this->fld($r, 'AFM'),
                    'name'                   => $this->fld($r, 'NAME') ?? '(no name)',
                    'address1'               => $this->fld($r, 'ADDRESS1'),
                    'address2'               => $this->fld($r, 'ADDRESS2'),
                    'city'                   => $this->fld($r, 'CITY'),
                    'postcode'               => $this->fld($r, 'POSTCODE'),
                    'phone1'                 => $this->fld($r, 'PHONE1'),
                    'phone2'                 => $this->fld($r, 'PHONE2'),
                    'fax'                    => $this->fld($r, 'FAX'),
                    'occupation'             => $this->fld($r, 'OCCUPATION'),
                    'tax_office'             => $this->fld($r, 'TAXOFFICE'),
                    'details'                => $this->fld($r, 'DETAILS'),
                    'discount'               => $r['DISCOUNT'] ?? 0,
                    'email'                  => $this->fld($r, 'EMAIL'),
                    'secondary_email'        => $this->fld($r, 'SECONDARY_EMAIL'),
                    'country'                => $this->fld($r, 'COUNTRY'),
                    'vat_vies'               => $this->fld($r, 'VAT_VIES'),
                    'withhold_tax'           => $r['WITHHOLD_TAX'] ?? null,
                    'sort_order'             => $r['ORDER'] ?? null,
                    'alt_customer_legacy_id' => $r['ALT_CUSTID'] ?? null,
                    'payment_method_id'      => $this->legacyId('payment_methods', $r['PAYMETH_ID'] ?? null),
                    'updated_at'             => now(),
                ],
                [
                    // Filament-managed columns — defaults on first
                    // insert, never updated on re-runs.
                    'is_active'              => true,
                    'needs_immediate_invoice' => false,
                    'created_at'             => now(),
                ],
            );
            $this->map['customers'][(int) $r['CUST_ID']] = $id;
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
            $code = $this->fld($r, 'INVTYPE_ID');
            $existing = DB::table('invoice_types')
                ->where(['company_id' => $this->companyId, 'code' => $code])
                ->value('invcount');
            $invcount = max((int) ($r['INVCOUNT'] ?? 1), (int) ($existing ?? 0));

            $id = $this->upsertGetId(
                'invoice_types',
                ['company_id' => $this->companyId, 'code' => $code],
                [
                    'name'                         => $this->fld($r, 'NAME') ?? $r['INVTYPE_ID'],
                    'invcount'                     => $invcount,
                    'show_on_menu'                 => (bool) ($r['SHOW_ON_MENU'] ?? 1),
                    'is_credit'                    => (bool) ($r['CREDITINVOICE'] ?? 0),
                    'is_return'                    => (bool) ($r['RETURNINVOICE'] ?? 0),
                    'mydata_type'                  => $this->fld($r, 'MYDATA_TYPE'),
                    'mydata_income_class'          => $this->fld($r, 'MYDATA_INCOME_CLASS'),
                    'mydata_income_class_category' => $this->fld($r, 'MYDATA_INCOME_CLASS_CATEGORY'),
                    'distribution_aim_id'          => $this->legacyId('distribution_aims', $r['DISTAIM_ID']),
                    'delivery_method_id'           => $this->legacyId('delivery_methods', $r['DELIVERYMETHOD_ID']),
                    'payment_method_id'            => $this->legacyId('payment_methods', $r['PAYMETH_ID']),
                    'default_customer_id'          => $this->legacyId('customers', $r['CUST_ID']),
                    'updated_at'                   => now(),
                ],
                ['created_at' => now()],
            );
            // invoice_types keyed by its string code, not an int PK
            $this->map['invoice_types'][$code] = $id;
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
                    'barcode'             => $this->fld($r, 'BARCODE'),
                    'description_short'   => $this->fld($r, 'DESCRIPTION_SHORT') ?? '(no description)',
                    'description'         => $this->fld($r, 'DESCRIPTION'),
                    'product_category_id' => $this->legacyId('product_categories', $r['CAT_ID']),
                    'vat_category_id'     => $this->legacyId('vat_categories', $r['VATCAT_ID']),
                    'metric_unit_id'      => $this->legacyId('metric_units', $r['METRIC_ID']),
                    'buy_price'           => $r['BUY_PRICE'] ?? 0,
                    'sell_price'          => $r['SELL_PRICE'] ?? 0,
                    'price_wvat'          => $r['PRICE_WVAT'] ?? 0,
                    'reserve'             => $r['RESERVE'] ?? 0,
                    'reserve_secure'      => $r['RESERVE_SECURE'] ?? 0,
                    'date_inserted'       => $r['DATE_INSERTED'],
                    'updated_at'          => $r['LAST_UPDATE'] ?? now(),
                ],
                [
                    'is_active'  => true,
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
                    'product_id'       => $product,
                    'value'            => $r['VAL'],
                    'discount_percent' => $r['DISCOUNT_PERCENT'],
                    'qty'              => $r['QTY'],
                    'updated_at'       => now(),
                ],
            );
        }
    }

    private function copyInvoices(): void
    {
        $this->line('  INVOICE -> invoices');
        // pass 1: upsert without conv_invoice_id (self-reference resolved in pass 2)
        foreach ($this->fbAll('SELECT * FROM INVOICE') as $r) {
            $issuedAt = $this->mergeDateTime($r['INVDATE'], $r['INVTIME']);
            $id = $this->upsertGetId(
                'invoices',
                ['company_id' => $this->companyId, 'legacy_id' => $r['INVOICE_ID']],
                [
                'invcode'             => $this->fld($r, 'INVCODE'),
                'code'                => $r['CODE'] ?? 0,
                'invoice_type_id'     => $this->map['invoice_types'][$this->fld($r, 'INVTYPE')] ?? null,
                'customer_id'         => $this->legacyId('customers', $r['CUST_ID']),
                'issued_at'           => $issuedAt,
                'distribution_aim_id' => $this->legacyId('distribution_aims', $r['DISTRAIM_ID']),
                'delivery_method_id'  => $this->legacyId('delivery_methods', $r['DELMETHOD_ID']),
                'payment_method_id'   => $this->legacyId('payment_methods', $r['PAYMETH_ID']),
                'delivery_date'       => $r['DELIVERYDATE'] ?? null,
                'header_discount_percent' => $r['DISCOUNT'] ?? 0,
                'net_total'           => $r['PRICE'] ?? 0,
                'gross_total'         => $r['PRICEWVAT'] ?? 0,
                'withhold_amount'     => $r['WITHHOLD_AMOUNT'] ?? null,
                'mailed'              => (bool) ($r['MAILED'] ?? 0),
                'printed'             => (bool) ($r['PRINTED'] ?? 0),
                'address1'            => $this->fld($r, 'ADDRESS1'),
                'address2'            => $this->fld($r, 'ADDRESS2'),
                'city'                => $this->fld($r, 'CITY'),
                'postcode'            => $this->fld($r, 'POSTCODE'),
                'country'             => $this->fld($r, 'COUNTRY'),
                'company_name'        => $this->fld($r, 'COMPANY_NAME'),
                'vat_no'              => $this->fld($r, 'VAT_NO'),
                'vies_vat'            => $this->fld($r, 'VIES_VAT'),
                'occupation'          => $this->fld($r, 'OCCUPATION'),
                'notes'               => $this->fld($r, 'NOTES'),
                'email_sent'          => $this->fld($r, 'EMAIL_SENT'),
                'mydata_sent'         => isset($r['MYDATA_SENT']) ? (bool) $r['MYDATA_SENT'] : null,
                'mydata_state'        => $this->fld($r, 'MYDATA_STATE'),
                'mydata_mark'         => $this->fld($r, 'MYDATA_MARK'),
                'mydata_url'          => $this->fld($r, 'MYDATA_URL'),
                'updated_at'          => now(),
                ],
                ['created_at' => $issuedAt ?? now()],
            );
            $this->map['invoices'][(int) $r['INVOICE_ID']] = $id;
        }
        // pass 2: wire up conversion self-references
        foreach ($this->fbAll('SELECT INVOICE_ID, CONV_INVOICE_ID FROM INVOICE WHERE CONV_INVOICE_ID IS NOT NULL') as $r) {
            $self = $this->legacyId('invoices', $r['INVOICE_ID']);
            $conv = $this->legacyId('invoices', $r['CONV_INVOICE_ID']);
            if ($self && $conv) {
                DB::table('invoices')->where('id', $self)->update(['conv_invoice_id' => $conv]);
            }
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
                    'invoice_id'     => $invoice,
                    'product_id'     => $this->legacyId('products', $r['PRODUCT_ID']),
                    'qty'            => $r['QTY'] ?? 1,
                    'price_per_item' => $r['PRICE_PER_ITEM'],
                    'discount'       => $r['DISCOUNT'] ?? 0,
                    'vat_percent'    => $r['VATPERCENT'],
                    'net_price'      => $r['PRICE'],
                    'gross_price'    => $r['PRICEWVAT'],
                    'product_descr'  => $this->fld($r, 'PRODUCT_DESCR'),
                    'metric_unit'    => $this->fld($r, 'METRIC_UNIT'),
                    'notes'          => $this->fld($r, 'NOTES'),
                    'updated_at'     => now(),
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
                    'company_id'   => $this->companyId,
                    'qty_given'    => $r['QTY_GIVEN'],
                    'qty_returned' => $r['QTY_RETURNED'],
                    'qty_sent'     => $r['QTY_SENT'],
                    'updated_at'   => now(),
                ],
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
                    'pay_date'    => $r['PAY_DATE'],
                    'amount'      => $r['VALUE'],
                    'notes'       => $this->fld($r, 'NOTES'),
                    'updated_at'  => now(),
                ],
            );
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
            $this->upsert(
                'mydata_marks',
                ['company_id' => $this->companyId, 'legacy_id' => $r['ID']],
                [
                    'invoice_id'    => $this->legacyId('invoices', $r['INVOICE_ID']),
                    // After PR #24 the column is nullable. Legacy rows
                    // with no MARK (rare — pre-myDATA staging) now land
                    // as NULL instead of empty-string sentinels.
                    'mark'          => $this->fld($r, 'MARK'),
                    'mydata_action' => $this->fld($r, 'MYDATA_ACTION'),
                    'invoice_url'   => $this->fld($r, 'INVOICE_URL'),
                    'request'       => $this->fld($r, 'REQUEST'),
                    'response'      => $this->fld($r, 'RESPONSE'),
                    'mark_date'     => $r['DATE'],
                    'mark_time'     => $r['TIME'],
                    'updated_at'    => now(),
                ],
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
                    'data_int'       => $r['DATA_INT'],
                    'data_string'    => $this->fld($r, 'DATA_STRING'),
                    'data_timestamp' => $r['DATA_TIMESTAMP'],
                    'data_float'     => $r['DATA_FLOAT'],
                    'data_numeric'   => $r['DATA_NUMERIC'],
                    'updated_at'     => now(),
                ],
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
            $this->upsert(
                'whmcs_invoice_log',
                ['company_id' => $this->companyId, 'legacy_id' => $r['LOG_ID']],
                [
                    'whmcs_invoice_id' => $r['CS_INVID'],
                    'invoice_id'       => $this->legacyId('invoices', $r['CS_INVID']), // best-effort; adjust to your bridge semantics
                    'message'          => $this->fld($r, 'LOG_MESSAGE'),
                    'updated_at'       => now(),
                ],
            );
        }
    }

    private function mergeDateTime($date, $time): ?string
    {
        if (! $date) {
            return null;
        }
        $d = substr((string) $date, 0, 10);
        $t = $time ? substr((string) $time, 0, 8) : '00:00:00';
        return "{$d} {$t}";
    }
}
