<?php

namespace App\Exceptions\Aade;

use RuntimeException;

/**
 * Base for everything AadeRegistryLookup throws. Lets the Filament action
 * catch the broad type and switch on subclass for notification color.
 *
 * Subclasses don't add fields — they exist purely so call sites can do
 * try { $svc->findByAfm(...) } catch (AadeCredentialsInvalid) { ... }
 * catch (AadeAfmNotFound) { ... } catch (AadeUnreachable) { ... } and
 * render the right notification. Wrapping the original SoapFault details
 * stays inside this class (getPrevious()) so we can log full diagnostics
 * without leaking SOAP internals to the operator UI.
 */
abstract class AadeRegistryException extends RuntimeException
{
}
