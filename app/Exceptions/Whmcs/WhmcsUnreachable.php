<?php

namespace App\Exceptions\Whmcs;

/**
 * Thrown when the WHMCS install can't be reached (DNS failure, TCP
 * refused, TLS handshake failure, HTTP timeout). Network-layer
 * problems; the scheduled pull command treats this as transient
 * (worth retrying on the next tick).
 */
class WhmcsUnreachable extends WhmcsApiException
{
}
