<?php

namespace Tests\Feature\Domains;

use App\Enums\DomainStatus;
use App\Models\Company;
use App\Models\Domain;
use App\Models\DomainContact;
use App\Models\DomainTld;
use App\Models\DomainTldPrice;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Πυλώνας A / A1a — the data model's own guarantees: per-tenant uniqueness
 * (same TLD/fqdn may exist on ANOTHER tenant), explicit per-year price rows,
 * the status enum round-trip, and the derived-fqdn normalizer.
 */
class DomainDataModelTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'Dom', 'slug' => 'd-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'enable_domain_management' => true,
        ]);
    }

    private function tld(Company $c, string $tld = 'gr'): DomainTld
    {
        return DomainTld::create(['company_id' => $c->id, 'tld' => $tld, 'min_years' => 2]);
    }

    public function test_tld_is_unique_per_tenant_but_not_across_tenants(): void
    {
        $a = $this->company();
        $b = $this->company();
        $this->tld($a);

        // Another tenant may carry the same TLD.
        $this->tld($b);
        $this->assertSame(2, DomainTld::withoutGlobalScopes()->where('tld', 'gr')->count());

        $this->expectException(QueryException::class);
        $this->tld($a);
    }

    public function test_price_rows_are_explicit_per_operation_year_currency(): void
    {
        $c = $this->company();
        $tld = $this->tld($c);

        $mk = fn (string $op, int $years) => DomainTldPrice::create([
            'company_id' => $c->id, 'domain_tld_id' => $tld->id,
            'operation' => $op, 'years' => $years, 'price' => 19.00,
        ]);

        // .gr-style even years, renewal separate from register, transfer at 0.
        $mk('register', 2);
        $mk('register', 4);
        $mk('renewal', 2);
        $this->assertSame(3, $tld->prices()->count());

        $this->expectException(QueryException::class);
        $mk('register', 2); // duplicate (operation, years, currency)
    }

    public function test_domain_status_casts_to_enum_and_fqdn_is_normalized(): void
    {
        $c = $this->company();
        $tld = $this->tld($c);

        $this->assertSame('παράδειγμα.gr', Domain::fqdnFor('  Παράδειγμα', '.gr'));

        $domain = Domain::create([
            'company_id' => $c->id,
            'domain_tld_id' => $tld->id,
            'sld' => 'example', 'tld' => 'gr',
            'fqdn' => Domain::fqdnFor('example', 'gr'),
            'status' => DomainStatus::TransferredAway,
        ]);

        $fresh = $domain->fresh();
        $this->assertSame(DomainStatus::TransferredAway, $fresh->status);
        $this->assertTrue($fresh->isUnassigned(), 'χωρίς customer_id = αδέσποτο');
        $this->assertFalse($fresh->status->isRenewable());

        // registrant contact, one per type.
        DomainContact::create([
            'company_id' => $c->id, 'domain_id' => $domain->id,
            'type' => 'registrant', 'name' => 'Νίκος Παράδειγμα', 'email' => 'n@example.gr',
        ]);
        $this->expectException(QueryException::class);
        DomainContact::create([
            'company_id' => $c->id, 'domain_id' => $domain->id,
            'type' => 'registrant', 'name' => 'Δεύτερος', 'email' => 'x@example.gr',
        ]);
    }

    public function test_fqdn_is_unique_per_tenant(): void
    {
        $a = $this->company();
        $b = $this->company();
        $tldA = $this->tld($a);
        $tldB = $this->tld($b);

        $mk = fn (Company $c, DomainTld $t) => Domain::create([
            'company_id' => $c->id, 'domain_tld_id' => $t->id,
            'sld' => 'example', 'tld' => 'gr', 'fqdn' => 'example.gr',
        ]);

        $mk($a, $tldA);
        $mk($b, $tldB); // ok on another tenant

        $this->expectException(QueryException::class);
        $mk($a, $tldA);
    }
}
