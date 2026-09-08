<?php

namespace App\Exceptions\Vies;

use RuntimeException;

/**
 * Base for VIES (EU VAT validation) lookup failures. Mirrors the
 * App\Exceptions\Aade hierarchy used by the GSIS lookup so the Filament
 * form-fill helper can discriminate failure cases the same way.
 */
class ViesException extends RuntimeException {}
