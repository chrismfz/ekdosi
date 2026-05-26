<?php

namespace Tests\Feature;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use App\Services\AadeRegistryLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery;
use SoapClient;
use SoapFault;
use Tests\TestCase;

/**
 * Locks in the AadeRegistryLookup contract:
 *  - parse() turns the SOAP response into a typed AadeRegistryRecord
 *  - missing credentials short-circuit before any network call
 *  - GSIS auth errors → AadeCredentialsInvalid
 *  - GSIS error_rec ("not found" / "deactivated") → AadeAfmNotFound
 *  - any other SoapFault / network error → AadeUnreachable
 *  - successful lookups are cached for 24h per (tenant, AFM)
 *
 * The SoapClient is mocked end-to-end — no actual SOAP calls happen
 * in tests. The mock receives the AFM, returns canned response
 * objects that match real RgWsPublic2 wire shape.
 */
class AadeRegistryLookupTest extends TestCase
{
    use RefreshDatabase;

    private Company $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // ext-soap is required at runtime (SoapClient / SoapFault / SoapVar).
        // The composer.json declares it as a "require" but if the test
        // sandbox happens to run without it loaded, skip cleanly rather
        // than mass-failing every case with "Class not found".
        if (! extension_loaded('soap')) {
            $this->markTestSkipped('ext-soap not loaded — install php-soap to run these tests.');
        }

        $this->tenant = Company::create([
            'name' => 'Test Co',
            'slug' => 'aade-test-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => '800561849',
            'gsis_username' => 'TESTUSER',
            'gsis_password' => 'TESTPASS',
            'mydata_production' => false,
        ]);

