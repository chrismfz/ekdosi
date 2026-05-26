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
 *     mydata_mode = Sandbox     → MyDataSubmitter (sandbox endpoint) [PR #25]
 *     mydata_mode = Production  → MyDataSubmitter (production endpoint) [PR #25]
 *
 *   einvoice_provider = 'ee-peppol':
 *     → NullSubmitter (PeppolSubmitter lands when Estonian tenant
 *       actually starts submitting; until then PDFs are still
 *       generated locally)
 *
 *   einvoice_provider = 'none':
 *     → NullSubmitter (PDF-only tenant)
 *
 * In this PR (#24), the factory always returns NullSubmitter because
 * MyDataSubmitter doesn't exist yet — but the contract is in place so
 * call sites can already type-hint against EInvoiceSubmitter. PR #25
 * extends this factory with the live submitter branch.
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
        // PR #25 will add:
        // if ($tenant->einvoice_provider === 'gr-mydata'
        //     && $tenant->mydata_mode_enum !== MyDataMode::Off) {
        //     return new MyDataSubmitter($tenant);
        // }
        unset($tenant); // PR #24 doesn't branch on tenant yet
        return new NullSubmitter();
    }
}
