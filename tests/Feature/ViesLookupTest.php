<?php

namespace Tests\Feature;

use App\Exceptions\Vies\ViesInvalidFormat;
use App\Exceptions\Vies\ViesUnavailable;
use App\Services\ViesLookup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ViesLookupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function svc(): ViesLookup
    {
        return app(ViesLookup::class);
    }

    public function test_valid_at_number_with_identity(): void
    {
        Http::fake(['*' => Http::response([
            'countryCode' => 'AT', 'vatNumber' => 'U18522105', 'valid' => true,
            'name' => 'KSR Group GmbH', 'address' => "Im Wirtschaftspark 15\nAT-3494 Gedersdorf",
            'requestDate' => '2026-05-31T10:00:00.000Z',
        ], 200)]);

        $r = $this->svc()->check('ATU18522105');

        $this->assertTrue($r->valid);
        $this->assertSame('AT', $r->countryCode);
        $this->assertSame('U18522105', $r->vatNumber);
        $this->assertSame('ATU18522105', $r->fullVatId());
        $this->assertTrue($r->hasIdentity());
        $this->assertSame('KSR Group GmbH', $r->name);

        // VIES wants the number WITHOUT the country prefix.
        Http::assertSent(fn ($req) => $req['countryCode'] === 'AT' && $req['vatNumber'] === 'U18522105');
    }

    public function test_valid_but_identity_withheld_de(): void
    {
        Http::fake(['*' => Http::response([
            'countryCode' => 'DE', 'vatNumber' => '129273398', 'valid' => true,
            'name' => '---', 'address' => '---',
        ], 200)]);

        $r = $this->svc()->check('DE129273398');

        $this->assertTrue($r->valid);
        $this->assertFalse($r->hasIdentity());   // '---' → no identity
    }

    public function test_invalid_number(): void
    {
        Http::fake(['*' => Http::response([
            'countryCode' => 'AT', 'vatNumber' => '00000000', 'valid' => false,
            'name' => '---', 'address' => '---',
        ], 200)]);

        $r = $this->svc()->check('AT00000000');
        $this->assertFalse($r->valid);
    }

    public function test_uses_country_hint_when_no_prefix(): void
    {
        Http::fake(['*' => Http::response(['countryCode' => 'IT', 'vatNumber' => '12345678901', 'valid' => true, 'name' => 'X', 'address' => ''], 200)]);

        $this->svc()->check('12345678901', 'IT');

        Http::assertSent(fn ($req) => $req['countryCode'] === 'IT' && $req['vatNumber'] === '12345678901');
    }

    public function test_gr_hint_normalised_to_el(): void
    {
        Http::fake(['*' => Http::response(['countryCode' => 'EL', 'vatNumber' => '999999999', 'valid' => true, 'name' => 'X', 'address' => ''], 200)]);

        $this->svc()->check('999999999', 'GR');

        Http::assertSent(fn ($req) => $req['countryCode'] === 'EL');
    }

    public function test_service_unavailable_body_throws_unavailable(): void
    {
        // The EU service signals soft failures in the body even on HTTP 200.
        Http::fake(['*' => Http::response(['errorWrappers' => [['error' => 'SERVICE_UNAVAILABLE']]], 200)]);

        $this->expectException(ViesUnavailable::class);
        $this->svc()->check('ATU18522105');
    }

    public function test_http_500_throws_unavailable(): void
    {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->expectException(ViesUnavailable::class);
        $this->svc()->check('ATU18522105');
    }

    public function test_no_country_no_prefix_throws_invalid_format(): void
    {
        Http::fake();
        $this->expectException(ViesInvalidFormat::class);
        $this->svc()->check('12345678901');   // no prefix, no hint
        Http::assertNothingSent();
    }

    public function test_non_eu_country_throws_invalid_format(): void
    {
        Http::fake();
        $this->expectException(ViesInvalidFormat::class);
        $this->svc()->check('123456', 'US');
    }

    public function test_result_is_cached_second_call_no_http(): void
    {
        Http::fake(['*' => Http::response(['countryCode' => 'AT', 'vatNumber' => 'U18522105', 'valid' => true, 'name' => 'X', 'address' => ''], 200)]);

        $this->svc()->check('ATU18522105');
        $this->svc()->check('ATU18522105');

        Http::assertSentCount(1);   // second call served from cache
    }
}
