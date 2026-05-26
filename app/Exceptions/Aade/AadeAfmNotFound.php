<?php

namespace App\Exceptions\Aade;

/**
 * GSIS accepted the credentials but returned no record for the queried
 * AFM, OR returned a deactivated AFM. From the operator's POV both mean
 * "this VAT number isn't a live business" — handled the same in the UI
 * (yellow warning notification). Distinguishing the two is logged but
 * not surfaced.
 */
class AadeAfmNotFound extends AadeRegistryException
{
}
