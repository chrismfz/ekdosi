<?php

namespace App\Services;

use App\DTOs\AadeRegistryRecord;
use App\Exceptions\Aade\AadeAfmNotFound;
use App\Exceptions\Aade\AadeCredentialsInvalid;
use App\Exceptions\Aade\AadeRegistryException;
use App\Exceptions\Aade\AadeUnreachable;
use App\Models\Company;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use SoapClient;
use SoapFault;
use SoapHeader;
use SoapVar;
use Throwable;

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

        // Verify credentials BEFORE the cache lookup so a tenant whose
        // credentials were removed/rotated doesn't continue serving
        // stale cache hits for up to 24h. Stronger invariant: "this
        // tenant cannot do GSIS lookups" applies consistently whether
        // or not we have a cached row.
        $this->assertCredentialsConfigured();

        $cacheKey = "aade.registry.{$this->tenant->getKey()}.{$afm}";

        // Defensive read: if the DTO shape changes across deploys the
        // file/redis cache can hold incompatible payloads. Treat any
        // failure as a miss and re-fetch from AADE.
        try {
            $cached = Cache::get($cacheKey);
            if ($cached instanceof AadeRegistryRecord) {
                return $cached;
            }
        } catch (Throwable) {
            // ignore — fall through to fresh fetch
        }

        $record = $this->callRegistry($afm);

        Cache::put($cacheKey, $record, self::CACHE_TTL_SECONDS);

        return $record;
    }

    /**
     * Verify both credentials are present AND decryptable. The Eloquent
     * encrypted cast throws DecryptException if APP_KEY rotated since
     * the credentials were stored — surface that as a friendly
     * AadeCredentialsInvalid rather than letting it escape as a 500.
     */
    private function assertCredentialsConfigured(): void
    {
        try {
            $username = $this->tenant->gsis_username;
            $password = $this->tenant->gsis_password;
        } catch (DecryptException $e) {
            throw new AadeCredentialsInvalid(
                'GSIS credentials cannot be decrypted (APP_KEY may have rotated). '
                . 'Re-enter them on the Company settings page.',
                0,
                $e,
            );
        }

        if (empty($username) || empty($password)) {
            throw new AadeCredentialsInvalid(
                'GSIS credentials are not configured for this tenant. '
                . 'Set gsis_username and gsis_password on the Company.'
            );
        }
    }

    /**
     * Known AADE/GSIS credential-failure messages. Used to discriminate
     * a credentials problem from a rate-limit / quota / transient
     * server fault. Match must be exact-substring on the SOAP fault
     * message — older broad-pattern matching on "AUTH" misclassified
     * codes like RG_WS_PUBLIC_AUTHORIZATION_QUOTA_EXCEEDED (rate limit,
     * NOT a credential problem) and would tell operators to "check
     * credentials" while the real fix is "wait or contact AADE".
     *
     * If AADE adds a new credential-failure code, extend this list —
     * better to be conservative (over-classify as Unreachable than
     * over-classify as CredentialsInvalid).
     */
    private const AUTH_FAILURE_TOKENS = [
        'RG_WS_PUBLIC_AUTHENTICATION_FAILED',
        'RG_WS_PUBLIC_WRONG_AFM',         // wrong issuer AFM in the request envelope
        'RG_WS_PUBLIC_USER_BLOCKED',       // credentials valid but user disabled
        'RG_WS_PUBLIC_INVALID_USER',
    ];

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
            $msg = $sf->getMessage();
            foreach (self::AUTH_FAILURE_TOKENS as $token) {
                if (str_contains($msg, $token)) {
                    throw new AadeCredentialsInvalid('GSIS rejected the credentials.', 0, $sf);
                }
            }

            Log::warning('AADE registry SOAP fault', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'faultcode' => $sf->faultcode ?? null,
                'message' => $msg,
            ]);

            throw new AadeUnreachable('AADE registry returned a SOAP fault.', 0, $sf);
        } catch (Throwable $e) {
            // Broad catch — TypeError, Error, network/DNS Exceptions etc.
            // that aren't SoapFault but are still "lookup couldn't
            // complete" from the operator POV. Anything credential-
            // related has already been thrown above; anything else
            // becomes Unreachable.
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

        // parse() can fail on shapes we didn't anticipate (gateway HTML
        // proxied through SOAP, AADE incident response, partial payload).
        // Translate any deref failure to Unreachable rather than letting
        // a TypeError escape the catch above.
        try {
            return $this->parse($data);
        } catch (Throwable $e) {
            Log::warning('AADE registry response parse failure', [
                'company_id' => $this->tenant->getKey(),
                'afm' => $afm,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw new AadeUnreachable('AADE registry response could not be parsed.', 0, $e);
        }
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

        // The legacy working example branches on deactivation_flag_descr
        // (the human Greek text "ΕΝΕΡΓΟΣ ΑΦΜ" / "ΑΝΕΝΕΡΓΟΣ ΑΦΜ") rather
        // than the deactivation_flag numeric code — the code's polarity
        // (1=active or 1=deactivated) is not documented unambiguously
        // and varies between drift-audit sources. Use the descr field
        // for the active check; if it's missing or unrecognised, treat
        // as active (defensive default — operators correct manually).
        $statusDescr = trim((string) ($basic->deactivation_flag_descr ?? ''));
        $active = $statusDescr === '' || $statusDescr === 'ΕΝΕΡΓΟΣ ΑΦΜ';

        return new AadeRegistryRecord(
            afm: (string) ($basic->afm ?? ''),
            name: (string) ($basic->onomasia ?? ''),
            doy: (string) ($basic->doy_descr ?? ''),
            doyCode: (string) ($basic->doy ?? ''),
            active: $active,
            statusDescr: $statusDescr,
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
        // Note: style/use/uri are intentionally absent. When a WSDL is
        // supplied, ext-soap ignores those options (WSDL dictates the
        // operation style and target namespace). Including them was a
        // copy-paste from the legacy non-WSDL example and would only
        // matter if someone later flipped to non-WSDL mode — at which
        // point a SOAP_1_2 envelope URI would be wrong for the request
        // target anyway. Keep this minimal.
        try {
            return new SoapClient(self::WSDL, [
                'encoding' => 'UTF-8',
                'exceptions' => true,
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
