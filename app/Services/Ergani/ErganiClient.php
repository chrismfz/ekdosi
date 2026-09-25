<?php

namespace App\Services\Ergani;

use App\Models\Company;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin ΕΡΓΑΝΗ ΙΙ Web API client (official guide: docs/ergani/): the connection
 * test (EX_BASE_01, incl. IsInCardSector) and document submission / cancellation
 * (WTOLeave …). The access token is CACHED per company + environment — the guide
 * (Παρ. ΙΙ) forbids a fresh login per call and rate-limits with 429.
 *
 * The same e-ΕΦΚΑ credentials work on both environments — `ergani_mode` alone
 * decides whether a call hits the real system.
 */
class ErganiClient
{
    public const TRIAL_URL = 'https://trialv2eservices.yeka.gr/WebServicesAPI/api';

    /** Production endpoint — from the SDKs, not the guide; confirm on first real use. */
    public const PRODUCTION_URL = 'https://eservices.yeka.gr/WebServicesAPI/api';

    /** e-ΕΦΚΑ credentials authenticate as Usertype 01 (the guide's example «02» = ΕΡΓΑΝΗ creds → 401). */
    private const USERTYPE = '01';

    public function __construct(private readonly Company $company) {}

    public function baseUrl(): string
    {
        return $this->company->ergani_mode === 'production' ? self::PRODUCTION_URL : self::TRIAL_URL;
    }

    /**
     * Log in and read the employer record.
     *
     * @return array{afm: ?string, name: ?string, in_card_sector: bool, environment: string}
     *
     * @throws RuntimeException with a Greek operator-facing message
     */
    public function employerInfo(): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post($this->baseUrl().'/WebServices/ExecuteService', [
            'ServiceCode' => 'EX_BASE_01',
            'Parameters' => [],
        ]));

        if (! $response->successful()) {
            throw new RuntimeException('Το ΕΡΓΑΝΗ απάντησε '.$response->status().': '.$this->message($response->json(), $response->body()));
        }

        $employer = (array) data_get($response->json(), 'EX_BASE_01.Ergodotis', []);

        return [
            'afm' => $employer['Afm'] ?? null,
            'name' => $employer['Eponimia'] ?? null,
            'in_card_sector' => ($employer['IsInCardSector'] ?? '0') === '1',
            'environment' => $this->company->ergani_mode === 'production' ? 'Παραγωγή' : 'Δοκιμαστικό',
        ];
    }

    /**
     * Submit a document (e.g. WTOLeave). Returns ΕΡΓΑΝΗ's receipt and the raw
     * exchange for the audit row; a 400 carries ΕΡΓΑΝΗ's own message.
     *
     * @return array{ok: bool, status: int, protocol: ?string, submitted_at: ?string, id: ?string, message: ?string, response: string}
     */
    public function submit(string $documentCode, array $payload): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post($this->baseUrl().'/Documents/'.rawurlencode($documentCode), $payload));
        $json = $response->json();
        $first = is_array($json) && isset($json[0]) && is_array($json[0]) ? $json[0] : [];

        return [
            'ok' => $response->successful() && filled($first['protocol'] ?? null),
            'status' => $response->status(),
            'protocol' => $first['protocol'] ?? null,
            'submitted_at' => $first['submitDate'] ?? null,
            'id' => isset($first['id']) ? (string) $first['id'] : null,
            'message' => $response->successful() ? null : $this->message($response->json(), $response->body()),
            'response' => $response->body(),
        ];
    }

    /**
     * Withdraw a submitted document (the guide allows it only for the Άδειες
     * documents). $submittedDate = yyyymmdd.
     *
     * @return array{ok: bool, status: int, message: ?string, response: string}
     */
    public function cancel(string $documentCode, string $protocol, string $submittedDate): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http->post($this->baseUrl().'/Documents/CancelSubmittedDocument', [
            'TypeOfDocument' => $documentCode,
            'Protocol' => $protocol,
            'SubmittedDate' => $submittedDate,
        ]));

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'message' => $this->message($response->json(), $response->body()),
            'response' => $response->body(),
        ];
    }

    /**
     * The official PDF of a submitted document (base64 in `document`), or null.
     * Verified on trial: `GET Documents/{code}?protocol=…&submittedDate=yyyymmdd`
     * → `{"message":null,"document":"<base64>"}`; cancelled/superseded → 400.
     */
    public function pdf(string $documentCode, string $protocol, string $submittedDate): ?string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get($this->baseUrl().'/Documents/'.rawurlencode($documentCode), [
            'protocol' => $protocol,
            'submittedDate' => $submittedDate,
        ]));
        $b64 = $response->successful() ? $response->json('document') : null;
        $pdf = is_string($b64) ? base64_decode($b64, true) : false;

        return $pdf === false ? null : $pdf;
    }

    /** Log in now (or reuse the cached token) — lets a caller separate «login failed» from «submit failed». */
    public function ensureToken(): void
    {
        $this->token();
    }

    /** Authenticated call; one transparent re-login when the cached token was rejected. */
    private function call(callable $send): Response
    {
        $response = $send($this->http($this->token()));
        if ($response->status() === 401) {
            Cache::forget($this->tokenKey());
            $response = $send($this->http($this->token()));
        }

        return $response;
    }

    private function http(string $token): PendingRequest
    {
        return Http::connectTimeout(10)->timeout(20)->withToken($token)->acceptJson();
    }

    private function tokenKey(): string
    {
        // Keyed on the credentials too: a changed (or mistyped, in «Test σύνδεσης») login
        // must never ride on a token cached from the previous one.
        // HMAC keyed with APP_KEY — never a bare hash of the password in the cache store.
        return 'ergani.token.'.$this->company->getKey().'.'.$this->company->ergani_mode.'.'
            .hash_hmac('sha256', $this->company->ergani_username."\0".$this->company->ergani_password, (string) config('app.key'));
    }

    /** The guide's access token lives 3h — reuse it for 2h50m. */
    private function token(): string
    {
        return Cache::remember($this->tokenKey(), now()->addMinutes(170), fn (): string => $this->authenticate());
    }

    private function authenticate(): string
    {
        $username = trim((string) $this->company->ergani_username);
        $password = (string) $this->company->ergani_password;
        if ($username === '' || $password === '') {
            throw new RuntimeException('Συμπληρώστε και αποθηκεύστε πρώτα username/password e-ΕΦΚΑ.');
        }

        $response = Http::connectTimeout(10)->timeout(20)
            ->acceptJson()
            ->post($this->baseUrl().'/Authentication', [
                'Username' => $username,
                'Password' => $password,
                'Usertype' => self::USERTYPE,
            ]);

        if ($response->status() === 401) {
            throw new RuntimeException('Λάθος κωδικοί e-ΕΦΚΑ (ή δεν έχουν πρόσβαση στο ΕΡΓΑΝΗ).');
        }
        $token = $response->json('accessToken');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException('Αποτυχία σύνδεσης στο ΕΡΓΑΝΗ ('.$response->status().'): '.$this->message($response->json(), $response->body()));
        }

        return $token;
    }

    private function message(mixed $json, string $body): string
    {
        $msg = is_array($json) ? ($json['message'] ?? null) : (is_string($json) ? $json : null);
        // ΕΡΓΑΝΗ embeds a literal «\n» (backslash-n) between messages.
        $msg = trim(str_replace(['\\n', '\n', "\n"], ' · ', (string) ($msg ?? $body)), ' ·');

        return mb_substr($msg, 0, 500);
    }
}
