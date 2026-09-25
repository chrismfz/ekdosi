<?php

namespace App\Services\Ergani;

use App\Models\Company;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin ΕΡΓΑΝΗ ΙΙ Web API client (official guide: docs/ergani/). Phase 1 only
 * needs the connection test: authenticate + EX_BASE_01 («Στοιχεία εργοδότη»,
 * incl. IsInCardSector). Leave/work-card submission (WTOLeave / WRKCardSE) is
 * Phase 2 and will add the token cache the guide requires (429 otherwise).
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
        $token = $this->authenticate();

        $response = Http::timeout(30)
            ->withToken($token)
            ->acceptJson()
            ->post($this->baseUrl().'/WebServices/ExecuteService', [
                'ServiceCode' => 'EX_BASE_01',
                'Parameters' => [],
            ]);

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

    private function authenticate(): string
    {
        $username = trim((string) $this->company->ergani_username);
        $password = (string) $this->company->ergani_password;
        if ($username === '' || $password === '') {
            throw new RuntimeException('Συμπληρώστε και αποθηκεύστε πρώτα username/password e-ΕΦΚΑ.');
        }

        $response = Http::timeout(30)
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

        return mb_substr((string) ($msg ?? $body), 0, 300);
    }
}
