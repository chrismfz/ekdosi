<?php

namespace Tests\Unit;

use App\Contracts\DomainRegistrar;
use App\Models\Domain;
use App\Services\Domains\DomainRegistrarNotConfigured;
use App\Services\Domains\DomainRegistrarRegistry;
use App\Services\Domains\NullDomainRegistrar;
use App\Support\Domains\AvailabilityResult;
use App\Support\Domains\DomainRegistrarCapabilities;
use App\Support\Domains\DomainRegistrarCredentials;
use App\Support\Domains\DomainSyncResult;
use App\Support\Domains\RegistrarContact;
use App\Support\Domains\RegistrarDomainPage;
use App\Support\Domains\TldPricing;
use Tests\TestCase;

/**
 * A0 seam: the registry resolves a registrar key → adapter, config-driven, and
 * falls back to the LOUD Null ('manual') adapter for empty/'manual'/unknown keys
 * — an API operation on it throws instead of faking a registrar success. Pure
 * logic — no DB. Mirrors ProviderTransportRegistryTest.
 */
class DomainRegistrarRegistryTest extends TestCase
{
    private function registry(array $registrars = []): DomainRegistrarRegistry
    {
        config()->set('ekdosi.domains.registrars', $registrars);

        return new DomainRegistrarRegistry;
    }

    public function test_empty_or_manual_key_returns_null_adapter(): void
    {
        $reg = $this->registry();
        $this->assertInstanceOf(NullDomainRegistrar::class, $reg->for(''));
        $this->assertInstanceOf(NullDomainRegistrar::class, $reg->for('manual'));
        $this->assertInstanceOf(NullDomainRegistrar::class, $reg->for('  '));
    }

    public function test_unknown_key_falls_back_to_null_adapter(): void
    {
        $reg = $this->registry(['fake' => FakeDomainRegistrar::class]);

        $this->assertInstanceOf(NullDomainRegistrar::class, $reg->for('does-not-exist'));
    }

    public function test_configured_key_resolves_and_caches(): void
    {
        $reg = $this->registry(['fake' => FakeDomainRegistrar::class]);

        $adapter = $reg->for('fake');
        $this->assertInstanceOf(FakeDomainRegistrar::class, $adapter);
        $this->assertSame('fake', $adapter->key());
        // cached → same instance
        $this->assertSame($adapter, $reg->for('fake'));
    }

    public function test_keys_lists_configured_registrars_only(): void
    {
        $reg = $this->registry(['fake' => FakeDomainRegistrar::class]);
        $this->assertSame(['fake'], $reg->keys());
    }

    public function test_a_class_not_implementing_the_contract_is_rejected(): void
    {
        $reg = $this->registry(['bad' => \stdClass::class]);
        $this->assertInstanceOf(NullDomainRegistrar::class, $reg->for('bad'));
    }

    public function test_null_adapter_pings_false_and_throws_on_availability(): void
    {
        $null = new NullDomainRegistrar;
        $creds = new DomainRegistrarCredentials;

        $this->assertSame('manual', $null->key());
        $this->assertFalse($null->ping($creds));
        $this->assertFalse($null->capabilities()->supportsTransfer);
        $this->expectException(DomainRegistrarNotConfigured::class);
        $null->checkAvailability('example.gr', $creds);
    }

    public function test_label_falls_back_to_the_raw_key(): void
    {
        config()->set('ekdosi.domains.registrar_labels', ['manual' => 'Manual (χωρίς API)']);
        $reg = $this->registry();

        $this->assertSame('Manual (χωρίς API)', $reg->label('manual'));
        $this->assertSame('gone-registrar', $reg->label('gone-registrar'));
    }

    public function test_select_options_include_wired_but_unlabeled_registrars(): void
    {
        // «One config line + one class» must be enough to make an adapter
        // selectable — a label is polish, not a second mandatory line.
        config()->set('ekdosi.domains.registrar_labels', ['manual' => 'Manual (χωρίς API)']);
        $reg = $this->registry(['fake' => FakeDomainRegistrar::class]);

        $options = $reg->selectOptions();
        $this->assertSame('Manual (χωρίς API)', $options['manual']);
        $this->assertSame('fake', $options['fake'], 'wired-but-unlabeled key renders as itself');
    }
}

/** Minimal test double registered via config. */
class FakeDomainRegistrar implements DomainRegistrar
{
    public function key(): string
    {
        return 'fake';
    }

    public function capabilities(): DomainRegistrarCapabilities
    {
        return new DomainRegistrarCapabilities(supportsTransfer: true);
    }

    public function ping(DomainRegistrarCredentials $credentials): bool
    {
        return true;
    }

    public function checkAvailability(string $fqdn, DomainRegistrarCredentials $credentials): AvailabilityResult
    {
        return new AvailabilityResult(fqdn: $fqdn, available: true);
    }

    public function syncDomain(Domain $domain, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return new DomainSyncResult(expiresAt: '2027-01-01');
    }

    public function getTldPricing(string $tld, DomainRegistrarCredentials $credentials): TldPricing
    {
        return new TldPricing(tld: $tld, costs: ['renewal' => ['cost' => 10.0, 'currency' => 'EUR']]);
    }

    public function listDomains(DomainRegistrarCredentials $credentials, int $offset, int $limit): RegistrarDomainPage
    {
        return new RegistrarDomainPage(records: [], total: 0);
    }

    public function getContact(string $handle, DomainRegistrarCredentials $credentials): ?RegistrarContact
    {
        return null;
    }

    public function renew(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return new DomainSyncResult(expiresAt: '2028-01-01');
    }

    public function register(Domain $domain, int $years, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return new DomainSyncResult(expiresAt: '2028-01-01', registrarDomainId: '1');
    }

    public function transferIn(Domain $domain, string $authCode, DomainRegistrarCredentials $credentials): DomainSyncResult
    {
        return new DomainSyncResult(registrarDomainId: '1', rawStatus: 'REQ');
    }

    public function getEppCode(Domain $domain, DomainRegistrarCredentials $credentials): ?string
    {
        return 'fake-code';
    }
}
