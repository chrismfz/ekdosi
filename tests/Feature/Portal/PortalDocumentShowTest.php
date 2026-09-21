<?php

namespace Tests\Feature\Portal;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerUser;
use App\Models\CustomerUserAccess;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\InvoiceType;
use App\Models\Payment;
use App\Services\Portal\CustomerDocumentFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The portal's read-only HTML «online προβολή» of a document. Same fail-closed
 * grant boundary as the PDF route (a login sees a document ONLY through an active
 * grant), plus the linked-documents surface (credit note ↔ original, receipts).
 */
class PortalDocumentShowTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create([
            'name' => 'NEXON', 'slug' => 'docshow-'.uniqid(), 'afm' => '801280908',
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

    private function grant(CustomerUser $login, Customer $customer): CustomerUserAccess
    {
        return CustomerUserAccess::create([
            'customer_user_id' => $login->id,
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'role' => CustomerUserAccess::ROLE_OWNER,
            'granted_at' => now(),
        ]);
    }

    private function type(Company $t): InvoiceType
    {
        return InvoiceType::create(['company_id' => $t->id, 'name' => 'Τιμολόγιο παροχής', 'code' => 'ΤΠΥ', 'invcount' => 1]);
    }

    private function invoice(Company $t, InvoiceType $it, Customer $c, string $invcode, int $code, string $local, ?string $state, array $extra = []): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $t->id, 'invoice_type_id' => $it->id, 'customer_id' => $c->id,
            'invcode' => $invcode, 'code' => $code, 'issued_at' => now(),
        ]);
        $inv->forceFill(array_merge([
            'local_status' => $local, 'mydata_state' => $state,
            'net_total' => 100, 'gross_total' => 124,
        ], $extra))->save();

        return $inv;
    }

    private function line(Invoice $inv, string $descr, float $qty = 1): InvoiceLine
    {
        return InvoiceLine::create([
            'company_id' => $inv->company_id, 'invoice_id' => $inv->id,
            'qty' => $qty, 'price_per_item' => 100, 'vat_percent' => 24,
            'net_price' => 100, 'gross_price' => 124, 'product_descr' => $descr,
        ]);
    }

    public function test_show_renders_for_a_granted_live_document(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $inv = $this->invoice($t, $it, $cust, 'ΤΠΥ6665', 6665, 'active', 'VALID');
        $this->line($inv, 'Υπηρεσία υποστήριξης', 2.5);

        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get("/user/document/{$inv->id}")
            ->assertOk()
            ->assertSee('ΤΠΥ6665')
            ->assertSee('Υπηρεσία υποστήριξης')
            ->assertSee('Τιμολόγιο παροχής')
            // Greek decimal separator for qty (consistent with Money::eur), not «2.5».
            ->assertSee('2,5');
    }

    public function test_show_404_for_a_non_granted_document(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $mine = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $other = Customer::create(['company_id' => $t->id, 'name' => 'Other', 'afm' => '2']);
        $otherInv = $this->invoice($t, $it, $other, 'ΤΠΥ9', 9, 'active', 'VALID');

        $login = $this->login();
        $this->grant($login, $mine);   // granted to Mine, NOT Other

        $this->actingAs($login, 'portal')->get("/user/document/{$otherInv->id}")->assertStatus(404);
    }

    public function test_show_404_for_a_draft_or_cancelled_document(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $draft = $this->invoice($t, $it, $cust, 'ΤΠΥ2', 2, 'draft', null);
        $cancelled = $this->invoice($t, $it, $cust, 'ΤΠΥ3', 3, 'active', 'CANCELLED');

        $login = $this->login();
        $this->grant($login, $cust);

        foreach ([$draft->id, $cancelled->id] as $id) {
            $this->actingAs($login, 'portal')->get("/user/document/{$id}")->assertStatus(404);
        }
    }

    public function test_credit_note_and_original_link_to_each_other(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '090000045']);
        $original = $this->invoice($t, $it, $cust, 'ΤΠΥ6655', 6655, 'active', 'VALID');
        $credit = $this->invoice($t, $it, $cust, 'ΠΙΣ10', 10, 'active', 'VALID', [
            'credited_invoice_id' => $original->id,
        ]);

        $login = $this->login();
        $this->grant($login, $cust);

        // Original lists its credit note…
        $this->actingAs($login, 'portal')->get("/user/document/{$original->id}")
            ->assertOk()
            ->assertSee('ΠΙΣ10')
            ->assertSee("/user/document/{$credit->id}\"", false);

        // …and the credit note links back to its original.
        $this->actingAs($login, 'portal')->get("/user/document/{$credit->id}")
            ->assertOk()
            ->assertSee('ΤΠΥ6655')
            ->assertSee("/user/document/{$original->id}\"", false);
    }

    public function test_receipts_are_listed_on_the_document(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $inv = $this->invoice($t, $it, $cust, 'ΤΠΥ6661', 6661, 'active', 'VALID');
        Payment::create([
            'company_id' => $t->id, 'customer_id' => $cust->id, 'invoice_id' => $inv->id,
            'kind' => 'payment', 'amount' => 124, 'pay_date' => now(),
        ]);

        $login = $this->login();
        $this->grant($login, $cust);

        $this->actingAs($login, 'portal')->get("/user/document/{$inv->id}")
            ->assertOk()
            ->assertSee('Εισπράξεις / Πληρωμές');
    }

    public function test_home_and_statement_link_to_the_document(): void
    {
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'Mine', 'afm' => '1']);
        $inv = $this->invoice($t, $it, $cust, 'ΤΠΥ6654', 6654, 'active', 'VALID');

        $login = $this->login();
        $this->grant($login, $cust);

        // «Τα παραστατικά μου» — the document name is a link to its online view.
        $this->actingAs($login, 'portal')->get('/user')
            ->assertOk()
            ->assertSee("/user/document/{$inv->id}\"", false);

        // «Η καρτέλα μου» — the document movement row links too (needs a receivable
        // to appear on the ledger: credit-term via a recorded payment path is not
        // required — the invoice row itself is listed).
        $this->actingAs($login, 'portal')->get('/user/statement')
            ->assertOk()
            ->assertSee("/user/document/{$inv->id}\"", false);
    }

    public function test_visible_id_set_excludes_non_customer_visible_docs(): void
    {
        // The ledger link-gate: only customer-visible docs may be linked, so a
        // draft/cancelled row on «Η καρτέλα μου» never renders a dead 404 link.
        $t = $this->company();
        $it = $this->type($t);
        $cust = Customer::create(['company_id' => $t->id, 'name' => 'C', 'afm' => '1']);
        $active = $this->invoice($t, $it, $cust, 'ΤΠΥ1', 1, 'active', 'VALID');
        $draft = $this->invoice($t, $it, $cust, 'ΤΠΥ2', 2, 'draft', null);
        $cancelled = $this->invoice($t, $it, $cust, 'ΤΠΥ3', 3, 'active', 'CANCELLED');

        $set = app(CustomerDocumentFeed::class)->visibleDocumentIdSet($t->id, $cust->id);

        $this->assertArrayHasKey($active->id, $set);
        $this->assertArrayNotHasKey($draft->id, $set);
        $this->assertArrayNotHasKey($cancelled->id, $set);
    }

    public function test_show_requires_portal_auth(): void
    {
        $this->get('/user/document/1')
            ->assertRedirect(route('portal.login'))
            ->assertSessionHas('url.intended', url('/user/document/1'));
    }
}
