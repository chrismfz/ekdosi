<?php

namespace Tests\Feature\Portability;

use App\Models\Company;
use App\Services\Portability\CompanyPurger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Deleting a company must work on MariaDB, but CI runs on sqlite — which checks
 * FKs at end of statement and so never hits the InnoDB 1451 a RESTRICT edge
 * inside the company cascade causes. So the guard is structural: rebuild the FK
 * graph from the schema (sqlite carries the same ON DELETE rules as the MariaDB
 * baseline) and prove CompanyPurger::RESTRICTED_FIRST leaves no RESTRICT edge
 * for any cascading delete to trip over.
 *
 * Blind spot: an FK that exists ONLY on MariaDB (a driver-guarded migration or
 * raw ALTER) isn't in the sqlite graph — keep FKs portable, or re-run the
 * rolled-back probe on MariaDB. Checked 2026-10: both graphs reach the same 88
 * tables through the same 8 RESTRICT edges.
 */
class CompanyPurgeOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_restrict_fk_inside_the_company_cascade_is_cleared_first_in_a_safe_order(): void
    {
        [$cascade, $blocking] = $this->graph();

        $this->assertGreaterThan(50, count($this->closure('companies', $cascade)), 'the FK graph was not read');

        $cleared = [];
        foreach ([...CompanyPurger::RESTRICTED_FIRST, 'companies'] as $table) {
            if ($table !== 'companies') {
                $this->assertTrue(Schema::hasColumn($table, 'company_id'), "{$table} is purged by company_id but has none");
            }

            // This step's DELETE (plus its cascade) touches every table in $reach.
            // Any RESTRICT edge X → P with P in there needs X already emptied by an
            // EARLIER step — if X goes in the same statement, InnoDB may visit P first.
            $reach = $this->closure($table, $cascade);
            foreach ($blocking as [$child, $parent, $columns]) {
                if (isset($reach[$parent]) && ! isset($cleared[$child])) {
                    $this->fail(
                        "{$child}.{$columns} → {$parent} is ON DELETE RESTRICT inside the company cascade, so "
                        ."deleting {$table} fails on MariaDB (1451). Add «{$child}» to CompanyPurger::RESTRICTED_FIRST "
                        .'before any table whose delete reaches '.$parent.' (or make the FK cascade/set null if it is not a legal link).'
                    );
                }
            }
            $cleared[$table] = true;
        }
    }

    public function test_deleting_a_company_removes_every_restrict_linked_row_and_leaves_other_tenants_alone(): void
    {
        $doomed = $this->seedTenant('doomed');
        $kept = $this->seedTenant('kept');

        $this->assertTrue($doomed->delete());

        foreach (['products', 'product_categories', 'vat_categories', 'invoices', 'invoice_types', 'pending_whmcs_invoices',
            'customers', 'service_contracts', 'delivery_notes', 'domains', 'domain_tlds'] as $table) {
            $this->assertSame(0, DB::table($table)->where('company_id', $doomed->id)->count(), "{$table} left behind");
            $this->assertSame(1, DB::table($table)->where('company_id', $kept->id)->count(), "{$table} of another tenant touched");
        }
        $this->assertNull(Company::find($doomed->id));
    }

    public function test_a_cancelled_delete_rolls_back_the_cleared_tables(): void
    {
        $c = $this->seedTenant('cancel');
        // Registered after the observer, so CompanyPurger has already run when this vetoes.
        Company::deleting(fn (): bool => false);

        try {
            $c->delete();
            $this->fail('a vetoed delete must throw');
        } catch (RuntimeException) {
        }

        $this->assertNotNull(Company::find($c->id));
        $this->assertSame(1, DB::table('products')->where('company_id', $c->id)->count());
        $this->assertSame(1, DB::table('invoices')->where('company_id', $c->id)->count());
    }

    public function test_every_delete_writes_the_myd025_audit_line_with_the_evidence_it_destroyed(): void
    {
        $c = $this->seedTenant('audit');
        Log::spy();

        $c->delete();   // not through EditCompany — the model itself must log

        Log::shouldHaveReceived('warning')->once()->with('Company deleted', Mockery::on(
            fn (array $ctx): bool => $ctx['company_id'] === $c->id && $ctx['slug'] === 'audit' && array_key_exists('evidence', $ctx),
        ));
    }

    public function test_deleting_an_unpersisted_instance_purges_nothing(): void
    {
        $real = $this->seedTenant('real');
        $ghost = new Company;
        $ghost->id = $real->id;   // same key, but exists=false

        $this->assertNull($ghost->delete());
        $this->assertSame(1, DB::table('products')->where('company_id', $real->id)->count());
        $this->assertSame(1, DB::table('invoices')->where('company_id', $real->id)->count());
    }

    /**
     * Cascade adjacency (parent → children) and the RESTRICT/NO ACTION edges
     * between company-cascaded tables (the ones into `companies` itself excluded).
     *
     * @return array{0: array<string, list<string>>, 1: list<array{0: string, 1: string, 2: string}>}
     */
    private function graph(): array
    {
        $cascade = [];
        $restrict = [];
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getForeignKeys($table) as $fk) {
                $rule = strtolower((string) ($fk['on_delete'] ?? 'no action'));
                if ($rule === 'cascade') {
                    $cascade[$fk['foreign_table']][] = $table;
                } elseif (in_array($rule, ['restrict', 'no action'], true)) {
                    $restrict[] = [$table, $fk['foreign_table'], implode(',', $fk['columns'])];
                }
            }
        }

        $tenant = $this->closure('companies', $cascade);
        $blocking = array_values(array_filter(
            $restrict,
            fn (array $e): bool => $e[1] !== 'companies' && isset($tenant[$e[0]], $tenant[$e[1]]),
        ));

        return [$cascade, $blocking];
    }

    /** @return array<string, true> the table plus everything its DELETE cascades into */
    private function closure(string $table, array $cascade): array
    {
        $seen = [$table => true];
        $queue = [$table];
        while ($queue) {
            foreach ($cascade[array_shift($queue)] ?? [] as $child) {
                if (! isset($seen[$child])) {
                    $seen[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $seen;
    }

    /** One row on each side of every RESTRICT edge CompanyPurger handles. */
    private function seedTenant(string $slug): Company
    {
        $c = Company::create(['name' => $slug.' OE', 'slug' => $slug, 'country_code' => 'GR', 'einvoice_provider' => 'none']);
        $id = $c->id;
        $ins = fn (string $table, array $row): int => DB::table($table)->insertGetId(['company_id' => $id, ...$row]);

        $vat = $ins('vat_categories', ['description' => '24%', 'rate' => 24]);
        $cat = $ins('product_categories', ['description_short' => 'Γενικά']);
        $ins('products', ['description_short' => 'Προϊόν', 'product_category_id' => $cat, 'vat_category_id' => $vat]);
        $type = $ins('invoice_types', ['code' => 'TPY', 'name' => 'ΤΠΥ', 'invcount' => 1]);
        $cust = $ins('customers', ['name' => 'Πελάτης']);
        $inv = $ins('invoices', ['invcode' => 'TPY1', 'code' => 1, 'invoice_type_id' => $type, 'customer_id' => $cust, 'issued_at' => now()]);
        $ins('pending_whmcs_invoices', ['whmcs_invoice_id' => 7, 'payload' => '{}', 'match_reason' => 'test', 'invoice_id' => $inv]);
        $ins('service_contracts', ['customer_id' => $cust, 'billing_cycle' => 'annually']);
        $ins('delivery_notes', ['delivery_type_id' => $type, 'issued_at' => now()]);
        $tld = $ins('domain_tlds', ['tld' => 'gr']);
        $ins('domains', ['domain_tld_id' => $tld, 'customer_id' => $cust, 'sld' => $slug, 'tld' => 'gr', 'fqdn' => $slug.'.gr']);

        return $c;
    }
}
