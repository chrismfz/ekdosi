<?php

namespace App\Services\Ergani;

use RuntimeException;

/**
 * A punch refused BY RULE (double punch, out-of-sequence, kiosk required …) —
 * nothing was recorded or sent. Any other exception from WorkCardService is an
 * unexpected failure whose outcome must be checked, not shown as a refusal.
 */
class WorkCardRefused extends RuntimeException {}
