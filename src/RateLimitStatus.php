<?php

declare(strict_types=1);

namespace Charon;

/**
 * Outcome of a rate limit evaluation.
 */
enum RateLimitStatus
{
    /**
     * The request was counted and is within the limit.
     */
    case Allowed;

    /**
     * The request exceeded the configured limit.
     */
    case Limited;

    /**
     * The client is blacklisted due to repeated violations.
     */
    case Blacklisted;
}
