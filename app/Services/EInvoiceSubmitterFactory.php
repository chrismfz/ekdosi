<?php

namespace App\Services;

use App\Contracts\EInvoiceSubmitter;
use App\Enums\MyDataMode;
use App\Models\Company;

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

        // Everything else routes to the no-op submitter:
        //   - mydata_mode = Off (Greek tenant deliberately not filing)
        //   - einvoice_provider = 'none' (PDF-only tenant)
        //   - einvoice_provider = 'ee-peppol' (Estonian — PeppolSubmitter
        //     lands later; NullSubmitter is the safe default until then)
        return new NullSubmitter();
    }
}
