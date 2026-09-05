<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Services\InvoicePdfRenderer;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The customer portal's documents view — the FIRST customer-facing data
 * exposure. The load-bearing property: a login sees a customer's documents ONLY
 * through an ACTIVE grant (never by ΑΦΜ or company alone), and the PDF route
 * re-derives that per request. No leak across customers, companies, or logins.
 */
class PortalDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'T', 'slug' => 'doc-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function login(): CustomerUser
    {
        return CustomerUser::factory()->create([
            'password' => Hash::make('secret-pass-123'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ]);
    }

    private function grant(CustomerUser $login, Customer $customer, string $role = CustomerUserAccess::ROLE_OWNER): CustomerUserAccess
    {
        return CustomerUserAccess::create([
            'customer_user_id' => $login->id,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'role' => $role,
            'granted_at' => now(),
        ]);
    }

    private function invoice(Company $t, InvoiceType $it, Customer $c, string $invcode, int $code, string $local, ?string $state): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id, 'customer_id' => $c->id,
            'invcode' => $invcode, 'code' => $code, 'issued_at' => now(),
        ]);
        $inv->forceFill(['local_status' => $local, 'mydata_state' => $state])->save();

        return $inv;
    }

    private function type(Company $t): InvoiceType
    {
        return InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
    }

    public function test_feed_returns_only_granted_customers_live_documents(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '801280908']);

        $live = $this->invoice($t, $it, $mine, 'ΤΠΥ1', 1, 'active', 'VALID');
        $this->invoice($t, $it, $mine, 'ΤΠΥ2', 2, 'draft', null);          // draft → hidden
        $this->invoice($t, $it, $mine, 'ΤΠΥ3', 3, 'active', 'CANCELLED');   // cancelled → hidden
        $this->invoice($t, $it, $other, 'ΤΠΥ9', 9, 'active', 'VALID');      // not granted → hidden

        $login = $this->login();
        $this->grant($login, $mine);

        $groups = app(CustomerDocumentFeed::class)->forLogin($login);

        $this->assertCount(1, $groups);
        $ids = collect($groups[0]['documents'])->pluck('id')->all();
        $this->assertSame([$live->id], $ids);   // only the live, granted-customer doc
    }

    public function test_a_revoked_grant_yields_no_documents(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $this->invoice($t, $it, $cust, 'ΤΠΥ1', 1, 'active', 'VALID');

        $login = $this->login();
        $this->grant($login, $cust)->forceFill(['revoked_at' => now()])->save();

        $this->assertCount(0, app(CustomerDocumentFeed::class)->forLogin($login));
    }

    public function test_home_page_renders_documents(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $this->invoice($t, $it, $cust, 'ΤΠΥ42', 42, 'active', 'VALID');
        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get('/user')
            ->assertOk()
            ->assertSee('Τα παραστατικά μου')
            ->assertSee('ΤΠΥ42');
    }

    public function test_pdf_streams_for_a_granted_live_document(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldReceive('render')->once()->andReturn('%PDF-1.4 fake');

        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $inv = $this->invoice($t, $it, $cust, 'ΤΠΥ1', 1, 'active', 'VALID');
        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get("/user/document/{$inv->id}/pdf")
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_404_for_a_non_granted_document(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->company();
        $it = $this->type($t);
        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '2']);
        $otherInv = $this->invoice($t, $it, $other, 'ΤΠΥ9', 9, 'active', 'VALID');
        $login = $this->login();
        $this->grant($login, $mine);   // granted to Mine, NOT Other

        $this->actingAs($login, 'portal')->get("/user/document/{$otherInv->id}/pdf")->assertStatus(404);
    }

    public function test_pdf_404_for_a_draft_or_cancelled_document(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');

        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $draft = $this->invoice($t, $it, $cust, 'ΤΠΥ2', 2, 'draft', null);
        $cancelled = $this->invoice($t, $it, $cust, 'ΤΠΥ3', 3, 'active', 'CANCELLED');
        $login = $this->login();
        $this->grant($login, $cust);

        foreach ([$draft->id, $cancelled->id] as $id) {
            $this->actingAs($login, 'portal')->get("/user/document/{$id}/pdf")->assertStatus(404);
        }
    }

    public function test_pdf_requires_portal_auth(): void
    {
        $this->mock(InvoicePdfRenderer::class)->shouldNotReceive('render');
        $this->get('/user/document/1/pdf')->assertRedirect(route('portal.login'));
    }

    public function test_a_suspended_login_is_logged_out_mid_session(): void
    {
        $login = $this->login();

        // A REAL login (not actingAs, which would pin the in-memory object) so the
        // middleware's genuine per-request DB reload is what's under test.
        $this->post('/user/login', ['email' => $login->email, 'password' => 'secret-pass-123'])
            ->assertRedirect(route('portal.home'));
        $this->assertAuthenticatedAs($login, 'portal');

        // Suspend via the query builder — no cached model is mutated.
        CustomerUser::query()->whereKey($login->id)->update(['status' => CustomerUser::STATUS_SUSPENDED]);

        // Forget the in-memory guard so the next request re-resolves the user from
        // the session id → DB, exactly as a fresh php-fpm request does in
        // production (where each request is a new guard). Without this, the guard
        // would return its cached, still-active user and mask the reload path.
        $this->app['auth']->forgetGuards();

        $this->get('/user')->assertRedirect(route('portal.login'));
        $this->assertGuest('portal');
    }
}
