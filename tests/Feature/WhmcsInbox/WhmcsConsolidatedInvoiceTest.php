<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use App\Services\Whmcs\WhmcsInvoiceIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * WHMCS "mass payment" / consolidated-invoice guard.
 *
 * When a client pays several open invoices at once, WHMCS makes a NEW invoice
 * whose every line references another invoice (type='Invoice', relid=<source
 * id>; text "Invoice #N" / "Αρ. Λογαριασμού #N") and carries NO VAT of its own.
 * It's a payment-grouping artefact, not a sale — issuing it would double-count
 * the source invoices and file their gross at 0% ΦΠΑ. This locks the detection
 * (PendingWhmcsInvoice::detectConsolidatedRefs) + the three guard points:
 * ingest holds it, auto-issue refuses it, and the inbox draft action blocks it.
 */
class WhmcsConsolidatedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    /** A native-GetInvoice consolidated payload (the real shape, type+relid). */
    private function consolidatedPayload(): array
    {
        return [
            'invoiceid' => 31732,
            'userid' => 1,
            'date' => '2026-06-13',
            'total' => '618.76',
            'subtotal' => '618.76',
            'tax' => '0.00',
            'taxrate' => '0.00',
            'items' => ['item' => [
                ['id' => 1, 'type' => 'Invoice', 'relid' => 31690, 'description' => 'Αρ. Λογαριασμού #31690', 'amount' => '76.88', 'taxed' => '0'],
                ['id' => 2, 'type' => 'Invoice', 'relid' => 31684, 'description' => 'Αρ. Λογαριασμού #31684', 'amount' => '541.88', 'taxed' => '0'],
            ]],
        ];
    }

    public function test_detects_consolidated_by_type_and_relid(): void
    {
        $refs = PendingWhmcsInvoice::detectConsolidatedRefs($this->consolidatedPayload());

        $this->assertSame([31690, 31684], $refs);
    }

    public function test_detects_consolidated_by_description_when_type_absent(): void
    {
        // Slimmed feed: no per-line type → fall back to untaxed + invoice-ref text.
        $refs = PendingWhmcsInvoice::detectConsolidatedRefs([
            'items' => ['item' => [
                ['description' => 'Invoice #100', 'amount' => '10.00', 'taxed' => '0'],
                ['description' => 'Αρ. Λογαριασμού #101', 'amount' => '20.00', 'taxed' => '0'],
            ]],
        ]);

        $this->assertSame([100, 101], $refs);
    }

    public function test_normal_invoice_is_not_consolidated(): void
    {
        $this->assertNull(PendingWhmcsInvoice::detectConsolidatedRefs([
            'items' => ['item' => [
                ['type' => 'Hosting', 'relid' => 55, 'description' => 'Hosting 1y', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]));
    }

    public function test_mixed_invoice_with_one_reference_line_is_not_blocked_wholesale(): void
    {
        // A real product line alongside a reference line → NOT a pure mass-pay.
        $this->assertNull(PendingWhmcsInvoice::detectConsolidatedRefs([
            'items' => ['item' => [
                ['type' => 'Invoice', 'relid' => 900, 'description' => 'Αρ. Λογαριασμού #900', 'amount' => '50.00', 'taxed' => '0'],
                ['type' => 'Hosting', 'relid' => 0, 'description' => 'Hosting 1y', 'amount' => '124.00', 'taxed' => '1'],
            ]],
        ]));
    }

    public function test_untaxed_exempt_product_is_not_mistaken_for_mass_pay(): void
    {
        // No type + untaxed but the text isn't an invoice reference → real sale.
        $this->assertNull(PendingWhmcsInvoice::detectConsolidatedRefs([
            'items' => ['item' => [
                ['description' => 'Exempt service', 'amount' => '50.00', 'taxed' => '0'],
            ]],
        ]));
    }

    public function test_handles_single_item_object_shape(): void
    {
        // WHMCS returns a bare object (not a list) when there's exactly one line.
        $refs = PendingWhmcsInvoice::detectConsolidatedRefs([
            'items' => ['item' => ['type' => 'Invoice', 'relid' => 42, 'description' => 'Invoice #42', 'amount' => '9.00', 'taxed' => '0']],
        ]);

        $this->assertSame([42], $refs);
    }

    public function test_ingest_holds_a_consolidated_invoice_with_reason(): void
    {
        $tenant = Company::create([
            'name' => 'Ing', 'slug' => 'cons-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);

        $result = app(WhmcsInvoiceIngestor::class)->ingest($tenant, $this->consolidatedPayload());

        $row = $result->row;
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $row->status);
        $this->assertNotNull($row->hold_reason);
        $this->assertStringContainsString('mass-pay', $row->hold_reason);
        $this->assertStringContainsString('#31690', $row->hold_reason);
        $this->assertTrue($row->isConsolidatedPayment());
    }

    public function test_auto_issue_refuses_a_consolidated_pending_row(): void
    {
        // Simulate a row staged BEFORE the detector landed (status=pending_review)
        // on an armed tenant with an άμεση-τιμολόγηση customer: auto-issue must
        // refuse to file it (chooseType returns a hold), leaving it in the inbox.
        $tenant = Company::create([
            'name' => 'Auto', 'slug' => 'cons-ai-'.uniqid(), 'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php',
            'whmcs_api_identifier' => 'id', 'whmcs_api_secret' => 'secret',
            'whmcs_auto_issue_immediate' => true,
        ]);
        VatCategory::create(['company_id' => $tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true]);
        VatCategory::create(['company_id' => $tenant->id, 'name' => '0%', 'rate' => 0.00, 'is_default' => false]);
        $pm = PaymentMethod::create(['company_id' => $tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true]);
        $type = InvoiceType::create(['company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1, 'payment_method_id' => $pm->id]);
        $tenant->update(['whmcs_default_invoice_type_id' => $type->id]);

        $customer = Customer::create([
            'company_id' => $tenant->id, 'name' => 'Γκρινιάρης', 'afm' => '111111111',
            'needs_immediate_invoice' => true,
        ]);

        $pending = PendingWhmcsInvoice::create([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => 31732,
            'payload' => $this->consolidatedPayload(),
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $customer->id,
        ]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $fresh->status);
        $this->assertNull($fresh->invoice_id, 'a consolidated payment must never be auto-filed');
    }
}
