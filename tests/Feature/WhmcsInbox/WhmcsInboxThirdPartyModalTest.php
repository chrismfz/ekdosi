<?php

namespace Tests\Feature\WhmcsInbox;

use App\Filament\Resources\WhmcsInbox\Tables\WhmcsInboxTable;
use App\Models\Company;
use App\Models\PendingWhmcsInvoice;
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
}
