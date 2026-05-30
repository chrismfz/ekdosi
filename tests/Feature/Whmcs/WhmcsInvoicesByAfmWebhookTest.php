<?php

namespace Tests\Feature\Whmcs;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP contract of POST /webhooks/whmcs/{slug}/invoices-by-afm — the AFM-keyed
 * per-client card the plugin renders on a client's admin profile. Matches on
 * customers.afm (the only surviving link after the legacy import), takes an ΑΦΜ
 * SET (client own + third-party routed contacts), returns each ΑΦΜ's live
 * ekdosi invoices with ΤΠΥ + ΜΑΡΚ + state.
 */
class WhmcsInvoicesByAfmWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'afm-secret-do-not-use-in-prod-xxxxx';

    private function tenant(?string $slug = null): Company
    {
        return Company::create([
            'name' => 'AfmTenant',
            'slug' => $slug ?? 'afm-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_mode' => 'off',
            'whmcs_webhook_secret' => self::SECRET,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function call_afm(string $slug, array $body, string $secret = self::SECRET): \Illuminate\Testing\TestResponse
    {
        $raw = json_encode($body);
        $sig = 'sha256='.hash_hmac('sha256', $raw, $secret);

        return $this->call(
            'POST',
            "/webhooks/whmcs/{$slug}/invoices-by-afm",
            [], [], [],
            ['HTTP_X_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'],
            $raw,
        );
    }

    private function customer(Company $t, string $afm, string $name): Customer
    {
        return Customer::create([
            'company_id' => $t->id,
            'afm' => $afm,
            'name' => $name,
        ]);
    }

    private function invoice(Company $t, InvoiceType $it, Customer $c, string $invcode, int $code, string $local, ?string $state, ?string $mark): Invoice
    {
        $inv = Invoice::create([
            'company_id' => $t->id,
            'invoice_type_id' => $it->id,
            'customer_id' => $c->id,
            'invcode' => $invcode,
            'code' => $code,
            'issued_at' => now(),
        ]);
        $inv->forceFill([
            'local_status' => $local,
            'mydata_state' => $state,
            'mydata_mark' => $mark,
        ])->save();

        return $inv;
    }

    public function test_returns_invoices_grouped_by_afm_including_third_party_and_unknown(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        // The client's own ΑΦΜ — one filed, one draft.
        $own = $this->customer($t, '998482379', 'Πελάτης ΑΕ');
        $this->invoice($t, $it, $own, 'ΤΠΥ130', 130, 'active', 'VALID', '400013690089505');
        $this->invoice($t, $it, $own, 'ΤΠΥ131', 131, 'draft', null, null);

        // A third-party ΑΦΜ this client routes a service to.
        $third = $this->customer($t, '123456789', 'Τρίτος ΕΠΕ');
        $this->invoice($t, $it, $third, 'ΤΠΥ200', 200, 'active', 'VALID', '400099999999999');

        $resp = $this->call_afm($t->slug, ['afms' => ['EL 998482379', '123456789', '000000000']]);

        $resp->assertOk()
            ->assertJsonPath('found', true)
            // own ΑΦΜ (note: 'EL ' prefix + space stripped by normalisation)
            ->assertJsonPath('afms.998482379.customer_name', 'Πελάτης ΑΕ')
            ->assertJsonPath('afms.998482379.invoices.0.ekdosi_invcode', 'ΤΠΥ131') // newest first
            ->assertJsonPath('afms.998482379.invoices.1.ekdosi_invcode', 'ΤΠΥ130')
            ->assertJsonPath('afms.998482379.invoices.1.mydata_mark', '400013690089505')
            // third-party ΑΦΜ resolves to its own customer
            ->assertJsonPath('afms.123456789.invoices.0.ekdosi_invcode', 'ΤΠΥ200')
            // unknown ΑΦΜ → null (honest "no match")
            ->assertJsonPath('afms.000000000', null);

        // own ΑΦΜ has exactly its 2 invoices
        $this->assertCount(2, $resp->json('afms.998482379.invoices'));
    }

    public function test_matches_customers_whose_stored_afm_is_not_digit_clean(): void
    {
        // customers.afm is imported verbatim from legacy Firebird and can carry
        // an EL/GR prefix, spaces or dashes. The inbound ΑΦΜ is digits-only.
        // Both sides must normalise so these still match (the silent-miss bug).
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        $prefixed = $this->customer($t, 'EL 998482379', 'Με EL prefix');
        $this->invoice($t, $it, $prefixed, 'ΤΠΥ300', 300, 'active', 'VALID', '400000000000300');

        $dashed = $this->customer($t, '12-345-6789', 'Με παύλες');
        $this->invoice($t, $it, $dashed, 'ΤΠΥ301', 301, 'active', 'VALID', '400000000000301');

        $resp = $this->call_afm($t->slug, ['afms' => ['998482379', '123456789']]);

        $resp->assertOk()
            ->assertJsonPath('afms.998482379.invoices.0.ekdosi_invcode', 'ΤΠΥ300')
            ->assertJsonPath('afms.123456789.invoices.0.ekdosi_invcode', 'ΤΠΥ301');
    }

    public function test_like_prefilter_does_not_overmatch_a_longer_afm(): void
    {
        // The LIKE '%afm%' prefilter can over-match (a longer stored ΑΦΜ that
        // contains the wanted digits as a substring); the PHP re-key must drop
        // those so only an EXACT normalised ΑΦΜ resolves.
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);

        // Stored ΑΦΜ 1234567890 contains '234567890' — must NOT match a query
        // for '234567890'.
        $longer = $this->customer($t, '1234567890', 'Πιο μακρύ');
        $this->invoice($t, $it, $longer, 'ΤΠΥ400', 400, 'active', 'VALID', '400000000000400');

        $resp = $this->call_afm($t->slug, ['afms' => ['234567890']]);

        $resp->assertOk()->assertJsonPath('afms.234567890', null);
    }

    public function test_excludes_cancelled_invoices(): void
    {
        $t = $this->tenant();
        $it = InvoiceType::create(['company_id' => $t->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $c = $this->customer($t, '998482379', 'Πελάτης ΑΕ');

        $this->invoice($t, $it, $c, 'ΤΠΥ140', 140, 'active', 'VALID', '400013690089505');
        $this->invoice($t, $it, $c, 'ΤΠΥ141', 141, 'cancelled', null, null);            // locally cancelled
        $this->invoice($t, $it, $c, 'ΤΠΥ142', 142, 'active', 'CANCELLED', '400000000000142'); // AADE-cancelled

        $resp = $this->call_afm($t->slug, ['afms' => ['998482379']]);

        $resp->assertOk();
        $invcodes = array_column($resp->json('afms.998482379.invoices'), 'ekdosi_invcode');
        $this->assertSame(['ΤΠΥ140'], $invcodes);
    }

    public function test_is_tenant_scoped(): void
    {
        $a = $this->tenant('afm-a-'.uniqid());
        $b = $this->tenant('afm-b-'.uniqid());
        $itB = InvoiceType::create(['company_id' => $b->id, 'name' => 'ΤΠΥ', 'code' => 'ΤΠΥ', 'invcount' => 1]);
        $custB = $this->customer($b, '998482379', 'Άλλος tenant');
        $this->invoice($b, $itB, $custB, 'ΤΠΥ500', 500, 'active', 'VALID', '400000000000500');

        // Same ΑΦΜ queried against tenant A → no customer there → null.
        $resp = $this->call_afm($a->slug, ['afms' => ['998482379']]);
        $resp->assertOk()->assertJsonPath('afms.998482379', null);
    }

    public function test_rejects_a_bad_signature(): void
    {
        $t = $this->tenant();
        $this->call_afm($t->slug, ['afms' => ['998482379']], 'wrong-secret')
            ->assertUnauthorized()
            ->assertJsonPath('error', 'invalid_signature');
    }

    public function test_rejects_an_empty_afm_set(): void
    {
        $t = $this->tenant();
        $this->call_afm($t->slug, ['afms' => []])
            ->assertStatus(400)
            ->assertJsonPath('error', 'missing_or_invalid_afms');
    }

    public function test_unknown_tenant_is_404(): void
    {
        $this->call_afm('does-not-exist-'.uniqid(), ['afms' => ['998482379']])
            ->assertNotFound()
            ->assertJsonPath('error', 'tenant_not_found');
    }
}
