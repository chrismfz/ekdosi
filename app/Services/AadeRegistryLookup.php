<?php

namespace App\Services;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeRegistryException;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;
use SoapHeader;
use SoapVar;

/**
 * Wraps AADE's RgWsPublic2 SOAP service for VAT-number → company
 * lookup. Each instance is bound to a specific Company tenant; the
 * tenant's stored gsis_username + gsis_password (decrypted by
 * Eloquent's encrypted cast) are the WS-Security UsernameToken.
 *
 * Endpoint:
 *   https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2?WSDL
 *
 * Implementation closely follows the operator's working example —
 * SOAP 1.2 RPC + encoded use, AddWSSUsernameToken header, single
 * rgWsPublic2AfmMethod call. Adapted for per-tenant credentials,
 * typed return, exception discrimination, and result caching.
 *
 * Caching: 24h TTL per (company_id, AFM). AADE registry data doesn't
 * change frequently and the GSIS service has rate limits we want to
 * respect. Tenants invalidate implicitly by waiting out the TTL; if
 * an operator needs a fresh lookup they can re-issue the call after
 * 24h. (Explicit invalidation is a future enhancement, not currently
 * needed.)
 *
 * The SoapClient instance is built lazily and can be overridden in
 * tests via the constructor for mocking.
 */
class AadeRegistryLookup
{
    private const WSDL = 'https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2?WSDL';

    private const WSS_NS = 'http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd';

    private const CACHE_TTL_SECONDS = 86400; // 24h

    public function __construct(
        private readonly Company $tenant,
        private ?SoapClient $client = null,
    ) {}

    /**
     * Look up an AFM in the AADE registry. Returns null only if caching
     * the AFM-not-found result was disabled (which we don't do); in
     * practice the not-found case throws AadeAfmNotFound.
     *
     * @throws AadeCredentialsInvalid GSIS rejected the username/password
     * @throws AadeAfmNotFound        AFM doesn't exist or is deactivated
     * @throws AadeUnreachable        SOAP/network/parsing failure
     */
    public function findByAfm(string $afm): AadeRegistryRecord
    {
        $afm = trim($afm);
        if ($afm === '') {
            throw new AadeAfmNotFound('Empty AFM');
        }

        $cacheKey = "aade.registry.{$this->tenant->getKey()}.{$afm}";

        $cached = Cache::get($cacheKey);
        if ($cached instanceof AadeRegistryRecord) {
            return $cached;
        }

        $this->assertCredentialsConfigured();

        $record = $this->callRegistry($afm);

        Cache::put($cacheKey, $record, self::CACHE_TTL_SECONDS);

        return $record;
    }

    private function assertCredentialsConfigured(): void
    {
        if (empty($this->tenant->gsis_username) || empty($this->tenant->gsis_password)) {
            throw new AadeCredentialsInvalid(
                'GSIS credentials are not configured for this tenant. '
                . 'Set gsis_username and gsis_password on the Company.'
            );
        }
    }

    /** @throws AadeRegistryException on any error */
    private function callRegistry(string $afm): AadeRegistryRecord
    {
        $client = $this->client ?? $this->buildSoapClient();

        $this->attachWssUsernameToken(
            $client,
            $this->tenant->gsis_username,
            $this->tenant->gsis_password,
        );

        try {
            $data = $client->rgWsPublic2AfmMethod([
                'INPUT_REC' => ['afm_called_for' => $afm],
            ]);
        } catch (SoapFault $sf) {
            // GSIS surfaces credential errors as SoapFaults with specific
            // codes; the message text varies by year so we match on the
            // faultcode prefix. RG_WS_PUBLIC_AUTHENTICATION_FAILED is the
            // documented credential-failure code; anything else is a
            // transport/parsing problem.
            $msg = $sf->getMessage();
            if (
                str_contains($msg, 'AUTHENTICATION')
                || str_contains($msg, 'AUTHORIZATION')
                || str_contains((string) $sf->faultcode, 'AUTH')
            ) {
                throw new AadeCredentialsInvalid('GSIS rejected the credentials.', 0, $sf);
            }

            Log::warning('AADE registry SOAP fault', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'faultcode' => $sf->faultcode ?? null,
                'message' => $msg,
            ]);

            throw new AadeUnreachable('AADE registry returned a SOAP fault.', 0, $sf);
        } catch (Exception $e) {
            Log::warning('AADE registry transport error', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            throw new AadeUnreachable('AADE registry unreachable.', 0, $e);
        }

        // AADE wraps "not found" / "deactivated" in errorRec rather than
        // throwing a fault. Detect and translate.
        if (
            isset($data->result->error_rec)
            && ! empty($data->result->error_rec->error_code)
        ) {
            $errorCode = (string) $data->result->error_rec->error_code;
            $errorDescr = (string) ($data->result->error_rec->error_descr ?? '');

            // RG_WS_PUBLIC_AFM_NOT_FOUND, RG_WS_PUBLIC_NO_INPUT_PARAMETERS,
            // RG_WS_PUBLIC_DEACTIVATED_AFM — all "this AFM isn't usable"
            // from the operator POV.
            Log::info('AADE registry returned error_rec', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'error_code' => $errorCode,
                'error_descr' => $errorDescr,
            ]);

            throw new AadeAfmNotFound("AADE: {$errorCode} — {$errorDescr}");
        }

