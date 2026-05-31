<?php

namespace App\Exceptions\Vies;

/**
 * The input couldn't be parsed into a {countryCode, vatNumber} pair — e.g.
 * a bare number with no country, or a non-EU country code. Thrown BEFORE any
 * network call.
 */
class ViesInvalidFormat extends ViesException {}
