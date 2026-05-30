<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\InvoiceType;
use App\Models\PendingWhmcsInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP contract of GET /webhooks/whmcs/{slug}/invoice-map/{whmcs_userid} — the
 * 3-way mapping (WHMCS # → ekdosi παραστατικό → ΜΑΡΚ/state) the plugin renders
 * on a client's admin profile. Surfaces drafts (invoice exists, no MARK yet).
 */
class WhmcsClientInvoiceMapWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'map-secret-do-not-use-in-prod-xxxxx';

    private function tenant(): Company
    {
        return Company::create([
            'name' => 'MapTenant',
            'slug' => 'map-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    private function sig(string $slug, int $userid): string
    {
        return 'sha256='.hash_hmac('sha256', $slug.':map:'.$userid, self::SECRET);
    }

    private function invoice(Company $t, InvoiceType $it, string $invcode, int $code, string $local, ?string $state, ?string $mark): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $t->id,
            'invoice_type_id' => $it->id,
            'invcode' => $invcode,
            'code' => $code,
            'issued_at' => now(),
        ]);
        // local_status / mydata_* are written via forceFill in production
        // (not fillable — see CLAUDE.md money/myDATA cache notes).
        $inv->forceFill([
            'local_status' => $local,
            'mydata_state' => $state,
            'mydata_mark' => $mark,
        ])->save();

        return $inv;
    }

    public function test_returns_the_3way_mapping_including_drafts_scoped_to_the_client(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        $draft = $this->invoice($t, $it, 'ΤΠΥ129', 129, 'draft', null, null);
        $filed = $this->invoice($t, $it, 'ΤΠΥ130', 130, 'active', 'VALID', '400013690089505');

        // Client 793: a drafted row, a filed row, and one not-yet-an-invoice row.
        // 'drafted' is defined as PendingWhmcsInvoice::STATUS_DRAFTED on the
        // inbox branch; here (off main) it's just a pass-through status string —
        // the map endpoint reports whatever status the row carries.
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 31588, 'whmcs_userid' => 793, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => 'drafted', 'invoice_id' => $draft->id]);
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 31590, 'whmcs_userid' => 793, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_FILED, 'invoice_id' => $filed->id, 'mydata_mark' => '400013690089505']);
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 31591, 'whmcs_userid' => 793, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_UNMATCHED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);
        // A different client — must NOT leak into 793's map.
        PendingWhmcsInvoice::create(['company_id' => $t->id, 'whmcs_invoice_id' => 40000, 'whmcs_userid' => 999, 'payload' => [],
            'match_reason' => PendingWhmcsInvoice::REASON_LINKED, 'status' => PendingWhmcsInvoice::STATUS_PENDING_REVIEW]);

        $res = $this->withHeaders(['X-Webhook-Signature' => $this->sig($t->slug, 793)])
            ->getJson("/webhooks/whmcs/{$t->slug}/invoice-map/793");

        $res->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonCount(3, 'rows');

        $rows = collect($res->json('rows'))->keyBy('whmcs_invoice_id');

        // Draft: παραστατικό exists, no MARK yet ("half visibility").
        $this->assertSame('ΤΠΥ129', $rows[31588]['ekdosi_invcode']);
        $this->assertSame('drafted', $rows[31588]['pending_status']);
        $this->assertSame('draft', $rows[31588]['local_status']);
        $this->assertNull($rows[31588]['mydata_mark']);

        // Filed: full mapping.
        $this->assertSame('ΤΠΥ130', $rows[31590]['ekdosi_invcode']);
        $this->assertSame('VALID', $rows[31590]['mydata_state']);
        $this->assertSame('400013690089505', $rows[31590]['mydata_mark']);

        // No invoice yet.
        $this->assertNull($rows[31591]['ekdosi_invcode']);
    }

    public function test_rejects_a_bad_signature(): void
    {
        $t = $this->tenant();

        $this->withHeaders(['X-Webhook-Signature' => 'sha256=wrong'])
            ->getJson("/webhooks/whmcs/{$t->slug}/invoice-map/793")
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_signature']);
    }
}
