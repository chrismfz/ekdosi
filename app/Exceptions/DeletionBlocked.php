<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A model-level guard refused a delete because other records still depend on the
 * row (e.g. Customer::forceDeleting). A RuntimeException so existing callers that
 * catch RuntimeException (the customer merge) keep working — but its own type, so
 * a bulk delete can skip exactly these without swallowing real DB errors.
 */
class DeletionBlocked extends RuntimeException {}
