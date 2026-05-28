<?php

namespace Tests\Feature\Whmcs;

use App\Services\Whmcs\ThirdPartyResolution;
use Tests\TestCase;

/**
 * T-1a: party/multi-party logic of the resolution value object. Pure — no DB.
 */
class ThirdPartyResolutionTest extends TestCase
{
    private function contact(int $id, string $name = 'Acme', string $afm = '111111111'): array
    {
        return ['id' => $id, 'company_name' => $name, 'gr_vatno' => $afm];
    }

    private function line(?array $contact, bool $receipt = false, string $type = 'Domain'): array
    {
        return [
            'item_id' => random_int(1, 99999),
            'relid' => 100,
            'type' => $type,
            'service_type' => strtolower($type),
            'description' => 'x',
            'routed' => $contact !== null,
            'is_receipt' => $receipt,
            'contact' => $contact,
        ];
    }

    public function test_no_routing_is_single_party_reseller(): void
    {
        $res = new ThirdPartyResolution(10, 793, true, [
            $this->line(null),
            $this->line(null),
        ]);

        $this->assertFalse($res->hasAnyRouting());
        $this->assertSame(1, $res->distinctParties());
        $this->assertFalse($res->isMultiParty());
        $this->assertNull($res->singleContact(), 'unrouted invoice has no single third-party contact');
    }

    public function test_whole_invoice_to_one_contact_is_single_contact(): void
    {
        $c = $this->contact(5, 'Haris', '081951154');
        $res = new ThirdPartyResolution(10, 793, true, [
            $this->line($c),
            $this->line($c),
        ]);

        $this->assertTrue($res->hasAnyRouting());
        $this->assertSame(1, $res->distinctParties());
        $this->assertFalse($res->isMultiParty());
        $this->assertNotNull($res->singleContact());
        $this->assertSame('Haris', $res->singleContact()['company_name']);
    }

    public function test_two_contacts_is_multi_party(): void
    {
        $res = new ThirdPartyResolution(10, 793, true, [
            $this->line($this->contact(1)),
            $this->line($this->contact(2)),
        ]);

        $this->assertSame(2, $res->distinctParties());
        $this->assertTrue($res->isMultiParty());
        $this->assertNull($res->singleContact(), 'multi-party has no single contact');
    }

    public function test_one_contact_plus_reseller_line_is_multi_party(): void
    {
        // hosting.gr line → Haris, but a domain line Chris keeps for himself.
        $res = new ThirdPartyResolution(10, 793, true, [
            $this->line($this->contact(5, 'Haris')),
            $this->line(null),
        ]);

        $this->assertSame(2, $res->distinctParties());
        $this->assertTrue($res->isMultiParty());
        $this->assertNull($res->singleContact(), 'mixed reseller+contact is not a clean single party');
        $this->assertCount(1, $res->routedLines());
    }

    public function test_from_bridge_response_parses_and_tolerates_absent_timologia(): void
    {
        $res = ThirdPartyResolution::fromBridgeResponse([
            'whmcs_invoice_id' => 42,
            'userid' => 793,
            'timologia_present' => false,
            'lines' => [],
        ]);

        $this->assertSame(42, $res->whmcsInvoiceId);
        $this->assertSame(793, $res->whmcsUserId);
        $this->assertFalse($res->timologiaPresent);
        $this->assertSame(0, $res->distinctParties());
        $this->assertFalse($res->isMultiParty());
    }

    public function test_from_bridge_response_defends_against_malformed_lines(): void
    {
        $res = ThirdPartyResolution::fromBridgeResponse([
            'whmcs_invoice_id' => 1,
            'userid' => 2,
            'lines' => 'not-an-array',
        ]);

        $this->assertSame([], $res->lines);
    }
}
