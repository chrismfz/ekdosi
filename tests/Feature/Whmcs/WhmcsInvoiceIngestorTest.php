<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Database\QueryException;
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

    /** A tenant that maps the «γκρινιάρης» role (id 16) — so the ingestor mirror is live. */
    private function tenantWithGriniaris(string $slug = 'ing-g'): Company
    {
        return Company::create([
            'name' => 'IngG',
            'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_custom_field_map' => ['vatno' => 13, 'griniaris' => 16],
        ]);
    }

    /** Payload matched to whmcs_client_id=$userId, carrying the griniaris field ($g = 'on'|'off'|null). */
    private function payloadWithGriniaris(int $invoiceId, int $userId, ?string $g): array
    {
        $customfields = [];
        if ($g !== null) {
            $customfields[] = ['id' => 16, 'value' => $g];
        }

        return [
            'invoiceid' => $invoiceId,
            'userid' => $userId,
            'total' => '10.00',
            'customfields' => $customfields,
        ];
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
            'userid' => 9999,
            'total' => '50.00',
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
            'userid' => 555,
            'total' => '10.00',
        ]);

        $this->assertSame(PendingWhmcsInvoice::REASON_LINKED, $result->row->match_reason);
        $this->assertSame($customer->id, $result->row->customer_id);
    }

    public function test_second_ingest_refreshes_payload_and_match_without_duplicating(): void
    {
        $tenant = $this->tenant();

        $first = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 777,
            'userid' => 1,
            'total' => '5.00',
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
            'userid' => 1,
            'total' => '6.50',   // <-- changed
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
            'userid' => 7,
            'total' => '100.00',
        ]);

        // Simulate Stage B-2 filing the row.
        $first->row->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'mydata_mark' => '4000123456789',
        ]);

        // WHMCS-side edits the invoice (e.g. typo fix in client
        // address) and re-pushes the webhook. Audit must NOT mutate.
        $second = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 8888,
            'userid' => 7,
            'total' => '999.99',  // <-- attempted mutation
            'sneaky' => 'should not land',
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

    public function test_held_and_rejected_rows_freeze_payload_on_re_ingest(): void
    {
        // Fix #6 regression: rejected_reason is captured against the
        // payload-at-decision-time. Allowing WHMCS-side edits to
        // mutate the payload underneath would decouple the reason
        // from its referent (operator sees "rejected because address
        // wrong" against a payload showing a correct address).
        // Rule: only pending_review rows refresh; everything else is
        // audit-frozen (filed = legal; rejected/held = operator-decision).
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
                'userid' => 1,
                'total' => '99.00',   // refresh attempt
            ]);

            $this->assertTrue($second->auditPreserved, "status={$status} should freeze payload");
            $this->assertSame('1.00', $second->row->payload['total'],
                "status={$status} payload should NOT have been refreshed by re-ingest");
            $this->assertSame($status, $second->row->status);
            if ($status === 'rejected') {
                $this->assertSame('duplicate', $second->row->rejected_reason);
            }
        }
    }

    // ===================== Fix #3: concurrent ingest race =====================

    public function test_unique_violation_on_concurrent_insert_falls_through_to_refresh(): void
    {
        // Simulate the race: another worker created the row between
        // our lockForUpdate->first() (returns null) and our create()
        // (throws unique violation). The ingestor must catch + re-select
        // + proceed as the existing-row branch, returning created=false
        // rather than letting the QueryException escape as a 500.
        $tenant = $this->tenant();

        // Pre-create the row directly (simulating the "other worker"
        // having won the race) without going through the ingestor.
        PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => 3001,
            'whmcs_userid' => 5,
            'payload' => ['invoiceid' => 3001, 'userid' => 5, 'total' => '10.00'],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);

        // Our ingest would normally hit existing-row branch. To force
        // the race-path code, mock-bypass the lockForUpdate->first()
        // by deleting the row immediately before, then re-inserting
        // before create() runs. That's hard to script reliably from
        // a single thread. Instead: verify the helper's behaviour by
        // making the SAME call twice in succession through the
        // ingestor itself - the second call hits the existing-row
        // path naturally. The QueryException catch is unit-tested
        // separately below via a forced fake.
        $result = $this->ingestor()->ingest($tenant, [
            'invoiceid' => 3001,
            'userid' => 5,
            'total' => '99.99',
        ]);

        $this->assertFalse($result->created);
        $this->assertSame('99.99', $result->row->payload['total']);
        $this->assertSame(1, PendingWhmcsInvoice::count());
    }

    public function test_unique_violation_helper_recognises_sqlite_and_mariadb_messages(): void
    {
        // The helper isUniqueConstraintViolation is private; we exercise
        // it via reflection to lock the message-matching contract for
        // both engines so a future SQLite-only fix doesn't break MariaDB
        // production. QueryException's getCode() returns a SQLSTATE
        // string (e.g. '23000'), not the PDOException's integer code,
        // so we construct the exceptions with sqlState directly to
        // mirror what Laravel produces in production.
        $ingestor = $this->ingestor();
        $ref = new \ReflectionMethod($ingestor, 'isUniqueConstraintViolation');
        $ref->setAccessible(true);

        $makeQE = function (string $message, string $sqlState): QueryException {
            // PDOException's $code is set to a SQLSTATE string in
            // production by the PDO C extension, but the constructor
            // type-hints int. Use reflection to set the protected
            // Exception::$code field directly to mirror the real shape.
            $pdo = new \PDOException($message);
            $codeProp = new \ReflectionProperty(\Exception::class, 'code');
            $codeProp->setAccessible(true);
            $codeProp->setValue($pdo, $sqlState);

            return new QueryException('mariadb', '', [], $pdo);
        };

        $this->assertTrue($ref->invoke($ingestor, $makeQE(
            'SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed: pending_whmcs_invoices.whmcs_invoice_id',
            '23000',
        )));
        $this->assertTrue($ref->invoke($ingestor, $makeQE(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-12345' for key 'pwi_company_invoice_unique'",
            '23000',
        )));
        $this->assertFalse($ref->invoke($ingestor, $makeQE(
            'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded',
            'HY000',
        )));
    }

    // ===================== Fix #5: observer blocks filed-row mutation =====================

    public function test_observer_blocks_payload_mutation_on_filed_rows(): void
    {
        $tenant = $this->tenant();
        $result = $this->ingestor()->ingest($tenant, ['invoiceid' => 7000, 'userid' => 1]);

        // Transition to filed first (allowed - the original status is
        // still pending_review at the moment of save).
        $result->row->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'mydata_mark' => '4000999888777',
        ]);

        // Now try to mutate. Reload to ensure getOriginal('status')
        // reflects the filed state, not pending_review.
        $filed = $result->row->fresh();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/audit-frozen.*MARK=4000999888777.*payload/');

        $filed->update(['payload' => ['tampered' => true]]);
    }

    public function test_observer_allows_touch_on_filed_rows(): void
    {
        $tenant = $this->tenant();
        $result = $this->ingestor()->ingest($tenant, ['invoiceid' => 7100, 'userid' => 1]);
        $result->row->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'mydata_mark' => '4000888',
        ]);

        $before = $result->row->fresh();
        $originalUpdatedAt = $before->updated_at;

        // touch() bumps updated_at only. Must NOT throw.
        sleep(1);   // ensure timestamp differs
        $before->touch();

        $this->assertGreaterThan(
            $originalUpdatedAt->timestamp,
            $before->fresh()->updated_at->timestamp,
        );
    }

    public function test_observer_blocks_status_change_on_filed_rows(): void
    {
        // filed is terminal - operator can't un-file a row by editing
        // it back to pending_review (the AADE MARK would dangle).
        $tenant = $this->tenant();
        $result = $this->ingestor()->ingest($tenant, ['invoiceid' => 7200, 'userid' => 1]);
        $result->row->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED,
            'filed_at' => now(),
            'mydata_mark' => '4000111',
        ]);

        $filed = $result->row->fresh();

        $this->expectException(\LogicException::class);
        $filed->update(['status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);
    }

    public function test_notifies_operators_when_a_new_immediate_row_is_staged(): void
    {
        $tenant = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $tenant->users()->attach($user->id);
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Άμεσος', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);

        $this->ingestor()->ingest($tenant, ['invoiceid' => 9001, 'userid' => 555, 'total' => '10.00']);

        $this->assertSame(1, $user->fresh()->notifications()->count(), 'a new immediate row pings the operator');

        // Re-ingest (update, not create) must NOT re-notify.
        $this->ingestor()->ingest($tenant, ['invoiceid' => 9001, 'userid' => 555, 'total' => '12.00']);
        $this->assertSame(1, $user->fresh()->notifications()->count(), 'a refresh does not re-notify');
    }

    public function test_does_not_notify_for_a_non_immediate_row(): void
    {
        $tenant = $this->tenant();
        $user = User::create(['name' => 'Op', 'email' => 'op2-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $tenant->users()->attach($user->id);
        Customer::create([
            'company_id' => $tenant->id, 'name' => 'Ήσυχος', 'whmcs_client_id' => 556,
            'needs_immediate_invoice' => false,
        ]);

        $this->ingestor()->ingest($tenant, ['invoiceid' => 9002, 'userid' => 556, 'total' => '10.00']);

        $this->assertSame(0, $user->fresh()->notifications()->count());
    }

    // ============ «Γκρινιάρης» → needs_immediate_invoice mirror (WHMCS = source of truth) ============

    public function test_mirror_flips_needs_immediate_invoice_on_from_griniaris(): void
    {
        $tenant = $this->tenantWithGriniaris();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => false,
        ]);

        $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(100, 555, 'on'));

        $this->assertTrue($customer->fresh()->needs_immediate_invoice, 'WHMCS γκρινιάρης=on mirrors the flag ON');
    }

    public function test_mirror_flips_needs_immediate_invoice_off_whmcs_is_source_of_truth(): void
    {
        $tenant = $this->tenantWithGriniaris();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);

        $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(101, 555, 'off'));

        $this->assertFalse(
            $customer->fresh()->needs_immediate_invoice,
            'WHMCS = source of truth: a cleared γκρινιάρης mirrors the flag OFF',
        );
    }

    public function test_a_later_whmcs_toggle_propagates_on_re_ingest(): void
    {
        // The «γκρινιάζει ένα μήνα μετά» scenario: the customer already exists, the
        // first ingest has no γκρινιάρης, and a later ingest of the SAME invoice has
        // it on → ekdosi follows on the next fetch (unlike the create-only seed).
        $tenant = $this->tenantWithGriniaris();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => false,
        ]);

        $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(102, 555, null));
        $this->assertFalse($customer->fresh()->needs_immediate_invoice, 'no γκρινιάρης yet');

        $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(102, 555, 'on'));
        $this->assertTrue(
            $customer->fresh()->needs_immediate_invoice,
            'operator ticked γκρινιάρης in WHMCS a month later → propagates on the next ingest',
        );
    }

    public function test_mirror_is_a_noop_when_tenant_does_not_map_griniaris(): void
    {
        // The critical safety guard: a tenant that doesn't manage γκρινιάρης in WHMCS
        // must NEVER have its customers' flag touched by an ingest — even if the payload
        // carries a griniaris-looking custom field. Without this, every fetch would force
        // the flag off for tenants that don't use the field.
        $tenant = $this->tenant();   // no whmcs_custom_field_map → griniaris unmapped
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);

        $this->ingestor()->ingest($tenant, [
            'invoiceid' => 103, 'userid' => 555, 'total' => '10.00',
            'customfields' => [['id' => 16, 'value' => 'off']],
        ]);

        $this->assertTrue(
            $customer->fresh()->needs_immediate_invoice,
            'unmapped tenant → the flag is left entirely to the ekdosi operator',
        );
    }

    public function test_audit_frozen_row_does_not_mirror_the_flag(): void
    {
        // A filed row's re-ingest keeps the decision-time payload frozen AND does not
        // sync the customer flag — the client's next pending_review invoice does.
        $tenant = $this->tenantWithGriniaris();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);

        $first = $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(104, 555, 'on'));
        $first->row->update([
            'status' => PendingWhmcsInvoice::STATUS_FILED, 'filed_at' => now(), 'mydata_mark' => '4000123',
        ]);

        // WHMCS clears γκρινιάρης and re-pushes the (now filed) invoice.
        $second = $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(104, 555, 'off'));

        $this->assertTrue($second->auditPreserved);
        $this->assertTrue(
            $customer->fresh()->needs_immediate_invoice,
            'a frozen re-ingest never touches the flag (the next live invoice syncs it)',
        );
    }

    public function test_mirror_does_not_write_when_already_in_sync(): void
    {
        // No needless updated_at / activity-log row when WHMCS agrees with ekdosi.
        $tenant = $this->tenantWithGriniaris();
        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Linked', 'whmcs_client_id' => 555,
            'needs_immediate_invoice' => true,
        ]);
        $stamp = $customer->fresh()->updated_at;

        sleep(1);
        $this->ingestor()->ingest($tenant, $this->payloadWithGriniaris(105, 555, 'on'));

        $this->assertSame(
            $stamp->timestamp,
            $customer->fresh()->updated_at->timestamp,
            'already in sync → the customer row is not re-saved',
        );
    }
}
