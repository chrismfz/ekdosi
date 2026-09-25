<?php

namespace App\Services\Ergani;

use RuntimeException;

/** An overtime refused BY RULE (already started, overlap, no ΑΦΜ …) — nothing was stored or sent. */
class OvertimeRefused extends RuntimeException {}
