<?php

namespace App\Exceptions\Aade;

/**
 * GSIS rejected the username / password. Operator sees a red toast
 * telling them to check the credentials on the Company settings page.
 */
class AadeCredentialsInvalid extends AadeRegistryException
{
}
