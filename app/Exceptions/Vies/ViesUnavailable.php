<?php

namespace App\Exceptions\Vies;

/**
 * VIES is unreachable or returned SERVICE_UNAVAILABLE / a member-state
 * service is down (MS_UNAVAILABLE / TIMEOUT). Transient — the operator should
 * retry shortly or fill manually. Distinct from "the number is invalid".
 */
class ViesUnavailable extends ViesException {}
