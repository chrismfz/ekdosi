<?php

namespace Tests\Unit;

use App\Support\EInvoice\ProviderEndpointGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * PROV-017: the provider base-URL guard accepts only a plain PUBLIC HTTPS
 * endpoint. Hermetic — every case uses a literal IP so the guard's private-range
 * check runs WITHOUT any DNS lookup (a hostname case would resolve via the network
 * and be flaky on NXDOMAIN-hijacking resolvers).
 */
class ProviderEndpointGuardTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function blockedUrls(): array
    {
        return [
            'http scheme' => ['http://1.1.1.1/api'],
            'ftp scheme' => ['ftp://1.1.1.1'],
            'no scheme' => ['1.1.1.1/api'],
            'userinfo' => ['https://user:pass@1.1.1.1'],
            'query string' => ['https://1.1.1.1/api?token=x'],
            'fragment' => ['https://1.1.1.1/api#frag'],
            'non-443 port' => ['https://1.1.1.1:8443/api'],
            'loopback ipv4' => ['https://127.0.0.1/api'],
            'private 10/8' => ['https://10.0.0.1'],
            'private 192.168' => ['https://192.168.1.10/api'],
            'link-local (metadata)' => ['https://169.254.169.254/latest/meta-data'],
            'cgnat 100.64/10' => ['https://100.64.0.1/api'],
            'loopback ipv6' => ['https://[::1]/api'],
        ];
    }

    #[DataProvider('blockedUrls')]
    public function test_unsafe_urls_are_rejected(string $url): void
    {
        $this->expectException(RuntimeException::class);
        ProviderEndpointGuard::assertSafeBaseUrl($url);
    }

    /** @return list<array{0:string}> */
    public static function allowedUrls(): array
    {
        return [
            'public literal ip + path' => ['https://1.1.1.1/iNVOSign_Api.php'],
            'public literal ip bare' => ['https://8.8.8.8'],
            'public literal ip explicit 443' => ['https://1.1.1.1:443'],
        ];
    }

    #[DataProvider('allowedUrls')]
    public function test_public_https_urls_pass(string $url): void
    {
        ProviderEndpointGuard::assertSafeBaseUrl($url);
        $this->addToAssertionCount(1); // no throw
    }
}
