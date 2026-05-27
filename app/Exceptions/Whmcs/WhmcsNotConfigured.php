<?php

namespace App\Exceptions\Whmcs;

/**
 * Thrown by the factory when asked to build a client for a tenant
 * that has no WHMCS credentials. Callers (artisan command, Filament
 * actions) catch this and present "WHMCS integration not configured
 * for this tenant — fill in Company → WHMCS tab first." Distinct
 * from auth/network failures (which mean the integration is
 * configured but broken).
 */
class WhmcsNotConfigured extends WhmcsApiException
{
}
