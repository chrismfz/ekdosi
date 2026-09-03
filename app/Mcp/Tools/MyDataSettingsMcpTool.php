<?php

namespace App\Mcp\Tools;

use App\Enums\MyDataMode;
use App\Mcp\Tools\Concerns\ForensicMcpTool;
use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * The myDATA / e-invoice CHANNEL configuration for a tenant — «τι βλέπει και τι
 * στέλνει, και σε ποιο περιβάλλον». Answers the exact confusion behind the
 * «πάροχος δοκιμαστικός → η κονσόλα φέρνει μόνο 6-7 sandbox παραστατικά»: it
 * shows the submit channel/mode AND the RESOLVED read environment
 * (Company::mydataReadMode) side by side, so it is obvious when reads are hitting
 * the AADE sandbox instead of production.
 *
 * Secret-safe by construction: it reports the aade-user-id (the ΑΦΜ-derived
 * header id, already shown in the panel) and boolean PRESENCE of each credential
 * slot — it NEVER emits a subscription key or the provider token, and it reads
 * the raw stored columns so it never decrypts (no APP_KEY-rotation crash, no
 * secret egress). Read-only, super_admin, no AADE call.
 */
#[Name('mydata_settings')]
#[Description('myDATA / e-invoice channel configuration for a tenant, read-only, no AADE call. Shows the submit channel (gr-mydata / gr-provider / …) + submit mode, and the RESOLVED read environment (sandbox vs production) with its AADE endpoint — the fast answer to "why does the console only show sandbox/test documents?". Reports which credential slots are populated (booleans) but NEVER the subscription keys or provider token. Optional `company` (slug); omit / "all" for every tenant you can reach. Read-only, super-admin.')]
#[IsReadOnly]
#[IsIdempotent]
class MyDataSettingsMcpTool extends ForensicMcpTool
{
    /** Well-known AADE REST hosts per environment (firebed prod/dev), for the read endpoint hint. */
    private const AADE_ENDPOINT = [
        'production' => 'https://mydatapi.aade.gr',
        'sandbox' => 'https://mydataapidev.aade.gr',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'company' => $schema->string()
                ->description('Optional company slug. Omit or "all" for every tenant you can reach.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $company = $request->get('company');
        $scope = $this->scope($request, is_string($company) ? $company : null);
        if ($scope['error'] !== null) {
            return self::json(['error' => $scope['error']]);
        }

        return self::json([
            'companies' => array_map(static fn (Company $c): string => (string) $c->slug, $scope['companies']),
            'tenants' => array_map(fn (Company $c): array => $this->describe($c), $scope['companies']),
            'note' => 'resolved_read_environment = ΤΟ myDATA που διαβάζει η κονσόλα/συμφωνία/έξοδα (sandbox = test, μόνο synthetic παραστατικά). Ορίζεται από mydata_read_env_override· «auto» → ακολουθεί το submit mode. Οι subscription keys ΔΕΝ εμφανίζονται — μόνο αν υπάρχουν.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Company $c): array
    {
        $readMode = $c->mydataReadMode();
        $env = $readMode?->value;

        return [
            'company' => (string) $c->slug,
            'name' => (string) $c->name,
            'afm' => (string) $c->afm,

            // Submit side.
            'einvoice_provider' => $c->einvoice_provider,
            'einvoice_provider_key' => $c->einvoice_provider_key,
            'einvoice_provider_mode' => $c->einvoice_provider_mode,
            'mydata_mode' => $c->mydata_mode_enum->value,

            // Read side — the answer to «ποιο myDATA βλέπει».
            'mydata_read_env_override' => $c->mydata_read_env ?: 'auto',
            'resolved_read_environment' => $env,
            'resolved_read_endpoint' => $env !== null ? (self::AADE_ENDPOINT[$env] ?? null) : null,
            'can_read_mydata' => $c->canReadMyData(),

            // Credential PRESENCE only (raw columns → no decrypt, no secret egress).
            'credentials_present' => [
                'sandbox_aade_id' => filled($c->getRawOriginal('mydata_aade_id_sandbox')),
                'sandbox_subscription_key' => filled($c->getRawOriginal('mydata_subscription_key_sandbox')),
                'production_aade_id' => filled($c->getRawOriginal('mydata_aade_id_production')),
                'production_subscription_key' => filled($c->getRawOriginal('mydata_subscription_key_production')),
            ],
            // The aade-user-id header value (ΑΦΜ-derived, non-secret) per slot, for
            // "am I reading with the right subscription?". Keys are never shown.
            'aade_user_id' => [
                'sandbox' => $c->getRawOriginal('mydata_aade_id_sandbox') ?: null,
                'production' => $c->getRawOriginal('mydata_aade_id_production') ?: null,
            ],

            'submits_electronically' => $c->submitsElectronically(),
            'is_live_mydata_tenant' => $c->isLiveMyDataTenant(),
            'is_live_provider_tenant' => $c->isLiveProviderTenant(),

            'diagnosis' => $this->diagnose($c, $readMode),
        ];
    }

    /** One human sentence on the read situation — the takeaway without reading the fields. */
    private function diagnose(Company $c, ?MyDataMode $readMode): string
    {
        if ($readMode === null) {
            if (! $c->filesToAadeByProvider()) {
                return 'Μη-ελληνικό κανάλι — δεν διαβάζει από myDATA.';
            }

            // For gr-mydata the auto read mode IS the submission mode, so null can
            // ONLY mean Off (missing creds don't blank it — they surface later at
            // fetch). Say that, not «λείπουν creds», or the operator is sent to add
            // credentials that already exist.
            if ($c->einvoice_provider === 'gr-mydata') {
                return 'Δεν διαβάζει από myDATA: η λειτουργία είναι κλειστή (Off). '
                    .'Άνοιξέ την (Τρόπος αποστολής) ή όρισε ρητό «Περιβάλλον ανάγνωσης myDATA» με τα διαπιστευτήριά του.';
            }

            // A provider reads via its own subscription; null here = no read creds.
            return 'Δεν διαβάζει από myDATA: λείπουν διαπιστευτήρια ανάγνωσης (aade-user-id) στο επιλεγμένο περιβάλλον.';
        }

        // Distinguish an override that WON from one that was set but discarded (its
        // slot was empty, so mydataReadMode fell through to the auto rule) — else we
        // would credit a bogus «(ρητό override)» for a sandbox read the auto rule
        // actually produced, mis-explaining the very «γιατί sandbox;» question. The
        // honour condition is the model's own (shared predicate) so the two never drift.
        $override = $c->honouredMydataReadEnvOverride() !== null
            ? ' (ρητό override)'
            : ' (auto — ακολουθεί τον «Τρόπο αποστολής»)';

        if ($readMode === MyDataMode::Sandbox) {
            return 'Διαβάζει το SANDBOX myDATA'.$override.' — δηλαδή το test περιβάλλον, μόνο synthetic παραστατικά. '
                .'Για τα πραγματικά όρισε «Περιβάλλον ανάγνωσης myDATA = Παραγωγή» (χρειάζεται production aade-user-id + key).';
        }

        return 'Διαβάζει το PRODUCTION myDATA'.$override.' — τα πραγματικά παραστατικά του ΑΦΜ.';
    }
}
