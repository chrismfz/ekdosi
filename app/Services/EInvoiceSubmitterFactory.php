<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Models\Company;
use App\Services\EInvoice\GrProviderSubmitter;
use App\Services\EInvoice\ProviderTransportRegistry;

/**
 * Resolves the right EInvoiceSubmitter implementation for a tenant.
 *
 * Routing logic:
 *
 *   einvoice_provider = 'gr-mydata':
 *     mydata_mode = Off         → NullSubmitter
 *     mydata_mode = Sandbox     → MyDataSubmitter (sandbox endpoint)
 *     mydata_mode = Production  → MyDataSubmitter (production endpoint —
 *                                  decided inside MyDataSubmitter via
 *                                  tenant->mydata_mode_enum)
 *
 *   einvoice_provider = 'ee-peppol':
 *     → NullSubmitter (PeppolSubmitter lands when Estonian tenant
 *       actually starts submitting; until then PDFs are still
 *       generated locally)
 *
 *   einvoice_provider = 'none':
 *     → NullSubmitter (PDF-only tenant)
 *
 * Usage:
 *
 *     $submitter = app(EInvoiceSubmitterFactory::class)->for($company);
 *     $mark = $submitter->submit($invoice);
 */
class EInvoiceSubmitterFactory
{
    public function for(Company $tenant): EInvoiceSubmitter
    {
        if (
            $tenant->einvoice_provider === 'gr-mydata'
            && $tenant->mydata_mode_enum !== MyDataMode::Off
        ) {
            return new MyDataSubmitter($tenant);
        }

        // ΥΠΑΗΕΣ provider path (P2): file through a certified provider. The
        // transport is resolved from the registry by the tenant's provider key —
        // an unconfigured/unknown key yields the Null transport, so a misconfig
        // fails LOUDLY at submit-time, never silently nor against the wrong path.
        // Gated by einvoice_provider_mode != 'off' so a provider tenant can be
        // STAGED (off) without filing — the twin of gr-mydata's Off gate.
        if (
            $tenant->einvoice_provider === 'gr-provider'
            && ($tenant->einvoice_provider_mode ?? 'off') !== 'off'
        ) {
            $transport = app(ProviderTransportRegistry::class)->for((string) $tenant->einvoice_provider_key);

            return new GrProviderSubmitter($tenant, $transport);
        }

        // Everything else routes to the no-op submitter:
        //   - mydata_mode = Off (Greek tenant deliberately not filing)
        //   - einvoice_provider = 'none' (PDF-only tenant)
        //   - einvoice_provider = 'ee-peppol' (Estonian — PeppolSubmitter
        //     lands later; NullSubmitter is the safe default until then)
        //   - einvoice_provider = 'gr-provider' WITH mode 'off' (staged, not filing)
        return new NullSubmitter;
    }
}
