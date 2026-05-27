<?php

namespace App\Exceptions\Whmcs;

use RuntimeException;

/**
 * Base for all WHMCS API errors. Subclasses below distinguish auth /
 * unreachable / protocol-error / not-configured so the UI can surface
 * actionable messages and the artisan command can fail cleanly with
 * a specific exit code per case.
 *
 * Pattern mirrors AADE exceptions (App\Exceptions\Aade\*) so the
 * Filament forms and the scheduled pull command both get the same
 * "catch specific, fall through to base" shape.
 */
class WhmcsApiException extends RuntimeException
{
}