        Cache::flush();
    }

    public function test_missing_credentials_throws_credentials_invalid(): void
    {
        $tenant = Company::create([
            'name' => 'No creds',
            'slug' => 'no-creds-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'mydata_production' => false,
            // No gsis_username / gsis_password set
        ]);

        $svc = new AadeRegistryLookup($tenant, $this->mockSoap(returnValue: null));

        $this->expectException(AadeCredentialsInvalid::class);
        $svc->findByAfm('800561849');
    }

    public function test_successful_lookup_parses_into_record(): void
    {
        $svc = new AadeRegistryLookup(
            $this->tenant,
            $this->mockSoap(returnValue: $this->canonicalResponse()),
        );

        $result = $svc->findByAfm('800561849');

        $this->assertInstanceOf(AadeRegistryRecord::class, $result);
        $this->assertSame('800561849', $result->afm);
        $this->assertSame('MYIP NET WORKS ΥΠΗΡΕΣΙΕΣ ΔΙΑΔΙΚΤΥΟΥ Ο Ε', $result->name);
        $this->assertSame('ΞΑΝΘΗΣ', $result->doy);
        $this->assertSame('5411', $result->doyCode);
        $this->assertTrue($result->active);
        $this->assertSame('ΚΑΝΑΡΗ 5', $result->address);
        $this->assertSame('ΞΑΝΘΗ', $result->city);
        $this->assertSame('67100', $result->postcode);
        $this->assertCount(2, $result->activities);
        $this->assertSame('KYRIA', $result->activities[0]['kind']);

        $primary = $result->primaryActivity();
        $this->assertNotNull($primary);
        $this->assertSame('60200000', $primary['code']);
    }

    public function test_deactivated_afm_throws_not_found(): void
    {
        $response = $this->canonicalResponse();
        $response->result->error_rec = (object) [
            'error_code' => 'RG_WS_PUBLIC_AFM_NOT_FOUND',
            'error_descr' => 'Ο ΑΦΜ δεν υπάρχει',
        ];

        $svc = new AadeRegistryLookup($this->tenant, $this->mockSoap(returnValue: $response));

        $this->expectException(AadeAfmNotFound::class);
        $svc->findByAfm('999999999');
    }

    public function test_auth_fault_throws_credentials_invalid(): void
    {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__setSoapHeaders')->andReturnTrue();
        $client->shouldReceive('rgWsPublic2AfmMethod')
            ->andThrow(new SoapFault('Client', 'RG_WS_PUBLIC_AUTHENTICATION_FAILED: bad creds'));

        $svc = new AadeRegistryLookup($this->tenant, $client);

        $this->expectException(AadeCredentialsInvalid::class);
        $svc->findByAfm('800561849');
    }

    public function test_other_fault_throws_unreachable(): void
    {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__setSoapHeaders')->andReturnTrue();
        $client->shouldReceive('rgWsPublic2AfmMethod')
            ->andThrow(new SoapFault('Server', 'Connection reset by peer'));

        $svc = new AadeRegistryLookup($this->tenant, $client);

        $this->expectException(AadeUnreachable::class);
        $svc->findByAfm('800561849');
    }

    public function test_successful_lookup_is_cached(): void
    {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__setSoapHeaders')->andReturnTrue();
        // Expectation: SOAP method called EXACTLY ONCE despite two findByAfm calls.
        $client->shouldReceive('rgWsPublic2AfmMethod')
            ->once()
            ->andReturn($this->canonicalResponse());

        $svc = new AadeRegistryLookup($this->tenant, $client);

        $first = $svc->findByAfm('800561849');
        $second = $svc->findByAfm('800561849');

        $this->assertSame($first->name, $second->name);
        $this->assertSame($first->afm, $second->afm);
    }

    public function test_cache_is_scoped_per_tenant(): void
    {
        $otherTenant = Company::create([
            'name' => 'Other',
            'slug' => 'other-'.uniqid(),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'gsis_username' => 'OTHER',
            'gsis_password' => 'OTHER',
            'mydata_production' => false,
        ]);

        // Tenant A fetches first
        (new AadeRegistryLookup($this->tenant, $this->mockSoap(returnValue: $this->canonicalResponse())))
            ->findByAfm('800561849');

        // Tenant B mock will be called fresh — not served from A's cache
        $mockB = Mockery::mock(SoapClient::class);
        $mockB->shouldReceive('__setSoapHeaders')->andReturnTrue();
        $mockB->shouldReceive('rgWsPublic2AfmMethod')
            ->once()
            ->andReturn($this->canonicalResponse());

        (new AadeRegistryLookup($otherTenant, $mockB))->findByAfm('800561849');
    }

    /**
     * Canonical RgWsPublic2 response shape, modelled after the real
     * payload returned for AFM 800561849 (the operator's company,
     * MyIP net-Works). Used as the fixture across all tests.
     */
    private function canonicalResponse(): object
    {
        return (object) [
            'result' => (object) [
                'rg_ws_public2_result_rtType' => (object) [
                    'basic_rec' => (object) [
                        'afm' => '800561849',
                        'onomasia' => 'MYIP NET WORKS ΥΠΗΡΕΣΙΕΣ ΔΙΑΔΙΚΤΥΟΥ Ο Ε',
                        'doy' => '5411',
                        'doy_descr' => 'ΞΑΝΘΗΣ',
                        'deactivation_flag' => '2',
                        'deactivation_flag_descr' => 'ΕΝΕΡΓΟΣ ΑΦΜ',
                        'postal_address' => 'ΚΑΝΑΡΗ',
                        'postal_address_no' => '5',
                        'postal_area_description' => 'ΞΑΝΘΗ',
                        'postal_zip_code' => '67100',
                    ],
                    'firm_act_tab' => (object) [
                        'item' => [
                            (object) [
                                'firm_act_code' => '60200000',
                                'firm_act_descr' => 'ΥΠΗΡΕΣΙΕΣ ΤΗΛΕΟΠΤΙΚΟΥ ΠΡΟΓΡΑΜΜΑΤΟΣ',
                                'firm_act_kind_descr' => 'KYRIA',
                            ],
                            (object) [
                                'firm_act_code' => '63101200',
                                'firm_act_descr' => 'ΥΠΗΡΕΣΙΕΣ ΙΣΤΟΦΙΛΟΞΕΝΙΑΣ',
                                'firm_act_kind_descr' => 'DEYTEREVOUSA',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function mockSoap(mixed $returnValue): SoapClient
    {
        $client = Mockery::mock(SoapClient::class);
        $client->shouldReceive('__setSoapHeaders')->andReturnTrue();
        if ($returnValue !== null) {
            $client->shouldReceive('rgWsPublic2AfmMethod')->andReturn($returnValue);
        } else {
            $client->shouldReceive('rgWsPublic2AfmMethod')->never();
        }
        return $client;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
