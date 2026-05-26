<?php

namespace App\Exceptions\Aade;

/**
 * SOAP fault, network timeout, DNS failure, GSIS endpoint down. Anything
 * that's NOT "creds rejected" and NOT "AFM not in registry" — i.e. the
 * lookup couldn't complete at all. Operator sees an orange/warning toast
 * advising them to try again or fill the customer manually.
 */
class AadeUnreachable extends AadeRegistryException
{
}
