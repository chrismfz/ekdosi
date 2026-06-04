<?php

namespace Tests\Unit;

use App\Contracts\EInvoiceProviderTransport;
use App\Models\Invoice;
use App\Services\EInvoice\ProviderTransportRegistry;
use App\Services\EInvoice\Transports\NullProviderTransport;
use App\Support\EInvoice\ProviderCredentials;
use App\Support\EInvoice\ProviderResult;
use RuntimeException;
use Tests\TestCase;

/**
 * P1 seam: the registry resolves a provider key → transport, config-driven, and
 * falls back to a LOUD Null transport for unknown/empty keys (never silently
 * not-filing). Pure logic — no DB.
 */
class ProviderTransportRegistryTest extends TestCase
{
    private function registry(array $providers = []): ProviderTransportRegistry
    {
        config()->set('ekdosi.einvoice.providers', $providers);

        return new ProviderTransportRegistry;
    }

    public function test_empty_or_none_key_returns_null_transport(): void
    {
        $reg = $this->registry();
        $this->assertInstanceOf(NullProviderTransport::class, $reg->for(''));
        $this->assertInstanceOf(NullProviderTransport::class, $reg->for('none'));
        $this->assertInstanceOf(NullProviderTransport::class, $reg->for('  '));
    }

    public function test_unknown_key_falls_back_to_null_transport(): void
    {
        $reg = $this->registry(['invosign' => FakeProviderTransport::class]);

        $this->assertInstanceOf(NullProviderTransport::class, $reg->for('does-not-exist'));
    }

    public function test_configured_key_resolves_and_caches(): void
    {
        $reg = $this->registry(['fake' => FakeProviderTransport::class]);

        $t = $reg->for('fake');
        $this->assertInstanceOf(FakeProviderTransport::class, $t);
        $this->assertSame('fake', $t->key());
        // cached → same instance
        $this->assertSame($t, $reg->for('fake'));
    }

    public function test_keys_lists_configured_providers_only(): void
    {
        $reg = $this->registry(['fake' => FakeProviderTransport::class]);
        $this->assertSame(['fake'], $reg->keys());
    }

    public function test_a_class_not_implementing_the_contract_is_rejected(): void
    {
        $reg = $this->registry(['bad' => \stdClass::class]);
        $this->assertInstanceOf(NullProviderTransport::class, $reg->for('bad'));
    }

    public function test_null_transport_pings_false_and_throws_on_send(): void
    {
        $null = new NullProviderTransport;
        $creds = new ProviderCredentials;

        $this->assertFalse($null->ping($creds));
        $this->expectException(RuntimeException::class);
        $null->send('<xml/>', $creds);
    }
}

/** Minimal test double registered via config. */
class FakeProviderTransport implements EInvoiceProviderTransport
{
    public function key(): string
    {
        return 'fake';
    }

    public function send(string $documentXml, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::ok(mark: '400000000000001', authenticationCode: 'ABC', qrUrl: 'https://x/y');
    }

    public function cancel(string $mark, ProviderCredentials $credentials, string $reason = ''): ProviderResult
    {
        return ProviderResult::ok(cancellationMark: '400000000000002');
    }

    public function status(Invoice $invoice, ProviderCredentials $credentials): ProviderResult
    {
        return ProviderResult::ok(mark: '400000000000001');
    }

    public function ping(ProviderCredentials $credentials): bool
    {
        return true;
    }
}
