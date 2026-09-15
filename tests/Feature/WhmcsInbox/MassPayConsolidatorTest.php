<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\WhmcsInbox\MassPayConsolidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * WHMCS mass-pay CONSOLIDATION — built against the real prod bundle #32310
 * (Γ.Λαουνάρος, one €647.70 vPOS deposit for three orders):
 *
 *   #32310 (mass-pay)  total 647.70  → refs #32280 (23.56) #32263 (82.26) #32256 (541.88)
 *   #32256  Hosting 437.00 net (taxed) → 541.88 gross    ← children's own `total` is 0.00
 *   #32263  Hosting  66.34 net (taxed) →  82.26 gross       (WHMCS zeroes it on mass-pay,
 *   #32280  Domain   19.00 net (taxed) →  23.56 gross        so the LINE ITEMS are the truth)
 *
 * Consolidate must fetch the children, merge their REAL service lines into one
 * παραστατικό, and reconstruct a net/gross breakdown that sums to the payment.
 */
class MassPayConsolidatorTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'MassPay OE', 'slug' => 'mp-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'sandbox',
        ]);
        $this->customer = Customer::create([
            'company_id' => $this->tenant->id, 'name' => 'Γ.Λαουνάρος και ΣΙΑ Ο.Ε.', 'afm' => '997890734',
        ]);
    }

    private function massPayRow(): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => 32310,
            'whmcs_userid' => 979,
            'customer_id' => $this->customer->id,
            'status' => PendingWhmcsInvoice::STATUS_HELD,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'payload' => [
                'invoiceid' => 32310, 'userid' => 979, 'total' => '647.70', 'taxrate' => '24.000',
                'status' => 'Paid', 'paymentmethod' => 'eurobanklib',
                'items' => ['item' => [
                    ['id' => 1, 'type' => 'Invoice', 'relid' => 32280, 'description' => 'Αρ. Λογαριασμού #32280', 'amount' => '23.56', 'taxed' => '0'],
                    ['id' => 2, 'type' => 'Invoice', 'relid' => 32263, 'description' => 'Αρ. Λογαριασμού #32263', 'amount' => '82.26', 'taxed' => '0'],
                    ['id' => 3, 'type' => 'Invoice', 'relid' => 32256, 'description' => 'Αρ. Λογαριασμού #32256', 'amount' => '541.88', 'taxed' => '0'],
                ]],
            ],
        ]);
    }

    /** A fetcher whose closure returns the child GetInvoice payloads (the real shape, total=0). */
    private function fetcherReturning(array $children): WhmcsInvoiceFetcher
    {
        return new class($children) extends WhmcsInvoiceFetcher
        {
            /** @param array<int, array<string,mixed>> $children */
            public function __construct(private array $children) {}

            public function for(Company $tenant): ?callable
            {
                return fn (int $id): ?array => $this->children[$id] ?? null;
            }
        };
    }

    private function child(int $id, string $type, string $desc, string $net): array
    {
        return [
            'invoiceid' => $id, 'userid' => 979, 'total' => '0.00', 'taxrate' => '24.000', 'status' => 'Paid',
            'items' => ['item' => [
                ['type' => $type, 'relid' => 1, 'description' => $desc, 'amount' => $net, 'taxed' => '1'],
            ]],
        ];
    }

    public function test_consolidate_merges_the_children_real_lines_into_one_payload(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro AMD Server (Managed) - creatures.myipservers.gr', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated 8C - karagilanis.gr', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Ανανέωση Domain - otgrowup.gr', '19.00'),
        ]);

        $folded = (new MassPayConsolidator($fetcher))->consolidate($this->tenant, $massPay);

        $this->assertEqualsCanonicalizing([32280, 32263, 32256], $folded);

        $fresh = $massPay->fresh();
        // The container is now issuable, no longer «σε αναμονή».
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $fresh->status);

        $payload = $fresh->payload;
        // Real service lines replaced the reference pointers.
        $descriptions = array_column($payload['items']['item'], 'description');
        $this->assertCount(3, $descriptions);
        $this->assertStringContainsString('Supermicro', implode(' | ', $descriptions));
        $this->assertStringContainsString('Domain', implode(' | ', $descriptions));
        // No reference (type=Invoice) line survived → it would file at 0% ΦΠΑ.
        $this->assertNotContains('Invoice', array_column($payload['items']['item'], 'type'));

        // Reconstructed breakdown: net 522.34 + ΦΠΑ 125.36 = 647.70 (the deposit).
        $this->assertSame('522.34', $payload['subtotal']);
        $this->assertSame('125.36', $payload['tax']);
        $this->assertSame('647.70', $payload['total']);

        // Write-back bookkeeping + audit trail of the original pointer payload.
        $this->assertEqualsCanonicalizing([32280, 32263, 32256], $payload['ekdosi_consolidated_children']);
        $this->assertNotNull($payload['ekdosi_masspay_source']['items']);
    }

    public function test_consolidate_refuses_a_third_party_child_and_points_to_explode(): void
    {
        $massPay = $this->massPayRow();
        // #32256 belongs to a DIFFERENT WHMCS client → third party.
        $fetcher = $this->fetcherReturning([
            32256 => ['invoiceid' => 32256, 'userid' => 5000, 'total' => '0.00', 'taxrate' => '24.000', 'status' => 'Paid',
                'items' => ['item' => [['type' => 'Hosting', 'description' => 'X', 'amount' => '437.00', 'taxed' => '1']]]],
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated 8C', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Domain', '19.00'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('τρίτου');

        (new MassPayConsolidator($fetcher))->consolidate($this->tenant, $massPay);
    }

    public function test_consolidate_refuses_when_a_child_cannot_be_fetched(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'X', '437.00'),
            // 32263 + 32280 missing (WHMCS unreachable for them)
        ]);

        $this->expectException(RuntimeException::class);
        (new MassPayConsolidator($fetcher))->consolidate($this->tenant, $massPay);

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $massPay->fresh()->status, 'stays held on failure');
    }
}
