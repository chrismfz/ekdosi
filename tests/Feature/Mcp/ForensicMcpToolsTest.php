<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\EkdosiMcpServer;
use App\Mcp\Tools\InvoiceFilingMcpTool;
use App\Mcp\Tools\MyDataFailuresMcpTool;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\MyDataMark;
use App\Models\User;
use App\Services\TenantRoleProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The OBS-001 forensic tools egress the filing audit trail (state + raw
 * mydata_marks XML) to an external MCP client. Two things must hold and are
 * locked here: (1) they are super_admin-only — a tenant member never even sees
 * them; (2) when they DO run, they surface the stored evidence (MARK, error
 * codes) for the named document. Everything is read-only; nothing files.
 */
class ForensicMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(Company $company): User
    {
        $user = User::create([
            'name' => 'Root',
            'email' => 'root-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($company->id);
        // A SYSTEM super_admin = holds the super_admin role in ANY company (the
        // exact gate SuperAdminMcpTool::shouldRegister uses). Grant it the way the
        // app does — the provisioner, teams-mode aware — not a bare assignRole.
        app(TenantRoleProvisioner::class)->assignSuperAdmin($user, $company);

        return $user->fresh();
    }

    private function member(Company $company): User
    {
        $user = User::create([
            'name' => 'Op',
            'email' => 'op-'.uniqid().'@test.local',
            'password' => bcrypt('x'),
        ]);
        $user->companies()->attach($company->id);

        return $user->fresh();
    }

    private function company(string $slug): Company
    {
        return Company::create([
            'name' => strtoupper($slug),
            'slug' => $slug.'-'.uniqid(),
            'country_code' => 'GR',
        ]);
    }

    private function rejectedInvoice(Company $company): Invoice
    {
        $type = InvoiceType::create([
            'company_id' => $company->id,
            'code' => 'TPY',
            'name' => 'ΤΠΥ',
            'invcount' => 1,
            'mydata_type' => '2.1',
            'mydata_income_class' => 'E3_561_001',
            'mydata_income_class_category' => 'category1_3',
        ]);
        $customer = Customer::create([
            'company_id' => $company->id,
            'name' => 'Πελάτης ΑΕ',
            'afm' => '090000045',
        ]);
        $invoice = Invoice::create([
            'company_id' => $company->id,
            'invcode' => 'TPY1',
            'series' => 'TPY',
            'code' => 1,
            'invoice_type_id' => $type->id,
            'customer_id' => $customer->id,
            'issued_at' => now(),
            'header_discount_percent' => 0,
            'local_status' => 'active',
            'country' => 'GR',
            'net_total' => 100,
            'gross_total' => 124,
        ]);

        MyDataMark::create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'mark' => null,
            'mydata_action' => 'REJECTED',
            'request' => '<InvoicesDoc>...</InvoicesDoc>',
            'response' => '<response><statusCode>ValidationError</statusCode><errors><error><code>229</code><message>invalid</message></error></errors></response>',
            'mark_date' => now()->toDateString(),
            'mark_time' => now()->toTimeString(),
        ]);

        return $invoice;
    }

    public function test_invoice_filing_surfaces_the_rejection_for_a_super_admin(): void
    {
        $company = $this->company('acme');
        $invoice = $this->rejectedInvoice($company);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($company))
            ->tool(InvoiceFilingMcpTool::class, ['invoice' => $invoice->invcode]);

        $response->assertOk();
        // The stored rejection code is extracted from the response XML.
        $response->assertSee('229');
        $response->assertSee('REJECTED');
    }

    public function test_invoice_filing_include_xml_returns_the_raw_payload(): void
    {
        $company = $this->company('acme');
        $invoice = $this->rejectedInvoice($company);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($company))
            ->tool(InvoiceFilingMcpTool::class, ['invoice' => (string) $invoice->id, 'include_xml' => true]);

        $response->assertOk();
        $response->assertSee('InvoicesDoc');
    }

    public function test_mydata_failures_lists_rejected_rows_with_codes(): void
    {
        $company = $this->company('acme');
        $this->rejectedInvoice($company);

        $response = EkdosiMcpServer::actingAs($this->superAdmin($company))
            ->tool(MyDataFailuresMcpTool::class, []);

        $response->assertOk();
        $response->assertSee('229');
    }

    public function test_forensic_tools_are_not_offered_to_a_tenant_member(): void
    {
        $company = $this->company('acme');
        $invoice = $this->rejectedInvoice($company);

        $response = EkdosiMcpServer::actingAs($this->member($company))
            ->tool(InvoiceFilingMcpTool::class, ['invoice' => $invoice->invcode]);

        // shouldRegister() is false for a non-super-admin, so the tool is not
        // available — the sensitive XML/state never egresses to a member.
        $response->assertHasErrors();
    }
}