        return $this->parse($data);
    }

    private function parse(object $data): AadeRegistryRecord
    {
        $basic = $data->result->rg_ws_public2_result_rtType->basic_rec ?? null;
        if ($basic === null) {
            throw new AadeUnreachable('AADE registry response missing basic_rec.');
        }

        $activities = [];
        $items = $data->result->rg_ws_public2_result_rtType->firm_act_tab->item ?? null;
        if ($items !== null) {
            // SoapClient may give a single object or an array of objects
            // depending on cardinality — normalise to an array.
            $items = is_array($items) ? $items : [$items];
            foreach ($items as $item) {
                $activities[] = [
                    'code' => (string) ($item->firm_act_code ?? ''),
                    'description' => (string) ($item->firm_act_descr ?? ''),
                    'kind' => (string) ($item->firm_act_kind_descr ?? ''),
                ];
            }
        }

        $address = trim(
            ((string) ($basic->postal_address ?? ''))
            . ' '
            . ((string) ($basic->postal_address_no ?? '')),
        );

        return new AadeRegistryRecord(
            afm: (string) ($basic->afm ?? ''),
            name: (string) ($basic->onomasia ?? ''),
            doy: (string) ($basic->doy_descr ?? ''),
            doyCode: (string) ($basic->doy ?? ''),
            // deactivation_flag: "1" = deactivated, "2" = active (per AADE docs).
            // Defensive: treat anything other than "1" as active.
            active: ((string) ($basic->deactivation_flag ?? '2')) !== '1',
            address: $address,
            city: (string) ($basic->postal_area_description ?? ''),
            postcode: (string) ($basic->postal_zip_code ?? ''),
            activities: $activities,
        );
    }

    /**
     * Lazy SoapClient builder. Overridable via constructor for tests.
     *
     * @throws AadeUnreachable if WSDL fetch fails on first call
     */
    private function buildSoapClient(): SoapClient
    {
        try {
            return new SoapClient(self::WSDL, [
                'encoding' => 'UTF-8',
                'exceptions' => true,
                'uri' => 'http://www.w3.org/2003/05/soap-envelope',
                'style' => SOAP_RPC,
                'use' => SOAP_ENCODED,
                'soap_version' => SOAP_1_2,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'connection_timeout' => 30,
                'trace' => true,
            ]);
        } catch (SoapFault $sf) {
            throw new AadeUnreachable('Failed to load AADE RgWsPublic2 WSDL.', 0, $sf);
        }
    }

    /**
     * Attach the WS-Security UsernameToken header. Copy of the working
     * helper from the legacy example.
     */
    private function attachWssUsernameToken(SoapClient $client, string $username, string $password): void
    {
        $usernameNode = new SoapVar($username, XSD_STRING, null, null, 'Username', self::WSS_NS);
        $passwordNode = new SoapVar($password, XSD_STRING, null, null, 'Password', self::WSS_NS);

        $usernameToken = new SoapVar(
            [$usernameNode, $passwordNode],
            SOAP_ENC_OBJECT,
            null, null, 'UsernameToken', self::WSS_NS,
        );

        $usernameToken = new SoapVar(
            [$usernameToken],
            SOAP_ENC_OBJECT,
            null, null, null, self::WSS_NS,
        );

        $client->__setSoapHeaders(
            new SoapHeader(self::WSS_NS, 'Security', $usernameToken),
        );
    }
}
