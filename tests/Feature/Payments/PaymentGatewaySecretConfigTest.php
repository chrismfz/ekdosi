<?php

namespace Tests\Feature\Payments;

use App\Filament\Resources\PaymentGatewayConnections\Pages\EditPaymentGatewayConnection;
use App\Models\Company;
use App\Models\PaymentGatewayConnection;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Write-only secrets on the «Τρόποι online πληρωμής» edit form (B1): a stored
 * shared secret is NEVER loaded back into the browser, and leaving the field
 * blank on save keeps the existing value (editing the label/endpoint can't wipe
 * the secret). A typed value overwrites. Exercised through the page's mutate hooks
 * directly — the exact security property, without the full panel.
 */
class PaymentGatewaySecretConfigTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): PaymentGatewayConnection
    {
        $t = Company::create(['name' => 'T', 'slug' => 'sec-'.uniqid(), 'country_code' => 'GR']);

        return PaymentGatewayConnection::create([
            'company_id' => $t->id, 'gateway' => 'eurobank', 'label' => 'Κάρτα',
            'is_active' => true, 'sort' => 0,
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => 'STORED-SECRET', 'testmode' => true],
        ]);
    }

    private function page(PaymentGatewayConnection $conn): EditPaymentGatewayConnection
    {
        $page = new EditPaymentGatewayConnection;
        $page->record = $conn;

        return $page;
    }

    /** @param 'mutateFormDataBeforeFill'|'mutateFormDataBeforeSave' $method */
    private function invoke(EditPaymentGatewayConnection $page, string $method, array $data): array
    {
        return Closure::bind(fn (array $d): array => $this->{$method}($d), $page, EditPaymentGatewayConnection::class)($data);
    }

    public function test_fill_never_exposes_the_stored_secret(): void
    {
        $page = $this->page($this->connection());
        $filled = $this->invoke($page, 'mutateFormDataBeforeFill', [
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => 'STORED-SECRET', 'testmode' => true],
        ]);

        $this->assertNull($filled['config']['shared_secret']);
        $this->assertSame('MID123', $filled['config']['merchant_id']);   // non-secret fields stay
    }

    public function test_blank_secret_on_save_keeps_the_stored_value(): void
    {
        $page = $this->page($this->connection());
        $saved = $this->invoke($page, 'mutateFormDataBeforeSave', [
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => null, 'testmode' => false],
        ]);

        $this->assertSame('STORED-SECRET', $saved['config']['shared_secret']);
        $this->assertFalse($saved['config']['testmode']);   // the real edit still applies
    }

    public function test_a_typed_secret_overwrites(): void
    {
        $page = $this->page($this->connection());
        $saved = $this->invoke($page, 'mutateFormDataBeforeSave', [
            'config' => ['merchant_id' => 'MID123', 'shared_secret' => 'NEW-SECRET', 'testmode' => true],
        ]);

        $this->assertSame('NEW-SECRET', $saved['config']['shared_secret']);
    }
}
