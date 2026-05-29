<?php

namespace Tests\Feature\WhmcsInbox;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PaymentMethod;
use App\Models\PendingWhmcsInvoice;
use App\Models\VatCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * G8 phase 2 — whmcs:auto-issue. The command files paid inbox rows at AADE
 * for γκρινιάρης (needs_immediate_invoice) customers, but ONLY on armed
 * tenants and ONLY for unambiguous single-party rows. Tenant runs 'off'
 * mode → NullSubmitter (no HTTP, no MARK), so the orchestration is tested
 * without AADE creds.
 *
 * Locks the gate: armed + γκρινιάρης + clean → filed; everything else is
 * left in the inbox.
 */
class WhmcsAutoIssueCommandTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(array $overrides = []): Company
    {
        $tenant = Company::create(array_merge([
            'name' => 'Auto',
            'slug' => 'ai-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',               // NullSubmitter
            'whmcs_api_url' => 'https://whmcs.example.test/includes/api.php',
            'whmcs_auto_issue_immediate' => true,
        ], $overrides));

        VatCategory::create([
            'company_id' => $tenant->id, 'name' => '24%', 'rate' => 24.00, 'is_default' => true,
        ]);
        VatCategory::create([
            'company_id' => $tenant->id, 'name' => '0%', 'rate' => 0.00, 'is_default' => false,
        ]);
        $pm = PaymentMethod::create([
            'company_id' => $tenant->id, 'name' => 'Cash', 'due_days' => 0, 'is_active' => true,
        ]);
        $type = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ',
            'invcount' => 1, 'payment_method_id' => $pm->id,
        ]);

        // Set the default invoice type unless the test overrode it to null.
        if (! array_key_exists('whmcs_default_invoice_type_id', $overrides)) {
            $tenant->update(['whmcs_default_invoice_type_id' => $type->id]);
        }

        return $tenant->fresh();
    }

    private function customer(Company $tenant, bool $grumpy): Customer
    {
        return Customer::create([
            'company_id' => $tenant->id,
            'name' => $grumpy ? 'Γκρινιάρης' : 'Ήσυχος',
            'afm' => '111111111',
            'needs_immediate_invoice' => $grumpy,
        ]);
    }

    private function pending(Company $tenant, Customer $customer, array $overrides = []): PendingWhmcsInvoice
    {
        static $seq = 9000;

        return PendingWhmcsInvoice::create(array_merge([
            'company_id' => $tenant->id,
            'whmcs_invoice_id' => ++$seq,
            'payload' => [
                'invoiceid' => $seq,
                'userid' => 1,
                'date' => '2026-05-20',
                'total' => '124.00',
                'items' => ['item' => [
                    ['description' => 'Hosting 1y', 'amount' => '124.00', 'taxed' => '1'],
                ]],
            ],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'customer_id' => $customer->id,
        ], $overrides));
    }

    public function test_files_grumpy_single_party_row_on_armed_tenant(): void
    {
        $tenant = $this->tenant();
        $customer = $this->customer($tenant, grumpy: true);
        $pending = $this->pending($tenant, $customer);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertNotNull($fresh->invoice_id);
        $this->assertNull($fresh->filed_by_user_id, 'auto-issue is system-filed (no operator)');
        $this->assertStringContainsString('γκρινιάρης', (string) $fresh->notes);
        $this->assertSame('ΤΠΥ1', Invoice::find($fresh->invoice_id)->invcode);
    }

    public function test_does_not_file_non_grumpy_customer(): void
    {
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, $this->customer($tenant, grumpy: false));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->invoice_id);
    }

    public function test_does_not_file_when_tenant_not_armed(): void
    {
        $tenant = $this->tenant(['whmcs_auto_issue_immediate' => false]);
        $pending = $this->pending($tenant, $this->customer($tenant, grumpy: true));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_skips_tenant_without_default_invoice_type(): void
    {
        $tenant = $this->tenant(['whmcs_default_invoice_type_id' => null]);
        $pending = $this->pending($tenant, $this->customer($tenant, grumpy: true));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_does_not_file_held_or_multi_party_rows(): void
    {
        $tenant = $this->tenant();
        $customer = $this->customer($tenant, grumpy: true);

        $held = $this->pending($tenant, $customer, ['status' => PendingWhmcsInvoice::STATUS_HELD]);
        $multi = $this->pending($tenant, $customer, [
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI,
            'status' => PendingWhmcsInvoice::STATUS_HELD,
        ]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $held->fresh()->status);
        $this->assertSame(PendingWhmcsInvoice::STATUS_HELD, $multi->fresh()->status);
        $this->assertNull($held->fresh()->invoice_id);
    }

    public function test_dry_run_files_nothing(): void
    {
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, $this->customer($tenant, grumpy: true));

        $this->artisan('whmcs:auto-issue --dry-run')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->invoice_id);
    }

    public function test_unknown_tenant_slug_exits_invalid(): void
    {
        $this->artisan('whmcs:auto-issue --tenant=nope-nope')->assertExitCode(2);
    }
}
