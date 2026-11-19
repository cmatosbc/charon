<?php

declare(strict_types=1);

namespace Charon;

/**
 * A rate limiter that can blacklist clients after repeated violations.
 */
interface BlacklistAwareRateLimiter extends RateLimiterInterface
{
    /**
     * Configure automatic blacklisting after recurring rate limit violations.
     *
     * @param int $recurringEvents Number of violations required before blacklisting
     * @param int|null $ttl Blacklist lifetime in seconds, or null to blacklist indefinitely
     */
    public function maybeBlacklist(int $recurringEvents, ?int $ttl = null): self;
}
