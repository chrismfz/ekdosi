<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * One-time (re-runnable) ETL: legacy Firebird .fdb  ->  multi-tenant MariaDB.
 *
 * Usage:
 *   php artisan migrate:firebird \
 *       --company="MyIP" --slug=myip \
 *       --fdb="/opt/Data/ekdosi-myip.fdb" \
 *       --host=10.23.22.5 --fbuser=EKDOSI --fbpass=ekdosi1234
 *
 * Run once per legacy database (myip, nixpal, systemworx, ...). Each run is scoped
 * to ONE company_id and wipes only that company's rows first, so it is idempotent.
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

    protected $description = 'Import a legacy Firebird ekdosi database into the multi-tenant MariaDB schema';

    private PDO $fb;
    private int $companyId;

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

        $this->info("Importing into company_id={$this->companyId} ({$this->option('slug')})");

        DB::transaction(function () {
            // Filament-managed columns have no legacy source; preserve any
            // manual edits across ETL re-runs by snapshotting BEFORE the
            // wipe and re-applying AFTER the re-insert.
            $this->snapshotManualEdits();

            $this->wipeCompany();        // make the run idempotent

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

            // Re-apply Filament-managed columns onto the freshly-imported
            // customers. Must run AFTER copyCustomers() so the legacy_id →
            // new_id map is built (referred_by_customer_id remapping).
            $this->restoreManualEdits();

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

    private function wipeCompany(): void
    {
        // reverse dependency order
        foreach ([
            'whmcs_invoice_log', 'conf_params', 'mydata_marks', 'payments',
            'return_invoice_extras', 'invoice_lines', 'invoices',
            'product_price_tiers', 'products', 'invoice_types', 'customers',
            'product_categories', 'vat_categories', 'metric_units',
            'distribution_aims', 'delivery_methods', 'payment_methods',
        ] as $table) {
            DB::table($table)->where('company_id', $this->companyId)->delete();
        }
    }

    /**
     * Filament-managed columns on `customers` (added in PR #16) — these are
     * NOT sourced from Firebird, so a naive re-run of the ETL would destroy
     * any manual edits the operator made in the panel between runs (CRM
     * cleanup, peppol endpoint entry, is_active toggles, γκρινιάρης flags).
     *
     * Snapshot the relevant columns by legacy_id BEFORE wipeCompany() blows
     * them away; restoreManualEdits() re-applies after copyCustomers().
     *
     * The referred_by FK is captured as the TARGET's legacy_id (the
     * surrogate id changes on re-insert; legacy_id is stable). On restore
     * we remap back through $this->map['customers'].
     *
     * Customers created manually in Filament (no legacy_id) are NOT
     * preserved — wipeCompany() removes them like any other row. If a
     * legacy-imported customer was set as "referred by" a Filament-only
     * customer, the referrer becomes null on re-import (the target no
     * longer exists). That's correct.
     */
    private array $manualCustomerEdits = [];

    private function snapshotManualEdits(): void
    {
        $this->manualCustomerEdits = DB::table('customers as c1')
            ->leftJoin('customers as c2', function ($join) {
                $join->on('c1.referred_by_customer_id', '=', 'c2.id')
                    ->where('c2.company_id', $this->companyId);
            })
            ->where('c1.company_id', $this->companyId)
            ->whereNotNull('c1.legacy_id')
            ->select(
                'c1.legacy_id',
                'c2.legacy_id as referred_by_legacy_id',
                'c1.peppol_endpoint',
                'c1.is_active',
                'c1.needs_immediate_invoice',
            )
            ->get()
            ->keyBy('legacy_id')
            ->all();
    }

    private function restoreManualEdits(): void
    {
        foreach ($this->manualCustomerEdits as $legacyId => $edit) {
            $newId = $this->map['customers'][(int) $legacyId] ?? null;
            if (! $newId) {
                continue;
            }

            $update = [
                'peppol_endpoint'         => $edit->peppol_endpoint,
                'is_active'               => (bool) $edit->is_active,
                'needs_immediate_invoice' => (bool) $edit->needs_immediate_invoice,
            ];

            if ($edit->referred_by_legacy_id !== null) {
                $update['referred_by_customer_id'] =
                    $this->map['customers'][(int) $edit->referred_by_legacy_id] ?? null;
            }

            DB::table('customers')->where('id', $newId)->update($update);
        }
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

    /** Generic copy for simple lookup tables. */
    private function copyLookup(string $fbTable, string $target, string $pk, callable $row): void
    {
        $this->line("  {$fbTable} -> {$target}");
        foreach ($this->fbAll("SELECT * FROM {$fbTable}") as $r) {
            $id = DB::table($target)->insertGetId(array_merge($row($r), [
                'company_id' => $this->companyId,
                'legacy_id'  => $r[$pk],
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->map[$target][(int) $r[$pk]] = $id;
        }
    }

    // ---------------------------------------------------------------- entities

    private function copyCustomers(): void
    {
        $this->line('  CUSTOMER -> customers');
        foreach ($this->fbAll('SELECT * FROM CUSTOMER') as $r) {
            $id = DB::table('customers')->insertGetId([
                'company_id'             => $this->companyId,
                'legacy_id'              => $r['CUST_ID'],
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
                // Defaults for forward-looking columns added in PR #15 —
                // no legacy source for these:
                'is_active'              => true,
                'needs_immediate_invoice' => false,
                'created_at'             => now(),
                'updated_at'             => now(),
            ]);
            $this->map['customers'][(int) $r['CUST_ID']] = $id;
        }
    }

    private function copyInvoiceTypes(): void
    {
        $this->line('  INVTYPE -> invoice_types  (carries the ΑΑ counter)');
        foreach ($this->fbAll('SELECT * FROM INVTYPE') as $r) {
            $id = DB::table('invoice_types')->insertGetId([
                'company_id'                   => $this->companyId,
                'code'                         => $this->fld($r, 'INVTYPE_ID'),
                'name'                         => $this->fld($r, 'NAME') ?? $r['INVTYPE_ID'],
                'invcount'                     => $r['INVCOUNT'] ?? 1, // <-- seed: next number continues here
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
                'created_at'                   => now(),
                'updated_at'                   => now(),
            ]);
            // invoice_types keyed by its string code, not an int PK
            $this->map['invoice_types'][$this->fld($r, 'INVTYPE_ID')] = $id;
        }
    }

    private function copyProducts(): void
    {
        $this->line('  PRODUCT -> products');
        foreach ($this->fbAll('SELECT * FROM PRODUCT') as $r) {
            $id = DB::table('products')->insertGetId([
                'company_id'          => $this->companyId,
                'legacy_id'           => $r['PRODUCT_ID'],
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
                'created_at'          => now(),
                'updated_at'          => $r['LAST_UPDATE'] ?? now(),
            ]);
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
            DB::table('product_price_tiers')->insert([
                'company_id'       => $this->companyId,
                'legacy_id'        => $r['PROD_PRICE_ID'],
                'product_id'       => $product,
                'value'            => $r['VAL'],
                'discount_percent' => $r['DISCOUNT_PERCENT'],
                'qty'              => $r['QTY'],
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
        }
    }

    private function copyInvoices(): void
    {
        $this->line('  INVOICE -> invoices');
        // pass 1: insert without conv_invoice_id (self-reference resolved in pass 2)
        foreach ($this->fbAll('SELECT * FROM INVOICE') as $r) {
            $issuedAt = $this->mergeDateTime($r['INVDATE'], $r['INVTIME']);
            $id = DB::table('invoices')->insertGetId([
                'company_id'          => $this->companyId,
                'legacy_id'           => $r['INVOICE_ID'],
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
                'created_at'          => $issuedAt ?? now(),
                'updated_at'          => now(),
            ]);
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
            $id = DB::table('invoice_lines')->insertGetId([
                'company_id'     => $this->companyId,
                'legacy_id'      => $r['INVLINE_ID'],
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
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
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
            DB::table('return_invoice_extras')->insert([
                'company_id'      => $this->companyId,
                'invoice_line_id' => $line,
                'qty_given'       => $r['QTY_GIVEN'],
                'qty_returned'    => $r['QTY_RETURNED'],
                'qty_sent'        => $r['QTY_SENT'],
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
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
            DB::table('payments')->insert([
                'company_id'  => $this->companyId,
                'legacy_id'   => $r['PAYMENT_ID'],
                'customer_id' => $customer,
                'pay_date'    => $r['PAY_DATE'],
                'amount'      => $r['VALUE'],
                'notes'       => $this->fld($r, 'NOTES'),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
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
            DB::table('mydata_marks')->insert([
                'company_id'    => $this->companyId,
                'legacy_id'     => $r['ID'],
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
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
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
            DB::table('conf_params')->insert([
                'company_id'     => $this->companyId,
                'varname'        => $this->fld($r, 'VARNAME') ?? '',
                'data_int'       => $r['DATA_INT'],
                'data_string'    => $this->fld($r, 'DATA_STRING'),
                'data_timestamp' => $r['DATA_TIMESTAMP'],
                'data_float'     => $r['DATA_FLOAT'],
                'data_numeric'   => $r['DATA_NUMERIC'],
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
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
            DB::table('whmcs_invoice_log')->insert([
                'company_id'       => $this->companyId,
                'legacy_id'        => $r['LOG_ID'],
                'whmcs_invoice_id' => $r['CS_INVID'],
                'invoice_id'       => $this->legacyId('invoices', $r['CS_INVID']), // best-effort; adjust to your bridge semantics
                'message'          => $this->fld($r, 'LOG_MESSAGE'),
                'created_at'       => $r['LOG_TIMESTAMP'] ?? now(),
                'updated_at'       => now(),
            ]);
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
