<?php

declare(strict_types=1);

namespace Charon;

/**
 * Built-in strategies for deriving a client signature from a request.
 */
enum SignatureStrategy
{
    /**
     * Throttle per resolved client IP address.
     */
    case Ip;

    /**
     * Throttle per resolved client IP and User-Agent pair.
     */
    case IpAndUserAgent;
}
