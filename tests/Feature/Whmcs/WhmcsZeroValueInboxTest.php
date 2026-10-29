<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use App\Services\WhmcsInbox\WhmcsInvoiceFiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * A WHMCS invoice worth 0 (a free .gr domain transfer) has no transaction to
 * document: the ingestor closes it as «resolved» instead of queueing it for a
 * human (45 such rows had been archived by hand), reopens it if WHMCS later gives
 * it a value, and the filer refuses it before any draft exists (auto-issue used to
 * leave an orphan all-zero draft — ΠΡΟΣ-ΤΠΥ-7249).
 */
class WhmcsZeroValueInboxTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Zero', 'slug' => 'zero-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    /** @return array<string, mixed> the real shape of WHMCS #32615 */
    private function freeTransfer(int $id = 32615, string $amount = '0.00'): array
    {
        $tax = $amount === '0.00' ? '0.00' : number_format((float) $amount * 0.24, 2, '.', '');

        return [
            'invoiceid' => $id, 'userid' => 1, 'status' => 'Paid', 'date' => '2026-10-27',
            'subtotal' => $amount, 'tax' => $tax, 'tax2' => '0.00', 'taxrate' => '24.000',
            'total' => number_format((float) $amount + (float) $tax, 2, '.', ''), 'credit' => '0.00', 'taxtype' => 'Exclusive',
            'items' => ['item' => [[
                'id' => 1, 'type' => 'DomainTransfer', 'description' => 'Μεταφορά Domain - example.gr - 2 Έτος/η',
                'amount' => $amount, 'taxed' => 1,
            ]]],
        ];
    }

    private function ingest(array $payload): PendingWhmcsInvoice
    {
        return app(WhmcsInvoiceIngestor::class)->ingest($this->tenant, $payload)->row->fresh();
    }

    public function test_zero_value_detection(): void
    {
        $this->assertTrue(PendingWhmcsInvoice::isZeroValuePayload($this->freeTransfer()));
        // Paid from credit: total 0 but worth 20,46 → NOT zero.
        $this->assertFalse(PendingWhmcsInvoice::isZeroValuePayload(array_merge($this->freeTransfer(9, '16.50'), ['total' => '0.00', 'credit' => '20.46'])));
        // A free line next to a charged one → NOT zero (the line is skipped at filing, #661).
        $mixed = $this->freeTransfer();
        $mixed['items']['item'][] = ['description' => 'Hosting', 'amount' => '10.00', 'taxed' => 1];
        $mixed['subtotal'] = '10.00';
        $this->assertFalse(PendingWhmcsInvoice::isZeroValuePayload($mixed));
        // No lines at all → a human's call.
        $this->assertFalse(PendingWhmcsInvoice::isZeroValuePayload(['invoiceid' => 1, 'total' => '0.00']));
    }

    public function test_a_free_transfer_is_closed_as_resolved_not_queued(): void
    {
        $row = $this->ingest($this->freeTransfer());

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertTrue($row->isAutoResolvedZeroValue());
        $this->assertStringContainsString('Μηδενικής αξίας', (string) $row->notes);
    }

    public function test_a_credit_paid_invoice_is_still_queued(): void
    {
        $row = $this->ingest(array_merge($this->freeTransfer(32467, '16.50'), ['total' => '0.00', 'credit' => '20.46']));

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
    }

    public function test_a_waiting_row_that_turns_zero_is_closed(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $this->ingest($this->freeTransfer(500, '19.00'))->status);

        $row = $this->ingest($this->freeTransfer(500));

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
    }

    public function test_an_auto_closed_row_reopens_when_whmcs_gives_it_a_value(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->freeTransfer(600))->status);

        $row = $this->ingest($this->freeTransfer(600, '19.00'));

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
        $this->assertSame('19.00', $row->payload['subtotal'], 'the fresh payload is taken');
        $this->assertStringContainsString('τώρα έχει αξία', (string) $row->notes);
    }

    public function test_a_human_decision_is_never_reopened(): void
    {
        $row = $this->ingest($this->freeTransfer(700, '19.00'));
        $row->update(['status' => PendingWhmcsInvoice::STATUS_REJECTED, 'rejected_reason' => 'χειροκίνητα']);

        $again = $this->ingest($this->freeTransfer(700, '25.00'));

        $this->assertSame(PendingWhmcsInvoice::STATUS_REJECTED, $again->status);
        $this->assertSame('19.00', $again->payload['subtotal'], 'decided rows stay frozen');
    }

    public function test_the_filer_refuses_a_zero_value_invoice_before_any_draft_exists(): void
    {
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '111111111']);
        $pending = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 32615, 'payload' => $this->freeTransfer(),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $customer->id,
        ]);

        try {
            app(WhmcsInvoiceFiler::class)->file($this->tenant, $pending, $customer, $type, unattended: true);
            $this->fail('a zero-value WHMCS invoice must not be filed');
        } catch (LogicException $e) {
            $this->assertStringContainsString('μηδενικής αξίας', $e->getMessage());
        }

        $this->assertSame(0, Invoice::count(), 'no orphan draft');
        $this->assertSame(1, $type->fresh()->invcount, 'no ΑΑ consumed');
    }

    /** @return array<string, mixed> the same invoice as MyCS sends it */
    private function asMycs(array $payload): array
    {
        return $payload + ['mycs' => ['id' => $payload['invoiceid']]];
    }

    public function test_a_stale_whmcs_payload_never_reopens_a_mycs_row(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->asMycs($this->freeTransfer(800)))->status);

        $row = $this->ingest($this->freeTransfer(800, '19.00'));   // WHMCS, frozen at the cut-over

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertSame('0.00', $row->payload['subtotal']);
    }

    public function test_the_cut_over_move_whmcs_to_mycs_for_the_same_client_reopens(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->freeTransfer(810))->status);

        $row = $this->ingest($this->asMycs($this->freeTransfer(810, '19.00')));

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
        $this->assertSame(PendingWhmcsInvoice::SOURCE_MYCS, $row->source);
        $this->assertSame('19.00', $row->payload['subtotal']);
    }

    public function test_another_client_from_the_other_system_does_not_reopen(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->freeTransfer(820))->status);

        $other = $this->asMycs($this->freeTransfer(820, '19.00'));
        $other['userid'] = 2;
        $row = $this->ingest($other);

        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $row->status);
        $this->assertSame('0.00', $row->payload['subtotal']);
    }

    public function test_same_source_moved_to_another_client_reopens_like_any_refresh(): void
    {
        $this->assertSame(PendingWhmcsInvoice::STATUS_RESOLVED, $this->ingest($this->freeTransfer(830))->status);

        $moved = $this->freeTransfer(830, '19.00');
        $moved['userid'] = 2;
        $row = $this->ingest($moved);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $row->status);
        $this->assertSame(2, (int) $row->whmcs_userid);
    }

    public function test_create_draft_refuses_a_zero_value_invoice(): void
    {
        VatCategory::create(['company_id' => $this->tenant->id, 'name' => '24%', 'rate' => 24, 'is_default' => true]);
        $pm = PaymentMethod::create(['company_id' => $this->tenant->id, 'name' => 'Bank', 'due_days' => 0, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $this->tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $customer = Customer::create(['company_id' => $this->tenant->id, 'name' => 'C', 'afm' => '111111111']);
        $pending = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => 900, 'payload' => $this->freeTransfer(900),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $customer->id,
        ]);

        $this->expectException(LogicException::class);
        try {
            app(WhmcsInvoiceFiler::class)->createDraft($this->tenant, $pending, $customer, $type);
        } finally {
            $this->assertSame(0, Invoice::count());
        }
    }
}
