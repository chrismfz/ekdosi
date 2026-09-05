<?php

namespace Tests\Feature\Payments;

use App\Services\Payments\Gateways\ManualPaymentGateway;
use App\Services\Payments\Gateways\NullPaymentGateway;
use App\Services\Payments\PaymentGatewayRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The modular gateway seam (B0): a key resolves to its adapter, an unknown key
 * fails closed to the Null gateway, and the manual gateway advertises the
 * offline capabilities the portal flow (B0b) will read instead of branching on key.
 */
class PaymentGatewayRegistryTest extends TestCase
{
    private function registry(): PaymentGatewayRegistry
    {
        return app(PaymentGatewayRegistry::class);
    }

    public function test_manual_key_resolves_to_the_manual_gateway(): void
    {
        $gw = $this->registry()->for('manual');
        $this->assertInstanceOf(ManualPaymentGateway::class, $gw);
        $this->assertSame('manual', $gw->key());
    }

    #[DataProvider('unknownKeys')]
    public function test_unknown_or_empty_key_fails_closed_to_null(string $key): void
    {
        $gw = $this->registry()->for($key);
        $this->assertInstanceOf(NullPaymentGateway::class, $gw);
        $this->assertFalse($gw->capabilities()->chargeable());
    }

    /** @return list<array{string}> */
    public static function unknownKeys(): array
    {
        return [['none'], [''], ['   '], ['does-not-exist']];
    }

    public function test_configured_keys_list_the_manual_gateway(): void
    {
        $this->assertContains('manual', $this->registry()->keys());
    }

    public function test_label_renders_a_stale_key_without_resolving_or_logging(): void
    {
        $registry = $this->registry();
        // Known key → the gateway's display name.
        $this->assertSame((new ManualPaymentGateway)->displayName(), $registry->label('manual'));
        // Unknown/removed key → the raw key itself (no Null fallback, no per-row warning).
        $this->assertSame('stripe', $registry->label('stripe'));
        $this->assertSame('—', $registry->label(''));
    }

    public function test_manual_gateway_advertises_offline_capabilities(): void
    {
        $caps = $this->registry()->for('manual')->capabilities();
        $this->assertSame('offline', $caps->flow);
        $this->assertFalse($caps->webhook);   // operator-confirmed, no webhook
        $this->assertTrue($caps->prepaid);    // a recorded deposit can fund credit
        $this->assertTrue($caps->chargeable());
    }
}
