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
 * for άμεση τιμολόγηση (needs_immediate_invoice) customers, but ONLY on armed
 * tenants and ONLY for unambiguous single-party rows. Tenant runs 'off'
 * mode → NullSubmitter (no HTTP, no MARK), so the orchestration is tested
 * without AADE creds.
 *
 * Locks the gate: armed + άμεση τιμολόγηση + clean → filed; everything else is
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
            'whmcs_api_identifier' => 'id',
            'whmcs_api_secret' => 'secret',
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
        $this->assertStringContainsString('άμεση τιμολόγηση', (string) $fresh->notes);
        $this->assertSame('ΤΠΥ1', Invoice::find($fresh->invoice_id)->invcode);
    }

    public function test_wh3_does_not_auto_issue_a_row_already_filed_in_legacy(): void
    {
        // WH-3: the legacy ekdosi app already filed this WHMCS invoice
        // (legacy_invoiced != 0). Auto-issuing it would double-declare income
        // at AADE during the dual-run — it must be excluded from candidates.
        $tenant = $this->tenant();
        $customer = $this->customer($tenant, grumpy: true);
        $pending = $this->pending($tenant, $customer, ['legacy_invoiced' => 3]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->invoice_id);
        $this->assertSame(0, Invoice::count());
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

    public function test_holds_an_unpaid_whmcs_row_instead_of_auto_issuing_it(): void
    {
        // Auto-issue is paid-only: an UNPAID WHMCS invoice (public-sector «τιμολόγιο
        // first, pay later») must NOT be auto-filed under the cash-term default —
        // that would record a real open receivable as SETTLED. It is held for the
        // operator, who issues it επί πιστώσει from the inbox.
        $tenant = $this->tenant();
        $customer = $this->customer($tenant, grumpy: true);
        $pending = $this->pending($tenant, $customer, [
            'payload' => [
                'invoiceid' => 7777, 'userid' => 1, 'date' => '2026-05-20', 'total' => '124.00',
                'status' => 'Unpaid',
                'items' => ['item' => [['description' => 'Hosting 1y', 'amount' => '124.00', 'taxed' => '1']]],
            ],
        ]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertNull($fresh->invoice_id, 'unpaid row must not be auto-filed');
        $this->assertNotSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
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

    public function test_does_not_file_row_whose_customer_belongs_to_another_tenant(): void
    {
        // The candidate query's whereHas('customer') is NOT company-scoped
        // (customer is belongsTo by id). The per-row company_id assertion
        // is the single CLI isolation guard — prove it holds: a pending
        // row on tenant A pointing at tenant B's άμεση τιμολόγηση customer must
        // NOT be auto-filed.
        $tenantA = $this->tenant();
        $tenantB = $this->tenant();   // separate company
        $foreignCustomer = $this->customer($tenantB, grumpy: true);

        $pending = $this->pending($tenantA, $foreignCustomer);   // company_id=A, customer_id=B's

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->invoice_id);
    }

    public function test_leaves_row_in_inbox_when_filer_rejects_zero_vat_without_exemption(): void
    {
        // Non-off mode → the filer's 0%-exempt guard is active. A taxed=0
        // line with no configured exemption reason makes file() throw
        // BEFORE any AADE submit; the command must catch it and leave the
        // row in the inbox for the operator (the 'failed' branch).
        $tenant = $this->tenant(['mydata_mode' => 'sandbox']);
        $customer = $this->customer($tenant, grumpy: true);

        $pending = $this->pending($tenant, $customer, [
            'payload' => [
                'invoiceid' => 7777,
                'userid' => 1,
                'date' => '2026-05-20',
                'total' => '50.00',
                'items' => ['item' => [
                    ['description' => 'Exempt service', 'amount' => '50.00', 'taxed' => '0'],
                ]],
            ],
        ]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $fresh->status);
        $this->assertNull($fresh->invoice_id);
    }

    private function noAfmCustomer(Company $tenant): Customer
    {
        return Customer::create([
            'company_id' => $tenant->id, 'name' => 'Λιανική', 'afm' => null,
            'needs_immediate_invoice' => true,
        ]);
    }

    public function test_no_afm_customer_is_held_without_a_receipt_type(): void
    {
        // A no-ΑΦΜ immediate customer needs an απόδειξη, but the tenant has no
        // default receipt type → HOLD (never mis-issue as a τιμολόγιο).
        $tenant = $this->tenant();
        $pending = $this->pending($tenant, $this->noAfmCustomer($tenant));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $pending->fresh()->status);
    }

    public function test_no_afm_customer_files_as_receipt_when_receipt_type_set(): void
    {
        $tenant = $this->tenant();
        $pmId = InvoiceType::where('company_id', $tenant->id)->value('payment_method_id');
        $receiptType = InvoiceType::create([
            'company_id' => $tenant->id, 'name' => 'ΑΛΠ', 'code' => 'ΑΛΠ',
            'invcount' => 0, 'payment_method_id' => $pmId,
        ]);
        $tenant->update(['whmcs_default_receipt_type_id' => $receiptType->id]);

        $pending = $this->pending($tenant, $this->noAfmCustomer($tenant));

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_FILED, $fresh->status);
        $this->assertSame($receiptType->id, Invoice::find($fresh->invoice_id)->invoice_type_id, 'no-ΑΦΜ → απόδειξη type');
    }

    public function test_wants_invoice_but_no_ekdosi_afm_is_held(): void
    {
        // Customer asked for a τιμολόγιο (wantsinvoice=on) but has no ekdosi ΑΦΜ →
        // can't file a valid invoice (the counterpart ΑΦΜ comes from customer.afm).
        // Must HOLD for the operator, not file an empty-ΑΦΜ τιμολόγιο.
        $tenant = $this->tenant(['whmcs_custom_field_map' => ['wantsinvoice' => 7]]);
        $customer = $this->noAfmCustomer($tenant);
        $pending = $this->pending($tenant, $customer, [
            'payload' => [
                'invoiceid' => 8123, 'userid' => 1, 'date' => '2026-05-20', 'total' => '124.00',
                'customfields' => [['id' => 7, 'value' => 'on']],
                'items' => ['item' => [['description' => 'Hosting', 'amount' => '124.00', 'taxed' => '1']]],
            ],
        ]);

        $this->artisan('whmcs:auto-issue')->assertExitCode(0);

        $fresh = $pending->fresh();
        $this->assertSame(PendingWhmcsInvoice::STATUS_PENDING_REVIEW, $fresh->status, 'wants invoice + no ΑΦΜ → held');
        $this->assertNull($fresh->invoice_id);
    }
}
