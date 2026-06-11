<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Tables\WhmcsInboxTable;
use App\Filament\Resources\WhmcsInbox\WhmcsInboxResource;
use App\Models\Company;
use App\Models\Customer;
use App\Models\PendingWhmcsInvoice;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression cover for the createDraft modal's third-party routing block: the
 * routedBeneficiaries() helper is reached from the form's ->visible()/->content()
 * closures, so a missing helper (a silently-dropped edit) 500s the inbox's
 * primary action for EVERY row — invisible to php -l. These tests fail loudly
 * if the helper goes missing or its data-shaping breaks.
 */
class WhmcsInboxThirdPartyModalTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Company::create([
            'name' => 'Inbox', 'slug' => 'inbox-'.uniqid(),
            'country_code' => 'GR', 'einvoice_provider' => 'gr-mydata', 'mydata_mode' => 'off',
        ]);
    }

    private function pending(?array $resolution): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id,
            'whmcs_invoice_id' => random_int(1, 9_999_999),   // unique per call (unique key = company_id+whmcs_invoice_id)
            'whmcs_userid' => 793,
            'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_EMAIL,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'third_party_state' => $resolution !== null ? PendingWhmcsInvoice::TP_SINGLE : null,
            'third_party_resolution' => $resolution,
        ]);
    }

    public function test_routed_beneficiaries_shapes_the_resolution(): void
    {
        $row = $this->pending([
            'lines' => [
                ['routed' => true, 'is_receipt' => false, 'contact' => [
                    'id' => 5, 'company_name' => 'ΣΥΝΔΕΣΜΟΣ ΚΑΒΑΛΑΣ', 'gr_vatno' => 'EL 090054207',
                ]],
                ['routed' => true, 'is_receipt' => true, 'contact' => [
                    'id' => 5, 'company_name' => 'ΣΥΝΔΕΣΜΟΣ ΚΑΒΑΛΑΣ', 'gr_vatno' => 'EL 090054207',
                ]],
                ['routed' => false, 'contact' => null],   // client-billed line, ignored
            ],
        ]);

        $m = new \ReflectionMethod(WhmcsInboxTable::class, 'routedBeneficiaries');
        $m->setAccessible(true);
        /** @var list<array{name:string,afm:string,lines:int,is_receipt:bool}> $out */
        $out = $m->invoke(null, $row);

        $this->assertCount(1, $out);                      // both routed lines collapse to one contact
        $this->assertSame('ΣΥΝΔΕΣΜΟΣ ΚΑΒΑΛΑΣ', $out[0]['name']);
        $this->assertSame('090054207', $out[0]['afm']);   // digits-normalised
        $this->assertSame(2, $out[0]['lines']);
        $this->assertTrue($out[0]['is_receipt']);         // any receipt line flags it
    }

    public function test_routed_beneficiaries_empty_when_no_resolution(): void
    {
        $m = new \ReflectionMethod(WhmcsInboxTable::class, 'routedBeneficiaries');
        $m->setAccessible(true);

        $this->assertSame([], $m->invoke(null, $this->pending(null)));
        $this->assertSame([], $m->invoke(null, $this->pending(['lines' => []])));
    }

    public function test_routing_rows_maps_each_line_to_its_beneficiary_and_type(): void
    {
        // Reseller WITH ΑΦΜ + one line routed to an end-customer (the «Πολλοί» case).
        $reseller = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Reseller', 'afm' => '700700700']);
        $row = PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => random_int(1, 9_999_999),
            'customer_id' => $reseller->id, 'payload' => [], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
            'third_party_state' => PendingWhmcsInvoice::TP_MULTI,
            'third_party_resolution' => ['lines' => [
                ['item_id' => 1, 'description' => 'domain.gr', 'routed' => true, 'is_receipt' => false,
                    'contact' => ['id' => 5, 'company_name' => 'Haris', 'gr_vatno' => '081951154']],
                ['item_id' => 2, 'description' => 'hosting', 'routed' => false, 'is_receipt' => false, 'contact' => null],
            ]],
        ]);

        $m = new \ReflectionMethod(WhmcsInboxTable::class, 'routingRows');
        $m->setAccessible(true);
        $rows = $m->invoke(null, $row);

        $this->assertCount(2, $rows);
        // Routed line → the end-customer, his ΑΦΜ, τιμολόγιο (route is_receipt=false).
        $this->assertSame('domain.gr', $rows[0]['line']);
        $this->assertSame('Haris', $rows[0]['who']);
        $this->assertSame('081951154', $rows[0]['afm']);
        $this->assertFalse($rows[0]['receipt']);
        $this->assertTrue($rows[0]['routed']);
        // Own line → the reseller (has ΑΦΜ → τιμολόγιο), marked as «ίδιος».
        $this->assertStringContainsString('Reseller', $rows[1]['who']);
        $this->assertSame('700700700', $rows[1]['afm']);
        $this->assertFalse($rows[1]['receipt']);
        $this->assertFalse($rows[1]['routed']);
    }

    public function test_nav_badge_turns_red_only_when_an_immediate_row_waits(): void
    {
        $user = User::create(['name' => 'Op', 'email' => 'op-'.uniqid().'@t.local', 'password' => bcrypt('x')]);
        $user->companies()->attach($this->tenant->id);
        $this->actingAs($user);
        Filament::setTenant($this->tenant);
        $this->assertNull(WhmcsInboxResource::getNavigationBadge());

        // A plain pending row → warning, count 1.
        $plain = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Ήσυχος', 'needs_immediate_invoice' => false]);
        $this->pendingFor($plain);
        $this->assertSame('1', WhmcsInboxResource::getNavigationBadge());
        $this->assertSame('warning', WhmcsInboxResource::getNavigationBadgeColor());

        // Add an immediate one → red.
        $hot = Customer::create(['company_id' => $this->tenant->id, 'name' => 'Άμεσος', 'needs_immediate_invoice' => true]);
        $this->pendingFor($hot);
        $this->assertSame('2', WhmcsInboxResource::getNavigationBadge());
        $this->assertSame('danger', WhmcsInboxResource::getNavigationBadgeColor());
    }

    private function pendingFor(Customer $c): PendingWhmcsInvoice
    {
        return PendingWhmcsInvoice::create([
            'company_id' => $this->tenant->id, 'whmcs_invoice_id' => random_int(1, 9_999_999),
            'customer_id' => $c->id, 'payload' => [], 'match_reason' => PendingWhmcsInvoice::REASON_LINKED,
            'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW,
        ]);
    }
}
