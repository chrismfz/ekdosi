<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceFetcher;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
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

    private function consolidator(WhmcsInvoiceFetcher $fetcher): MassPayConsolidator
    {
        return new MassPayConsolidator($fetcher, app(WhmcsInvoiceIngestor::class));
    }

    public function test_consolidate_merges_the_children_real_lines_into_one_payload(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro AMD Server (Managed) - creatures.myipservers.gr', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated 8C - karagilanis.gr', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Ανανέωση Domain - otgrowup.gr', '19.00'),
        ]);

        $folded = $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

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

    public function test_consolidate_handles_exempt_and_taxed_lines_per_line(): void
    {
        // Prod reality: taxrate=0 invoices + taxed=0 lines are common. A child with
        // BOTH a 24% and a 0% (exempt) line must be reconstructed PER LINE — a uniform
        // gross÷1.24 back-out would mis-state the net (530.40 vs the correct 447.00 here).
        $massPay = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 40000, 'whmcs_userid' => 979,
            'customer_id' => $this->customer->id, 'status' => PendingWhmcsInvoice::STATUS_HELD,
            'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'payload' => [
                'invoiceid' => 40000, 'userid' => 979, 'total' => '551.88', 'taxrate' => '24.000', 'status' => 'Paid',
                'items' => ['item' => [
                    ['type' => 'Invoice', 'relid' => 32256, 'description' => 'Αρ. Λογαριασμού #32256', 'amount' => '551.88', 'taxed' => '0'],
                ]],
            ],
        ]);
        $fetcher = $this->fetcherReturning([
            32256 => ['invoiceid' => 32256, 'userid' => 979, 'total' => '0.00', 'taxrate' => '24.000', 'status' => 'Paid',
                'items' => ['item' => [
                    ['type' => 'Hosting', 'description' => 'Hosting 1y', 'amount' => '437.00', 'taxed' => '1'],   // 24%
                    ['type' => 'Domain', 'description' => 'Domain χωρίς ΦΠΑ', 'amount' => '10.00', 'taxed' => '0'], // exempt
                ]]],
        ]);

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

        $payload = $massPay->fresh()->payload;
        $this->assertSame('447.00', $payload['subtotal']); // 437 + 10 (NOT 551.88/1.24 = 445.06)
        $this->assertSame('104.88', $payload['tax']);       // 437 * 24% only
        $this->assertSame('551.88', $payload['total']);     // = the deposit
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

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);
    }

    public function test_consolidate_refuses_when_a_child_cannot_be_fetched(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'X', '437.00'),
            // 32263 + 32280 missing (WHMCS unreachable for them)
        ]);

        $this->expectException(RuntimeException::class);
        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $massPay->fresh()->status, 'stays held on failure');
    }

    public function test_consolidate_refuses_when_a_child_was_already_issued_on_its_own(): void
    {
        // P0 (review): a child already filed/drafted separately must NOT be folded —
        // its lines would be declared twice (its own MARK AND inside the consolidated).
        $massPay = $this->massPayRow();
        PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 32256,
            'status' => PendingWhmcsInvoice::STATUS_FILED, 'match_reason' => PendingWhmcsInvoice::REASON_AFM,
            'payload' => ['invoiceid' => 32256], 'mydata_mark' => '400009999',
        ]);
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Domain', '19.00'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ήδη εκδοθεί');

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);
    }

    public function test_consolidate_refuses_a_child_whose_lines_do_not_reconcile_to_the_payment(): void
    {
        // P1 (review): reconcile is now REAL — the child's own line total is compared
        // to the mass-pay reference. #32256's lines sum to 248 gross, but the mass-pay
        // says it settled 541.88 → refuse (would overbill by ~294).
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro', '200.00'), // 248 gross ≠ 541.88 ref
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Domain', '19.00'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('δεν συμφωνεί');

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);
    }

    public function test_rate_comes_from_the_child_not_the_zero_rate_container(): void
    {
        // P1 (review): a mass-pay container can report taxrate=0 («no VAT of its own»).
        // The rate MUST come from the taxed children, else the whole thing files at 0%.
        $massPay = $this->massPayRow();
        $massPay->forceFill(['payload' => array_merge($massPay->payload, ['taxrate' => '0.000'])])->save();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro', '437.00'),  // child taxrate 24
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Domain', '19.00'),
        ]);

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

        $payload = $massPay->fresh()->payload;
        $this->assertSame('647.70', $payload['total']);   // 24% applied, NOT 0%
        $this->assertSame('125.36', $payload['tax']);
    }

    public function test_consolidated_note_lists_the_paid_proformas(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro AMD Server', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated 8C', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Ανανέωση Domain otgrowup.gr', '19.00'),
        ]);

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

        $note = $massPay->fresh()->payload['ekdosi_invoice_note'];
        $this->assertStringContainsString('εξοφλεί τα προτιμολόγια', $note);
        $this->assertStringContainsString('#32280', $note);
        $this->assertStringContainsString('Supermicro', $note);  // child label included
    }

    public function test_consolidate_tombstones_the_children_so_a_later_fetch_cannot_restage_them(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Domain', '19.00'),
        ]);

        $this->consolidator($fetcher)->consolidate($this->tenant, $massPay);

        foreach ([32256, 32263, 32280] as $childId) {
            $tombstone = PendingWhmcsInvoice::where('company_id', $this->tenant->id)
                ->where('whmcs_invoice_id', $childId)->first();
            $this->assertNotNull($tombstone, "child #{$childId} tombstoned");
            $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $tombstone->status);
            $this->assertSame($massPay->id, $tombstone->masspay_parent_id, 'linked for grouping');
        }
        // The mass-pay «has» its children for the grouped UI.
        $this->assertCount(3, $massPay->fresh()->massPayChildren);
    }

    public function test_explode_stages_each_child_as_its_own_row_and_resolves_the_masspay(): void
    {
        $massPay = $this->massPayRow();
        $fetcher = $this->fetcherReturning([
            32256 => $this->child(32256, 'Hosting', 'Supermicro AMD Server', '437.00'),
            32263 => $this->child(32263, 'Hosting', 'Semi Dedicated 8C', '66.34'),
            32280 => $this->child(32280, 'Domain', 'Ανανέωση Domain', '19.00'),
        ]);

        $staged = $this->consolidator($fetcher)->explode($this->tenant, $massPay);

        $this->assertEqualsCanonicalizing([32280, 32263, 32256], $staged);
        // The container is done.
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $massPay->fresh()->status);

        // Each child is now its OWN issuable row, with its true gross reconstructed
        // (WHMCS reported total=0) and linked back to the mass-pay for grouping.
        $child = PendingWhmcsInvoice::where('company_id', $this->tenant->id)->where('whmcs_invoice_id', 32256)->first();
        $this->assertNotNull($child);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $child->status);
        $this->assertSame($massPay->id, $child->masspay_parent_id);
        $this->assertSame('541.88', $child->payload['total']);  // 437 net + 24%
        $this->assertSame('437.00', $child->payload['subtotal']);
    }
}
