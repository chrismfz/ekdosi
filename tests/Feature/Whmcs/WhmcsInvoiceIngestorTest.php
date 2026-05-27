<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * PR #31: WhmcsInvoiceIngestor idempotency + audit-freeze contract.
 *
 * The ingestor IS the canonical entry point for both ingress paths
 * (webhook + pull command). Every invariant the inbox UI will rely
 * on - "filed rows never silently change payload", "(company_id,
 * whmcs_invoice_id) maps to at most one row", "match is re-run on
 * refresh" - is locked in here so a Stage B-2 refactor can't break
 * the contract without these tests turning red.
 */
class WhmcsInvoiceIngestorTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $slug = 'ing'): Company
    {
        return Company::create([
            'name' => 'Ing',
            'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
        ]);
    }

    private function ingestor(): WhmcsInvoiceIngestor
    {
        return app(WhmcsInvoiceIngestor::class);
    }

    public function test_throws_when_payload_missing_invoice_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->ingestor()->ingest($this->tenant(), ['userid' => 1]);
    }

    public function test_creates_row_on_first_ingest_with_unmatched_reason(): void
    {
        $tenant = $this->tenant();

        $result = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 4242,
            'userid'    => 9999,
            'total'     => '50.00',
        ]);

        $this->assertTrue($result->created);
        $this->assertFalse($result->auditPreserved);
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $result->row->status);
        $this->assertSame(PendingWhmcsInvoice::REASON_UNMATCHED, $result->row->match_reason);
        $this->assertNull($result->row->customer_id);
        $this->assertSame(4242, $result->row->whmcs_invoice_id);
        $this->assertSame(9999, $result->row->whmcs_userid);
        // Full payload snapshot retained.
        $this->assertSame('50.00', $result->row->payload['total']);
    }

    public function test_creates_row_with_linked_match_when_customer_has_whmcs_client_id(): void
    {
        $tenant = $this->tenant();
        $customer = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'Linked',
            'whmcs_client_id' => 555,
        ]);

        $result = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 100,
            'userid'    => 555,
            'total'     => '10.00',
        ]);

        $this->assertSame(PendingWhmcsInvoice::REASON_LINKED, $result->row->match_reason);
        $this->assertSame($customer->id, $result->row->customer_id);
    }

    public function test_second_ingest_refreshes_payload_and_match_without_duplicating(): void
    {
        $tenant = $this->tenant();

        $first = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 777,
            'userid'    => 1,
            'total'     => '5.00',
        ]);

        // Mutate the upstream snapshot AND introduce a linkable
        // customer between calls. The second ingest should pick both up.
        $cust = Customer::create([
            'company_id' => $tenant->id,
            'name' => 'New Link',
            'whmcs_client_id' => 1,
        ]);

        $second = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 777,
            'userid'    => 1,
            'total'     => '6.50',   // <-- changed
            'notes_test_marker' => 'fresh',
        ]);

        $this->assertFalse($second->created);
        $this->assertFalse($second->auditPreserved);
        $this->assertSame($first->row->id, $second->row->id);
        $this->assertSame('6.50', $second->row->payload['total']);
        $this->assertSame('fresh', $second->row->payload['notes_test_marker']);
        $this->assertSame($cust->id, $second->row->customer_id);
        $this->assertSame(PendingWhmcsInvoice::REASON_LINKED, $second->row->match_reason);
        $this->assertSame(1, PendingWhmcsInvoice::count());
    }

    public function test_filed_rows_preserve_payload_and_match_on_re_ingest(): void
    {
        $tenant = $this->tenant();
        $first = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 8888,
            'userid'    => 7,
            'total'     => '100.00',
        ]);

        // Simulate Stage B-2 filing the row.
        $first->row->update([
            'status'           => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at'         => now(),
            'mydata_mark'      => '4000123456789',
        ]);

        // WHMCS-side edits the invoice (e.g. typo fix in client
        // address) and re-pushes the webhook. Audit must NOT mutate.
        $second = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 8888,
            'userid'    => 7,
            'total'     => '999.99',  // <-- attempted mutation
            'sneaky'    => 'should not land',
        ]);

        $this->assertTrue($second->auditPreserved);
        $this->assertFalse($second->created);
        $this->assertSame('100.00', $second->row->payload['total']);
        $this->assertArrayNotHasKey('sneaky', $second->row->payload);
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $second->row->status);
        $this->assertSame('4000123456789', $second->row->mydata_mark);
    }

    public function test_different_tenants_can_have_same_whmcs_invoice_id(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        $this->ingestor()->ingest($a, ['invoiceid' => 1, 'userid' => 1]);
        $this->ingestor()->ingest($b, ['invoiceid' => 1, 'userid' => 1]);

        $this->assertSame(2, PendingWhmcsInvoice::count());
        $this->assertSame(1, PendingWhmcsInvoice::where('company_id', $a->id)->count());
        $this->assertSame(1, PendingWhmcsInvoice::where('company_id', $b->id)->count());
    }

    public function test_held_and_rejected_rows_can_refresh_payload_on_re_ingest(): void
    {
        $tenant = $this->tenant();

        foreach (['held', 'rejected'] as $status) {
            $first = $this->ingestor()->ingest($tenant, [
                'invoiceid' => $status === 'held' ? 1001 : 1002,
                'userid' => 1,
                'total' => '1.00',
            ]);
            $first->row->update([
                'status' => $status,
                'rejected_reason' => $status === 'rejected' ? 'duplicate' : null,
            ]);

            $second = $this->ingestor()->ingest($tenant, [
                'invoiceid' => $first->row->whmcs_invoice_id,
                'userid'    => 1,
                'total'     => '99.00',   // refresh expected
            ]);

            $this->assertFalse($second->auditPreserved, "status={$status} should not freeze audit");
            $this->assertSame('99.00', $second->row->payload['total']);
            // Status + operator-set notes are preserved across refresh.
            $this->assertSame($status, $second->row->status);
            if ($status === 'rejected') {
                $this->assertSame('duplicate', $second->row->rejected_reason);
            }
        }
    }
}
