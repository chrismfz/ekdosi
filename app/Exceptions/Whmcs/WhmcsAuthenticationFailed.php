<?php

namespace App\Exceptions\Whmcs;

/**
 * Thrown when WHMCS rejects our identifier+secret pair. Distinct
 * from WhmcsUnreachable (transport / DNS failure) and WhmcsApiException
 * (protocol-level error returned by WHMCS) so the Filament Test-
 * Connection button can show "Credentials rejected — check Setup →
 * Staff Mgmt → API Credentials in WHMCS" rather than the generic
 * "send failed".
 */
class WhmcsAuthenticationFailed extends WhmcsApiException
{
}
