<?php

namespace Tests\Feature\Payments;

use App\Models\Company;
use App\Models\PaymentGatewayConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The per-tenant payment-method row (B0). `config` (creds + settings) is
 * ENCRYPTED at rest, `is_active` is the enable toggle, and the row is
 * company-scoped like every other tenant model.
 */
class PaymentGatewayConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function company(): Company
    {
        return Company::create(['name' => 'T', 'slug' => 'pg-'.uniqid(), 'country_code' => 'GR']);
    }

    public function test_config_is_encrypted_at_rest_but_reads_back_as_an_array(): void
    {
        $t = $this->company();
        $conn = PaymentGatewayConnection::create([
            'company_id' => $t->id,
            'gateway' => 'manual',
            'label' => 'Κατάθεση',
            'is_active' => true,
            'sort' => 0,
            'config' => ['bank_details' => 'GR-SECRET-IBAN-0001', 'instructions' => 'ref = invoice'],
        ]);

        // Model reads the decrypted array.
        $this->assertSame('GR-SECRET-IBAN-0001', $conn->fresh()->config['bank_details']);
        $this->assertTrue($conn->fresh()->is_active);

        // The raw column is ciphertext — the IBAN is not stored in the clear.
        $raw = (string) DB::table('payment_gateway_connections')->where('id', $conn->id)->value('config');
        $this->assertStringNotContainsString('GR-SECRET-IBAN-0001', $raw);
    }

    public function test_rows_are_company_scoped(): void
    {
        $a = $this->company();
        $b = $this->company();
        PaymentGatewayConnection::create(['company_id' => $a->id, 'gateway' => 'manual', 'is_active' => true, 'sort' => 0]);
        PaymentGatewayConnection::create(['company_id' => $b->id, 'gateway' => 'manual', 'is_active' => true, 'sort' => 0]);

        $this->assertSame(1, PaymentGatewayConnection::where('company_id', $a->id)->count());
    }
}
